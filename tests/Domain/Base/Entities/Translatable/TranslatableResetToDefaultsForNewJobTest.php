<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\Translatable;

use DDD\Domain\Base\Entities\Translatable\Translatable;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * A long-lived worker otherwise renders a job's texts in the language, country and writing style the PREVIOUS job
 * left in the process statics — or in the settings of a snapshot that job never restored.
 */
class TranslatableResetToDefaultsForNewJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Translatable::resetToDefaultsForNewJob();
    }

    protected function translationSettingsSnapshot(): ?array
    {
        return (new ReflectionProperty(Translatable::class, 'translationSettingsSnapshot'))->getValue();
    }

    public function testThePreviousJobsTranslationSettingsDoNotSurviveTheReset(): void
    {
        Translatable::setCurrentLanguageCode('de');
        Translatable::setCurrentCountryCode('AT');
        Translatable::setCurrentWritingStyle('INFORMAL');

        Translatable::resetToDefaultsForNewJob();

        $this->assertSame(Translatable::getDefaultLanguageCode(), Translatable::$currentLanguageCode);
        $this->assertSame(Translatable::getDefaultWritingStyle(), Translatable::$currentWritingStyle);
        $this->assertNull(Translatable::$currentCountryCode);
    }

    /** A job that died inside a snapshot/restore pair leaves the snapshot set, blocking the next job's snapshot. */
    public function testAnUnrestoredSnapshotDoesNotSurviveTheReset(): void
    {
        Translatable::setCurrentLanguageCode('de');
        Translatable::setTranslationSettingsSnapshot();

        $this->assertNotNull($this->translationSettingsSnapshot(), 'precondition: the died job left a snapshot');

        Translatable::resetToDefaultsForNewJob();

        $this->assertNull($this->translationSettingsSnapshot());
    }

    /** The reset lowercases through the setter, so the defaults land in the same shape a setter call produces. */
    public function testTheResetGoesThroughTheSettersSoTheValuesAreNormalised(): void
    {
        Translatable::setCurrentCountryCode('DE');
        $this->assertSame('de', Translatable::$currentCountryCode);

        Translatable::resetToDefaultsForNewJob();

        $this->assertSame(strtolower(Translatable::getDefaultLanguageCode()), Translatable::$currentLanguageCode);
    }
}
