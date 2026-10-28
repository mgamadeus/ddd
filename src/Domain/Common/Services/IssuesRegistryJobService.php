<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Common\Entities\Issues\Issue;
use DDD\Domain\Common\Entities\Issues\IssueGroup;
use DDD\Domain\Common\Entities\Issues\IssuesJobState;
use DDD\Domain\Common\Entities\Issues\IssuesRegistryHealth;
use DDD\Domain\Common\Entities\Issues\IssuesRegistryJobResult;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Logging\IssuesAlertWebhookSender;
use DDD\Infrastructure\Logging\IssuesRegistrySettings;
use DDD\Infrastructure\Logging\Query\IssueEvent;
use DDD\Infrastructure\Logging\Query\IssueEventSearch;
use DDD\Infrastructure\Logging\Query\IssueHashCounts;
use DDD\Infrastructure\Logging\Query\IssuesQueryBackend;
use DDD\Infrastructure\Logging\Query\IssuesQueryBackendFactory;
use DDD\Infrastructure\Services\DDDService;
use Throwable;

/**
 * The issues registry job — one instance (a lock), never on the request path. Each run:
 *
 * 1. **Discovery** over a wide trailing window (the collector's maximum buffer age, ISSUES_DISCOVERY_WINDOW_MINUTES):
 *    the backend aggregates the error events per hash; every unknown group hash becomes a group (one sample event
 *    fetched for it), every unknown issue hash an issue. Idempotent through the unique hashes — late lines a collector
 *    replays under their original timestamp are still found. A known RESOLVED group with an event after its
 *    `resolvedAt` is reopened (regression) — a replay of older lines never reopens it.
 * 2. **Counting** over [watermark, now − 1 min]: event counts and last seen (approximate under replays).
 * 3. **Alerts**: one Slack message per new root-cause group or regression, after the database write; a failed send stays
 *    pending and is retried next run. Not in **seed mode** (ISSUES_SEED_HOURS after the first run, or on demand), not
 *    for IGNORED groups, and above ISSUES_GROUP_EXPLOSION_LIMIT new groups per hour one "explosion" message instead.
 * 4. Its own state: watermark, last successful run, newest event seen, last error ({@see self::getHealth()}).
 *
 * Without a query backend it does nothing: the application only logs.
 */
class IssuesRegistryJobService
{
    public const string LOCK_NAME = 'ddd_issues_registry_job';

    /** Events newer than this are left to the next run (the collector may not have shipped all of them yet) */
    public const int SETTLE_SECONDS = 60;

    /** Groups per run whose newest event is fetched for last seen / release */
    public const int MAX_REFRESHED_GROUPS_PER_RUN = 100;

    protected ?IssuesRegistrySettings $settings = null;

    protected ?IssuesQueryBackend $queryBackend = null;

    protected ?IssuesAlertWebhookSender $alertWebhookSender = null;

    public function getSettings(): IssuesRegistrySettings
    {
        return $this->settings ??= IssuesRegistrySettings::fromEnvironment();
    }

    /** Replaces the settings (tests, CLI options); resets the backend and the sender built from them */
    public function setSettings(?IssuesRegistrySettings $settings): static
    {
        $this->settings = $settings;
        $this->queryBackend = null;
        $this->alertWebhookSender = null;
        return $this;
    }

    public function getQueryBackend(): IssuesQueryBackend
    {
        return $this->queryBackend ??= IssuesQueryBackendFactory::create($this->getSettings());
    }

    public function setQueryBackend(?IssuesQueryBackend $queryBackend): static
    {
        $this->queryBackend = $queryBackend;
        return $this;
    }

    public function getAlertWebhookSender(): IssuesAlertWebhookSender
    {
        return $this->alertWebhookSender ??= new IssuesAlertWebhookSender($this->getSettings());
    }

    public function setAlertWebhookSender(?IssuesAlertWebhookSender $alertWebhookSender): static
    {
        $this->alertWebhookSender = $alertWebhookSender;
        return $this;
    }

