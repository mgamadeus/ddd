<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Dialect\Identifiers;

use DDD\Domain\Base\Repo\DB\Dialect\Identifiers\IdentifierPolicy;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use PHPUnit\Framework\TestCase;

/** D1/§4.5: schema-wide PostgreSQL identifier naming, the 63-byte cut, and the schema-wide duplicate guard. */
final class IdentifierPolicyTest extends TestCase
{
    public function testShortNamesPassThroughUnchanged(): void
    {
        $policy = new IdentifierPolicy();
        $this->assertSame('Widgets_idx_amount', $policy->indexName('Widgets', 'idx_amount'));
        $this->assertSame('Widgets_fk_businessId', $policy->foreignKeyName('Widgets', 'businessId'));
    }

    public function testLongNamesAreCutToSixtyThreeBytesWithAHashSuffix(): void
    {
        $policy = new IdentifierPolicy();
        $table = 'EntityAIConversationTaskPushNotificationConfigs';
        $name = $policy->indexName($table, 'idx_aiConversationId');

        $this->assertSame(63, strlen($name));
        $this->assertMatchesRegularExpression('/^' . preg_quote(substr("{$table}_idx_aiConversationId", 0, 54), '/') . '_[0-9a-f]{8}$/', $name);
    }

    public function testShorteningIsDeterministic(): void
    {
        $policy = new IdentifierPolicy();
        $table = str_repeat('X', 80);
        $first = $policy->indexName($table, 'idx_col');

        $policyAgain = new IdentifierPolicy();
        $second = $policyAgain->indexName($table, 'idx_col');

        $this->assertSame($first, $second);
    }

    public function testRepeatedResolutionOfTheSameNameIsNotADuplicate(): void
    {
        $policy = new IdentifierPolicy();
        $first = $policy->indexName('Widgets', 'idx_amount');
        $second = $policy->indexName('Widgets', 'idx_amount');
        $this->assertSame($first, $second);
    }

    public function testTwoLongNamesSharingA54ByteHeadStayDistinct(): void
    {
        // Common case: two different full names whose first 54 bytes match (so the "head" portion alone could not
        // disambiguate them) must still come out different, because the sha256 suffix is computed over the FULL
        // original name, not just the head.
        $policy = new IdentifierPolicy();
        $head = str_repeat('a', 54);
        $first = $policy->indexName('Table', $head . 'One');
        $second = $policy->indexName('Table', $head . 'Two');
        $this->assertNotSame($first, $second);
    }

    public function testTwoDifferentOriginalNamesResolvingToTheSameFinalNameThrow(): void
    {
        // reserve() lets a test force the collision directly: register a long name (so it gets shortened), then
        // reserve ITS ALREADY-SHORTENED form as if that were itself a distinct, unshortened original name. Both
        // inputs are different "full names" that the policy must treat as colliding on the same final identifier.
        $policy = new IdentifierPolicy();
        $longOriginal = str_repeat('Y', 80) . '_idx_col';
        $shortenedForm = $policy->reserve($longOriginal);

        $this->expectException(InternalErrorException::class);
        $policy->reserve($shortenedForm);
    }
}
