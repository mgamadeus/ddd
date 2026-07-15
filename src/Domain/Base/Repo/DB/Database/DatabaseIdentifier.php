<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database;

/**
 * MariaDB/MySQL identifier length guard: schema object names (constraints, index names) are capped at
 * 64 characters. Auto-generated names built from table + column names can exceed the cap (long entity
 * class names produce long table names — e.g. `fk_EntityAIConversationTaskPushNotificationConfigs_aiConversationId`
 * is 68 chars and fails with error 1059 "Identifier name is too long"). Such names are shortened
 * DETERMINISTICALLY: the head is kept for readability, a 6-hex-char md5 suffix over the FULL original
 * name keeps the result unique (FK constraint names are schema-wide unique in MariaDB, so truncation
 * alone could collide across tables). Deterministic = the same input always yields the same name, so
 * the schema diff never ping-pongs (FKs and indexes are additionally matched by column composition,
 * never by name — see DatabaseSchemaDiffService::buildForeignKeyMatchKey / buildIndexMatchKey).
 */
class DatabaseIdentifier
{
    /** @var int MariaDB/MySQL maximum length for schema object identifiers. */
    public const int MAX_LENGTH = 64;

    /** @var int Hex chars of the md5 disambiguator suffix appended when shortening. */
    protected const int HASH_SUFFIX_LENGTH = 6;

    /** Returns the identifier unchanged when within the cap, else head + `_` + md5-suffix at exactly the cap. */
    public static function shortenToMaxLength(string $identifierName): string
    {
        if (strlen($identifierName) <= self::MAX_LENGTH) {
            return $identifierName;
        }
        $hashSuffix = substr(md5($identifierName), 0, self::HASH_SUFFIX_LENGTH);
        return substr($identifierName, 0, self::MAX_LENGTH - self::HASH_SUFFIX_LENGTH - 1) . '_' . $hashSuffix;
    }
}
