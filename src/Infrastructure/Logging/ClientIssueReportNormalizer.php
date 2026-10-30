<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging;

use DDD\Domain\Common\Entities\Issues\Client\ClientIssueBreadcrumb;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueBreadcrumbs;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueContext;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueEvent;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueEvents;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueFrame;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueFrames;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueReport;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueTag;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueTags;
use DDD\Infrastructure\Exceptions\BadRequestException;

/**
 * Enforces the caps of the wire contract on a client report, whatever the client sent: texts are truncated, frames,
 * breadcrumbs and tags beyond their cap are dropped (the innermost frames and the newest breadcrumbs stay), unknown
 * enum values fall back to the default. A client's own caps are never trusted.
 */
class ClientIssueReportNormalizer
{
    /**
     * Caps the report in place and returns it. Events beyond {@see ClientIssueEvents::MAX_EVENTS} are NOT removed here
     * — the caller counts them as dropped.
     *
     * @throws BadRequestException The report has no event
     */
    public function normalizeReport(ClientIssueReport $clientIssueReport): ClientIssueReport
    {
        if (!isset($clientIssueReport->events) || $clientIssueReport->events->count() === 0) {
            throw new BadRequestException('A client issue report needs at least one event');
        }
        $this->normalizeContext($clientIssueReport->client);
        foreach ($clientIssueReport->events->getElements() as $clientIssueEvent) {
            $this->normalizeEvent($clientIssueEvent);
        }
        return $clientIssueReport;
    }

    public function normalizeContext(ClientIssueContext $clientIssueContext): void
    {
        if (!in_array($clientIssueContext->runtime, ClientIssueContext::RUNTIMES, true)) {
            $clientIssueContext->runtime = ClientIssueContext::RUNTIME_BROWSER;
        }
        $clientIssueContext->app = $this->truncateNullable($clientIssueContext->app, ClientIssueContext::MAX_SHORT_TEXT_LENGTH);
        $clientIssueContext->release = $this->truncateNullable($clientIssueContext->release, ClientIssueContext::MAX_SHORT_TEXT_LENGTH);
        $clientIssueContext->tabId = $this->truncateNullable($clientIssueContext->tabId, ClientIssueContext::MAX_SHORT_TEXT_LENGTH);
        $clientIssueContext->locale = $this->truncateNullable($clientIssueContext->locale, ClientIssueContext::MAX_SHORT_TEXT_LENGTH);
        $clientIssueContext->userAgent = $this->truncateNullable($clientIssueContext->userAgent, ClientIssueContext::MAX_USER_AGENT_LENGTH);
    }

    public function normalizeEvent(ClientIssueEvent $clientIssueEvent): void
    {
        if (!in_array($clientIssueEvent->kind, ClientIssueEvent::KINDS, true)) {
            $clientIssueEvent->kind = ClientIssueEvent::KIND_ERROR;
        }
        if (!in_array($clientIssueEvent->level, ClientIssueEvent::LEVELS, true)) {
            $clientIssueEvent->level = ClientIssueEvent::LEVEL_ERROR;
        }
        $clientIssueEvent->name = $this->truncate(trim($clientIssueEvent->name), ClientIssueEvent::MAX_NAME_LENGTH);
        if ($clientIssueEvent->name === '') {
            $clientIssueEvent->name = 'Error';
        }
        $clientIssueEvent->message = $this->truncate($clientIssueEvent->message, ClientIssueEvent::MAX_MESSAGE_LENGTH);
        $clientIssueEvent->occurredAt = $this->truncate($clientIssueEvent->occurredAt, ClientIssueContext::MAX_SHORT_TEXT_LENGTH);
        $clientIssueEvent->url = $this->truncateNullable($clientIssueEvent->url, ClientIssueEvent::MAX_URL_LENGTH);
        $clientIssueEvent->routePattern = $this->truncateNullable($clientIssueEvent->routePattern, ClientIssueEvent::MAX_ROUTE_PATTERN_LENGTH);
        $clientIssueEvent->requestMethod = $this->truncateNullable(
            $clientIssueEvent->requestMethod !== null ? strtoupper($clientIssueEvent->requestMethod) : null,
            ClientIssueEvent::MAX_REQUEST_METHOD_LENGTH
        );
        $clientIssueEvent->clientFingerprint = $this->truncateNullable($clientIssueEvent->clientFingerprint, ClientIssueEvent::MAX_CLIENT_FINGERPRINT_LENGTH);
        $clientIssueEvent->count = max(1, $clientIssueEvent->count);

        $clientIssueEvent->frames = $this->normalizeFrames($clientIssueEvent->frames ?? new ClientIssueFrames());
        // the raw stack is only the fallback of a client that could not parse its frames
        $clientIssueEvent->rawStack = $clientIssueEvent->frames->count() === 0
            ? $this->truncateNullable($clientIssueEvent->rawStack, ClientIssueEvent::MAX_RAW_STACK_LENGTH)
            : null;
        $clientIssueEvent->breadcrumbs = $this->normalizeBreadcrumbs($clientIssueEvent->breadcrumbs ?? new ClientIssueBreadcrumbs());
        $clientIssueEvent->tags = $this->normalizeTags($clientIssueEvent->tags ?? new ClientIssueTags());
    }

