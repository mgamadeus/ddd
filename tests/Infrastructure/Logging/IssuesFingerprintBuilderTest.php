<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Logging;

use DDD\Infrastructure\Logging\IssuesFingerprintBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The two hashes are stable: no line numbers and no free-text message in either, the SQLSTATE and the normalised message
 * of a generic engine error discriminate, the entry point only splits issues.
 */
class IssuesFingerprintBuilderTest extends TestCase
{
    protected function builder(): IssuesFingerprintBuilder
    {
        return new IssuesFingerprintBuilder(dirname(__DIR__, 3));
    }

    public function testTheSameBugMovedByAFewLinesKeepsBothHashes(): void
    {
        $thrower = new IssuesFixtureThrower();
        $before = $thrower->runtimeError(1);
        $after = $thrower->runtimeError(2);
        $this->assertNotSame($before->getLine(), $after->getLine());

        $groupHash = $this->builder()->buildGroupHash($before);
        $this->assertSame($groupHash, $this->builder()->buildGroupHash($after));
        $this->assertSame(
            $this->builder()->buildIssueHash($before, $groupHash, 'api_orders_list'),
            $this->builder()->buildIssueHash($after, $groupHash, 'api_orders_list')
        );
    }

    public function testTheMessageIsNotPartOfTheHashes(): void
    {
        $thrower = new IssuesFixtureThrower();

        $this->assertSame(
            $this->builder()->buildGroupHash($thrower->runtimeError(1, 'failed for id 42')),
            $this->builder()->buildGroupHash($thrower->runtimeError(1, 'failed for id 43 of customer x@y.com'))
        );
    }

    public function testTheSameBugThroughTwoRoutesIsOneGroupAndTwoIssues(): void
    {
        $exception = (new IssuesFixtureThrower())->runtimeError(1);
        $groupHash = $this->builder()->buildGroupHash($exception);

        $this->assertNotSame(
            $this->builder()->buildIssueHash($exception, $groupHash, 'api_orders_list'),
            $this->builder()->buildIssueHash($exception, $groupHash, 'api_venues_detail')
        );
    }

    public function testTwoSqlStatesAreTwoGroups(): void
    {
        $thrower = new IssuesFixtureThrower();

        $this->assertNotSame(
            $this->builder()->buildGroupHash($thrower->databaseError('42703')),
            $this->builder()->buildGroupHash($thrower->databaseError('23505'))
        );
        $this->assertSame('sqlstate:42703', $this->builder()->getDiscriminator($thrower->databaseError('42703')));
    }

    public function testAGenericEngineErrorIsDiscriminatedByItsNormalisedMessage(): void
    {
        $thrower = new IssuesFixtureThrower();

        $this->assertSame(
            $this->builder()->buildGroupHash($thrower->engineError('Undefined array key 5')),
            $this->builder()->buildGroupHash($thrower->engineError('Undefined array key 7'))
        );
        $this->assertNotSame(
            $this->builder()->buildGroupHash($thrower->engineError('Undefined array key 5')),
            $this->builder()->buildGroupHash($thrower->engineError('Call to a member function id() on null'))
        );
    }

    public function testTheMessageNormalisationReplacesVolatileParts(): void
    {
        $this->assertSame(
            'order {uuid} at {timestamp} for {literal} with {number} items, ref {hex}',
            $this->builder()->normaliseMessage(
                "order 3f2b6a1c-9d4e-4b1a-8f0c-2a7e5d9c1b3f at 2026-10-28 14:05:00 for 'Anna' with 12 items, ref deadbeefcafe1234"
            )
        );
    }

    public function testAClosureNameLosesItsLine(): void
    {
        $this->assertSame(
            '{closure:App\Orders::place()}',
            $this->builder()->normaliseFunctionName('{closure:App\Orders::place():118}')
        );
    }

    public function testFramesAreRelativeAndWithoutLines(): void
    {
        $frames = $this->builder()->getFrames((new IssuesFixtureThrower())->runtimeError(1));

        $this->assertSame('tests/Infrastructure/Logging/IssuesFixtureThrower.php#' . IssuesFixtureThrower::class . '::runtimeError', $frames[0]);
        foreach ($frames as $frame) {
            $this->assertDoesNotMatchRegularExpression('/:\d+$/', $frame);
        }
    }
}
