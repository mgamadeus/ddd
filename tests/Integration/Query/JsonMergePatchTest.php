<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Query;

use DDD\Symfony\Commands\Base\Database\InstallPostgresExtensionsCollationFunctions;
use DDD\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The merge of mergeable JSON columns behaves alike on both engines: PostgreSQL's `ddd_json_merge_patch` as MariaDB's
 * JSON_MERGE_PATCH, also for values that are not objects (`[]` from an empty translation store, MariaDB-era rows) —
 * before, PostgreSQL raised "cannot deconstruct an array as an object" and every later save of the row failed.
 */
class JsonMergePatchTest extends IntegrationTestCase
{
    /** @return array<string, array{0: string, 1: string, 2: mixed}> */
    public static function mergeCases(): array
    {
        return [
            'object onto object' => ['{"de": "Hallo", "en": "Hello"}', '{"en": "Hi", "fr": "Salut"}', ['de' => 'Hallo', 'en' => 'Hi', 'fr' => 'Salut']],
            'null removes a key' => ['{"de": "Hallo", "en": "Hello"}', '{"de": null}', ['en' => 'Hello']],
            'array target is an empty object' => ['[]', '{"de": "Hallo"}', ['de' => 'Hallo']],
            'array patch is the result' => ['{"de": "Hallo"}', '[]', []],
            'scalar target' => ['"text"', '{"de": "Hallo"}', ['de' => 'Hallo']],
            'array onto array' => ['[1]', '[2]', [2]],
        ];
    }

    /**
     * @dataProvider mergeCases
     */
    public function testMergeMatchesMariaDb(string $target, string $patch, mixed $expected): void
    {
        $function = static::currentDriver() === 'pdo_pgsql' ? 'ddd_json_merge_patch(?::json, ?::json)' : 'JSON_MERGE_PATCH(?, ?)';

        $merged = static::connection()->fetchOne('SELECT ' . $function, [$target, $patch]);

        self::assertSame($expected, json_decode((string)$merged, true));
    }

    public function testTheInstallCommandAppliesTheFunctions(): void
    {
        $commandTester = new CommandTester(new InstallPostgresExtensionsCollationFunctions());

        self::assertSame(Command::SUCCESS, $commandTester->execute([]));
        if (static::currentDriver() === 'pdo_pgsql') {
            self::assertStringContainsString('statements applied', $commandTester->getDisplay());
            self::assertSame('{"de": "Hallo"}', (string)static::connection()->fetchOne("SELECT ddd_json_merge_patch('[]'::json, '{\"de\": \"Hallo\"}'::json)"));
        } else {
            self::assertStringContainsString('PostgreSQL only', $commandTester->getDisplay());
        }
    }
}