    /** The innermost {@see ClientIssueFrames::MAX_FRAMES} frames, each capped */
    public function normalizeFrames(ClientIssueFrames $clientIssueFrames): ClientIssueFrames
    {
        $normalizedFrames = new ClientIssueFrames();
        foreach (array_slice($clientIssueFrames->getElements(), 0, ClientIssueFrames::MAX_FRAMES) as $clientIssueFrame) {
            /** @var ClientIssueFrame $clientIssueFrame */
            $clientIssueFrame->file = $this->truncate($clientIssueFrame->file, ClientIssueFrame::MAX_FILE_LENGTH);
            $clientIssueFrame->function = $this->truncateNullable($clientIssueFrame->function, ClientIssueFrame::MAX_FUNCTION_LENGTH);
            $normalizedFrames->add($clientIssueFrame);
        }
        return $normalizedFrames;
    }

    /** The newest {@see ClientIssueBreadcrumbs::MAX_BREADCRUMBS} breadcrumbs, each capped */
    public function normalizeBreadcrumbs(ClientIssueBreadcrumbs $clientIssueBreadcrumbs): ClientIssueBreadcrumbs
    {
        $normalizedBreadcrumbs = new ClientIssueBreadcrumbs();
        foreach (array_slice($clientIssueBreadcrumbs->getElements(), -ClientIssueBreadcrumbs::MAX_BREADCRUMBS) as $clientIssueBreadcrumb) {
            /** @var ClientIssueBreadcrumb $clientIssueBreadcrumb */
            if (!in_array($clientIssueBreadcrumb->category, ClientIssueBreadcrumb::CATEGORIES, true)) {
                $clientIssueBreadcrumb->category = ClientIssueBreadcrumb::CATEGORY_CUSTOM;
            }
            $clientIssueBreadcrumb->at = $this->truncate($clientIssueBreadcrumb->at, ClientIssueContext::MAX_SHORT_TEXT_LENGTH);
            $clientIssueBreadcrumb->message = $this->truncate($clientIssueBreadcrumb->message, ClientIssueBreadcrumb::MAX_MESSAGE_LENGTH);
            $clientIssueBreadcrumb->url = $this->truncateNullable($clientIssueBreadcrumb->url, ClientIssueBreadcrumb::MAX_URL_LENGTH);
            $clientIssueBreadcrumb->from = $this->truncateNullable($clientIssueBreadcrumb->from, ClientIssueBreadcrumb::MAX_URL_LENGTH);
            $clientIssueBreadcrumb->to = $this->truncateNullable($clientIssueBreadcrumb->to, ClientIssueBreadcrumb::MAX_URL_LENGTH);
            $normalizedBreadcrumbs->add($clientIssueBreadcrumb);
        }
        return $normalizedBreadcrumbs;
    }

    /** The first {@see ClientIssueTags::MAX_TAGS} tags, key and value capped */
    public function normalizeTags(ClientIssueTags $clientIssueTags): ClientIssueTags
    {
        $normalizedTags = new ClientIssueTags();
        foreach (array_slice($clientIssueTags->getElements(), 0, ClientIssueTags::MAX_TAGS) as $clientIssueTag) {
            /** @var ClientIssueTag $clientIssueTag */
            $clientIssueTag->key = $this->truncate($clientIssueTag->key, ClientIssueTag::MAX_LENGTH);
            $clientIssueTag->value = $this->truncate($clientIssueTag->value, ClientIssueTag::MAX_LENGTH);
            if ($clientIssueTag->key !== '') {
                $normalizedTags->add($clientIssueTag);
            }
        }
        return $normalizedTags;
    }

    protected function truncate(string $text, int $maxLength): string
    {
        return mb_strlen($text) > $maxLength ? mb_substr($text, 0, $maxLength) : $text;
    }

    protected function truncateNullable(?string $text, int $maxLength): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }
        return $this->truncate($text, $maxLength);
    }
}