    /**
     * One run (see the class description). Takes the job lock; a concurrent run is skipped.
     *
     * @param DateTime|null $now The run's "now" (tests)
     * @param int|null $seedHours Start seed mode for this many hours from now (on demand)
     * @throws Throwable A backend or database failure — recorded as the state's lastError, the next run catches up
     */
    public function run(?DateTime $now = null, ?int $seedHours = null): IssuesRegistryJobResult
    {
        $result = new IssuesRegistryJobResult();
        if (!$this->getSettings()->hasQueryBackend()) {
            $result->skippedReason = 'no query backend (ISSUES_QUERY_BACKEND)';
            return $result;
        }
        $lockService = EntityManagerFactory::getDialect()->locks(EntityManagerFactory::getInstance()->getConnection());
        if (!$lockService->acquire(self::LOCK_NAME, 0)) {
            $result->skippedReason = 'another run holds the lock';
            return $result;
        }
        DDDService::instance()->deactivateEntityRightsRestrictions();
        $issuesJobState = null;
        try {
            /** @var IssuesJobStatesService $issuesJobStatesService */
            $issuesJobStatesService = IssuesJobState::getService();
            $issuesJobState = $issuesJobStatesService->getState();
            $now ??= DateTime::fromTimestamp(time());
            $issuesJobState->lastRunStartedAt = $now;
            $issuesJobState->activatedAt ??= $now;
            if ($seedHours !== null) {
                $issuesJobState->seedUntil = DateTime::fromTimestamp($now->getTimestamp() + $seedHours * 3600);
            }
            $result->seedMode = $this->isSeedMode($issuesJobState, $now);

            $settledUntil = DateTime::fromTimestamp($now->getTimestamp() - self::SETTLE_SECONDS);
            $discoveryFrom = DateTime::fromTimestamp($now->getTimestamp() - $this->getSettings()->discoveryWindowMinutes * 60);
            $this->discover($discoveryFrom, $settledUntil, $now, $result);
            $this->guardExplosion($issuesJobState, $now, $result);
            $countFrom = $issuesJobState->watermark ?? $discoveryFrom;
            if ($countFrom->getTimestamp() < $settledUntil->getTimestamp()) {
                $this->count($countFrom, $settledUntil, $result);
                $issuesJobState->watermark = $settledUntil;
            }
            $newestEvent = $this->getQueryBackend()->searchEvents(new IssueEventSearch($discoveryFrom, $now, limit: 1))->first();
            if ($newestEvent?->timestamp !== null) {
                $issuesJobState->newestEventSeenAt = $newestEvent->timestamp;
            }
            $this->sendPendingAlerts($now, $result);
            $issuesJobState->lastSuccessfulRunAt = $now;
            $issuesJobState->lastError = null;
            $issuesJobState->update();
            return $result;
        } catch (Throwable $throwable) {
            if ($issuesJobState === null) {
                throw $throwable;
            }
            $issuesJobState->lastError = mb_substr($throwable->getMessage(), 0, 2000);
            try {
                $issuesJobState->update();
            } catch (Throwable) {
                // the database itself may be the failure; the next run catches up
            }
            throw $throwable;
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
            $lockService->release(self::LOCK_NAME);
        }
    }

    public function isSeedMode(IssuesJobState $issuesJobState, DateTime $now): bool
    {
        $seedEnd = $issuesJobState->activatedAt !== null
            ? $issuesJobState->activatedAt->getTimestamp() + $this->getSettings()->seedHours * 3600
            : PHP_INT_MAX;
        if ($issuesJobState->seedUntil !== null) {
            $seedEnd = max($seedEnd, $issuesJobState->seedUntil->getTimestamp());
        }
        return $now->getTimestamp() < $seedEnd;
    }

    /** Pass 1: new groups and issues from the wide window, regressions of resolved groups */
    protected function discover(DateTime $from, DateTime $until, DateTime $now, IssuesRegistryJobResult $result): void
    {
        /** @var IssueGroupsService $issueGroupsService */
        $issueGroupsService = IssueGroup::getService();
        /** @var IssuesService $issuesService */
        $issuesService = Issue::getService();
        $issueHashCounts = $this->getQueryBackend()->aggregateErrors($from, $until);
        foreach ($issueHashCounts->getGroupHashes() as $groupHash) {
            $issueGroup = $issueGroupsService->findByGroupHash($groupHash);
            if ($issueGroup === null) {
                $issueGroup = $this->createGroup($groupHash, $from, $until, $now, $result->seedMode);
                $result->groupsCreated++;
            } elseif ($issueGroup->status === IssueGroup::STATUS_RESOLVED && $this->hasEventAfterResolution($issueGroup, $from, $until)) {
                $issueGroup->status = IssueGroup::STATUS_OPEN;
                $issueGroup->isRegression = true;
                $issueGroup->alertPending = !$result->seedMode;
                $issueGroup->resolvedAt = null;
                $result->regressions++;
            }
            foreach ($issueHashCounts->getElements() as $issueHashCount) {
                if ($issueHashCount->groupHash !== $groupHash) {
                    continue;
                }
                $issueGroup->addEntryPoint($issueHashCount->entryPoint);
                if ($issueHashCount->issueHash !== '' && $issuesService->findByIssueHash($issueHashCount->issueHash) === null) {
                    $issueGroup = $issueGroup->update();
                    $issue = new Issue();
                    $issue->issueHash = $issueHashCount->issueHash;
                    $issue->issueGroupId = $issueGroup->id;
                    $issue->entryPoint = $issueHashCount->entryPoint !== '' ? $issueHashCount->entryPoint : null;
                    $issue->firstSeenAt = $issueGroup->firstSeenAt;
                    $issue->insert(0);
                    $result->issuesCreated++;
                }
            }
            $issueGroup->update();
        }
    }

