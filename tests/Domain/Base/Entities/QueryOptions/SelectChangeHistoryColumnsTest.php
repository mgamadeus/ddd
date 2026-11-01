<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\QueryOptions\SelectOptions;
use DDD\Tests\Integration\Fixtures\Entities\ChangeHistoryRows\ChangeHistoryRow;
use PHPUnit\Framework\TestCase;

/**
 * `select=created,updated` (the ChangeHistory column names) keeps `changeHistory` in the output; without them it is
 * hidden like any unselected property.
 */
class SelectChangeHistoryColumnsTest extends TestCase
{
    /** @return string[] The hidden properties for a select on a fresh join path */
    protected function hiddenPropertiesFor(array $selectedProperties): array
    {
        $joinPath = 'select-change-history-' . implode('-', $selectedProperties) . '-' . uniqid();
        SelectOptions::addPropertiesToHideByByJoinPath(ChangeHistoryRow::class, $joinPath, $selectedProperties);
        return SelectOptions::$propertiesToHideByJoinPath[$joinPath];
    }

    public function testSelectingCreatedOrUpdatedKeepsChangeHistory(): void
    {
        self::assertNotContains('changeHistory', $this->hiddenPropertiesFor(['label', 'created']));
        self::assertNotContains('changeHistory', $this->hiddenPropertiesFor(['updated']));
        self::assertNotContains('changeHistory', $this->hiddenPropertiesFor(['created', 'updated']));
    }

    public function testChangeHistoryStaysHiddenWithoutTheColumns(): void
    {
        $hiddenProperties = $this->hiddenPropertiesFor(['label']);
        self::assertContains('changeHistory', $hiddenProperties);
        self::assertNotContains('label', $hiddenProperties);
    }
}