    protected function createGroup(string $groupHash, DateTime $from, DateTime $until, DateTime $now, bool $seedMode): IssueGroup
    {
        $sampleEvent = $this->getQueryBackend()->searchEvents(
            new IssueEventSearch($from, $until, groupHash: $groupHash, limit: 1, oldestFirst: true)
        )->first();
        $issueGroup = new IssueGroup();
        $issueGroup->groupHash = $groupHash;
        $issueGroup->registeredAt = $now;
        $issueGroup->isNew = !$seedMode;
        $issueGroup->alertPending = !$seedMode;
        $this->applySampleEvent($issueGroup, $sampleEvent, true);
        $issueGroup->firstSeenAt ??= $now;
        $issueGroup->lastSeenAt ??= $issueGroup->firstSeenAt;
        $issueGroup->insert(0);
        return $issueGroup;
    }

    protected function applySampleEvent(IssueGroup $issueGroup, ?IssueEvent $sampleEvent, bool $isFirstSample): void
    {
        if ($sampleEvent === null) {
            return;
        }
        if ($isFirstSample) {
            $issueGroup->exceptionType = $sampleEvent->exceptionType;
            $issueGroup->sampleMessage = $sampleEvent->message;
            $issueGroup->slug = $sampleEvent->slug;
            $issueGroup->logOrigin = $sampleEvent->logOrigin;
            $issueGroup->section = $sampleEvent->section;
            $issueGroup->environment = $sampleEvent->environment;
            $issueGroup->sampleEventId = $sampleEvent->eventId;
            $issueGroup->releaseFirstSeen = $sampleEvent->release;
            $issueGroup->firstSeenAt = $sampleEvent->timestamp;
        }
        $issueGroup->releaseLastSeen = $sampleEvent->release ?? $issueGroup->releaseLastSeen;
        if ($sampleEvent->timestamp !== null
            && ($issueGroup->lastSeenAt === null || $sampleEvent->timestamp->getTimestamp() > $issueGroup->lastSeenAt->getTimestamp())) {
            $issueGroup->lastSeenAt = $sampleEvent->timestamp;
        }
    }

    /** Whether the backend has an event of the group after its resolution (only those reopen it) */
    protected function hasEventAfterResolution(IssueGroup $issueGroup, DateTime $from, DateTime $until): bool
    {
        if ($issueGroup->resolvedAt === null) {
            return false;
        }
        $searchFrom = DateTime::fromTimestamp(max($from->getTimestamp(), $issueGroup->resolvedAt->getTimestamp() + 1));
        if ($searchFrom->getTimestamp() >= $until->getTimestamp()) {
            return false;
        }
        return $this->getQueryBackend()->searchEvents(
            new IssueEventSearch($searchFrom, $until, groupHash: $issueGroup->groupHash, limit: 1, oldestFirst: true)
        )->count() > 0;
    }

    /** Above the limit of new groups per hour: they stop alerting one by one, one explosion message instead */
    protected function guardExplosion(IssuesJobState $issuesJobState, DateTime $now, IssuesRegistryJobResult $result): void
    {
        if ($result->groupsCreated === 0 || $result->seedMode) {
            return;
        }
        $issueGroupsService = IssueGroup::getService();
        $oneHourAgo = DateTime::fromTimestamp($now->getTimestamp() - 3600);
        $newGroupCount = $issueGroupsService->countNewGroupsRegisteredSince($oneHourAgo);
        if ($newGroupCount <= $this->getSettings()->groupExplosionLimit) {
            return;
        }
        $result->explosion = true;
        foreach ($issueGroupsService->findGroupsWithPendingAlert()->getElements() as $issueGroup) {
            if ($issueGroup->isNew && $issueGroup->registeredAt !== null
                && $issueGroup->registeredAt->getTimestamp() >= $oneHourAgo->getTimestamp()) {
                $issueGroup->alertPending = false;
                $issueGroup->update();
            }
        }
        $lastExplosionAlert = $issuesJobState->explosionAlertSentAt?->getTimestamp() ?? 0;
        if ($lastExplosionAlert < $oneHourAgo->getTimestamp() && $this->getAlertWebhookSender()->sendExplosionAlert($newGroupCount)) {
            $issuesJobState->explosionAlertSentAt = $now;
            $result->alertsSent++;
        }
    }

    /** Pass 2: counts and last seen over [from, until] */
    protected function count(DateTime $from, DateTime $until, IssuesRegistryJobResult $result): void
    {
        $issueGroupsService = IssueGroup::getService();
        $issuesService = Issue::getService();
        $issueHashCounts = $this->getQueryBackend()->aggregateErrors($from, $until);
        $refreshedGroups = 0;
        foreach ($issueHashCounts->getGroupHashes() as $groupHash) {
            $issueGroup = $issueGroupsService->findByGroupHash($groupHash);
            if ($issueGroup === null) {
                continue;
            }
            $groupCount = $issueHashCounts->getCountForGroupHash($groupHash);
            $issueGroup->eventCount += $groupCount;
            $result->eventsCounted += $groupCount;
            if ($refreshedGroups < self::MAX_REFRESHED_GROUPS_PER_RUN) {
                $newestEvent = $this->getQueryBackend()->searchEvents(
                    new IssueEventSearch($from, $until, groupHash: $groupHash, limit: 1)
                )->first();
                $this->applySampleEvent($issueGroup, $newestEvent, false);
                $refreshedGroups++;
            }
            $issueGroup->update();
            $this->countIssues($issueHashCounts, $groupHash, $issuesService, $issueGroup);
        }
    }

    protected function countIssues(IssueHashCounts $issueHashCounts, string $groupHash, IssuesService $issuesService, IssueGroup $issueGroup): void
    {
        foreach ($issueHashCounts->getElements() as $issueHashCount) {
            if ($issueHashCount->groupHash !== $groupHash || $issueHashCount->issueHash === '') {
                continue;
            }
            $issue = $issuesService->findByIssueHash($issueHashCount->issueHash);
            if ($issue === null) {
                continue;
            }
            $issue->eventCount += $issueHashCount->count;
            $issue->lastSeenAt = $issueGroup->lastSeenAt;
            $issue->update();
        }
    }

    /** One message per pending group; a failed send stays pending */
    protected function sendPendingAlerts(DateTime $now, IssuesRegistryJobResult $result): void
    {
        $alertWebhookSender = $this->getAlertWebhookSender();
        if (!$alertWebhookSender->isConfigured()) {
            return;
        }
        foreach (IssueGroup::getService()->findGroupsWithPendingAlert()->getElements() as $issueGroup) {
            if ($alertWebhookSender->sendGroupAlert($issueGroup)) {
                $issueGroup->alertPending = false;
                $issueGroup->alertSentAt = $now;
                $issueGroup->update();
                $result->alertsSent++;
            } else {
                $result->alertsFailed++;
            }
        }
    }

    /**
     * Whether the job and the pipeline are alive: stalled = no successful run within ISSUES_JOB_STALE_MINUTES, silent =
     * no event at all within ISSUES_COLLECTOR_SILENT_MINUTES.
     */
    public function getHealth(?DateTime $now = null): IssuesRegistryHealth
    {
        $settings = $this->getSettings();
        if (!$settings->hasQueryBackend()) {
            return new IssuesRegistryHealth();
        }
        $now ??= DateTime::fromTimestamp(time());
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $issuesJobState = IssuesJobState::getService()->getState();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        $lastRun = $issuesJobState->lastSuccessfulRunAt?->getTimestamp();
        $newestEvent = $issuesJobState->newestEventSeenAt?->getTimestamp();
        return new IssuesRegistryHealth(
            backendConfigured: true,
            lastSuccessfulRunAt: $issuesJobState->lastSuccessfulRunAt,
            newestEventSeenAt: $issuesJobState->newestEventSeenAt,
            lastError: $issuesJobState->lastError,
            jobStalled: $lastRun === null || $now->getTimestamp() - $lastRun > $settings->jobStaleMinutes * 60,
            collectorSilent: $newestEvent === null || $now->getTimestamp() - $newestEvent > $settings->collectorSilentMinutes * 60,
        );
    }
}
