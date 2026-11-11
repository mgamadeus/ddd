# Raw SQL that runs on MariaDB and PostgreSQL

A package or an application that supports both databases writes each raw statement once. The dialect of the
connection renders the parts the engines write differently.

```php
$connection = EntityManagerFactory::getInstance()->getConnection();
$dialect = EntityManagerFactory::getDialect();               // SqlDialect of the default scope
```

## Contents

- [Rules for every raw statement](#rules-for-every-raw-statement)
- [Prefer DQL](#prefer-dql)
- [Fragments](#fragments)
- [JSON columns: read](#json-columns-read)
- [JSON columns: change inside the UPDATE](#json-columns-change-inside-the-update)
- [Insert that skips existing rows](#insert-that-skips-existing-rows)
- [Lock inside a transaction](#lock-inside-a-transaction)
- [Timeouts of a transaction](#timeouts-of-a-transaction)
- [What has no common form](#what-has-no-common-form)
- [Virtual columns](#virtual-columns)

## Rules for every raw statement

- **Backticks around every identifier and alias** (`` `AIRuns` ``, `` `createdAt` ``, `` AS `total` ``). The
  connection turns them into the engine's quotes; an unquoted mixed-case name is lower-cased by PostgreSQL and not
  found. Table names come from `Model::getTableName()`.
- **Bind values**; a boolean literal in the text is `$dialect->boolLiteral(true)`.
- **No backslash in the SQL text.** PDO before PHP 8.4 reads `\` inside a string literal as an escape when it
  looks for placeholders: after a literal like `'\'` it loses `?` placeholders (SQLSTATE[HY093] "parameter was not
  defined"), on PostgreSQL only and often only when the fragment appears twice in one statement. Write `chr(92)` for a
  backslash and bracket classes (`[[:space:]]`, `[-+*]`) in regular expressions.
- **No engine function in the text** (`IF()`, `IFNULL()`, `NOW()`, `JSON_*`, `GROUP_CONCAT`, `DATE_ADD`, `GET_LOCK`,
  `INSERT IGNORE`, `ON DUPLICATE KEY`, `LOCK IN SHARE MODE`): standard SQL (`CASE WHEN`, `COALESCE`) or a fragment
  below.
- **A claim does not count rows.** MariaDB counts changed rows, PostgreSQL matched rows. Write the claim so that a
  matched row is a changed row: the WHERE excludes the target state
  (`SET status = :to WHERE id = :id AND status = :from`).
- `UPDATE … LIMIT` / `DELETE … LIMIT` exist on MariaDB only: select the ids first, or
  `WHERE id IN (SELECT id FROM (SELECT … LIMIT n) AS picked)`.

## Prefer DQL

A statement over mapped columns belongs in the repository's QueryBuilder: identifiers, booleans, pagination and the
registered functions (`ddd-query-options-specialist`, `ddd-geometry-and-vector-specialist`) are rendered per engine.
Raw SQL stays for what DQL cannot say (an atomic JSON change, `INSERT … SELECT`, a row lock clause, an aggregate over
a derived table) and for hot paths where one statement replaces several.

## Fragments

Every method returns SQL text; arguments are SQL expressions (a quoted column, a placeholder), never user input.

| Need | Method |
|---|---|
| statement time / a moment before it | `now()`, `intervalAgo(':minutes', 'MINUTE')` |
| add to a date, seconds between two | `dateAdd($columnSql, $amountSql, 'DAY')`, `secondsBetween($fromSql, $toSql)` |
| day / week / month of a column | `dateBucket($columnSql, 'day')` |
| values of a group as one string | `stringAgg($exprSql, ', ', $orderBySql)`, `stringAggDistinct($exprSql, ', ')` |
| NULL-safe equality | `bytesEqual($leftSql, $rightSql)` |
| update one table from a join | `updateFromJoin(UpdateFromJoinSpec)` |
| share lock at the end of a SELECT | `forShare()` (`FOR UPDATE` is the same text on both) |
| upsert / insert-or-lose of one row without an entity | `upsert($connection, UpsertSpec)`, `insertOrNull($connection, UpsertSpec)` |

## JSON columns: read

A path is a `JsonPath`: literal keys, and bound values where a key comes from data.

```php
$status = JsonPath::fromKeys('subAgent', 'runs')->withParameter(':runNumber')->withKey('status');

$sql = 'SELECT ' . $dialect->jsonText('`toolCall`', $status) . ' AS `runStatus`, '
    . $dialect->jsonInt('`toolCall`', JsonPath::fromKeys('subAgent', 'number')) . ' AS `number` '
    . "FROM `$tableName` WHERE `id` = :id AND " . $dialect->jsonType('`toolCall`', $status) . ' IS NOT NULL';
```

| Method | Answer |
|---|---|
| `jsonText` | a string without quotes, a number or boolean in its JSON form; NULL for a missing path and for JSON null |
| `jsonInt`, `jsonDecimal($c, $p, 16, 8)` | the number; NULL when missing |
| `jsonBool` | true only for JSON true; NULL when missing |
| `jsonType` | `object`, `array`, `string`, `number`, `boolean`, `null`; SQL NULL = the path does not exist |
| `jsonLength` | elements of an array, keys of an object |

An object or array read as text differs in spacing between the engines: read those in PHP.

## JSON columns: change inside the UPDATE

`jsonUpdate()` builds one expression for `SET col = …`: no read-modify-write, the row is changed under its own lock.

```php
$runs = JsonPath::fromKeys('subAgent', 'runs');
$run = $runs->withParameter(':runNumber');

$documentSql = $dialect->jsonUpdate('`toolCall`')
    ->setObjectUnlessPresent(JsonPath::fromKeys('subAgent'))
    ->setObjectUnlessPresent($runs)
    ->setObjectUnlessPresent($run)
    ->set($run->withKey('status'), ':status', JsonValueKind::TEXT)
    ->set($run->withKey('cost'), 'CAST(:cost AS DECIMAL(16,8))', JsonValueKind::NUMBER)
    ->remove($run->withKey('error'))
    ->toSql();

$claimed = (int)$connection->executeStatement(
    "UPDATE `$tableName` SET `toolCall` = $documentSql WHERE `id` = :id AND "
    . $dialect->jsonText('`toolCall`', $run->withKey('status')) . ' = :from',
    ['id' => $id, 'runNumber' => (string)$runNumber, 'status' => 'ended', 'cost' => $cost, 'from' => 'running']
);
```

- **`set()` does not create missing parents** on either engine: the statement changes nothing then. Name each
  parent with `setObjectUnlessPresent()` first (it leaves an existing object alone).
- The value kind says what the SQL expression is: `TEXT` (always a JSON string), `NUMBER`, `BOOLEAN`, `JSON` (bound
  JSON text or another JSON column). A SQL NULL value writes JSON null (false for `BOOLEAN`).
- `mergePatch(':patch')` merges an RFC 7396 patch (nested objects merge, a null removes the key).
- A NULL column counts as `{}`. A placeholder used twice needs a named parameter.

## Insert that skips existing rows

```php
$insertSql = $dialect->insertSkippingExistingRows(
    "INSERT INTO `$tableName` (`conversationId`, `code`) VALUES (:c1, :k1), (:c2, :k2)",
    $tableName,
    'code'            // any column of the target table
);
$written = (int)$connection->executeStatement($insertSql, $parameters);   // rows written, on both engines
```

Works for `INSERT … SELECT` too. A row whose unique key exists is left out; every other error still fails (unlike
`INSERT IGNORE`).

## Lock inside a transaction

A named lock on the transaction's own connection, in one name space with `$dialect->locks()`. It also works behind
a connection pooler in transaction mode.

```php
$connection->beginTransaction();
try {
    if (!$dialect->acquireTransactionLock($connection, "conversation.$conversationId", 5)) {
        throw new BadRequestException('Conversation is busy');
    }
    // … read, decide, write …
    $connection->commit();
} catch (Throwable $throwable) {
    $connection->rollBack();
    throw $throwable;
} finally {
    $dialect->releaseTransactionLock($connection, "conversation.$conversationId");
}
```

PostgreSQL releases the lock when the transaction ends; MariaDB holds it until `releaseTransactionLock()`. Without
an open transaction `acquireTransactionLock()` throws. A lock around work without a transaction: `$dialect->locks()`.

## Timeouts of a transaction

```php
$connection->beginTransaction();
try {
    $dialect->setTransactionTimeouts($connection, lockWaitTimeoutSeconds: 2, statementTimeoutSeconds: 10);
    // …
    $connection->commit();
} catch (Throwable $throwable) {
    $connection->rollBack();
    throw $throwable;
} finally {
    $dialect->clearTransactionTimeouts($connection);
}
```

A statement over a limit throws a DBAL exception. PostgreSQL then aborts the transaction (roll back, start again);
MariaDB rolls back the statement only. Code that must behave the same rolls back on either.

## What has no common form

- Wildcard JSON paths (`$.runs.*.status`), JSON search functions: read the document in PHP.
- Server status and metadata queries (`SHOW …`, `information_schema` details): branch on
  `$dialect->capabilities()->isPostgres()`.
- Vector literals and distances: DQL (`ddd-geometry-and-vector-specialist`).

## Virtual columns

`#[DatabaseVirtualColumn(as: …)]` keeps its MariaDB expression; the PostgreSQL form is derived for these shapes:
`IFNULL(col, 0 | 'x')`, `COALESCE(col, STR_TO_DATE('Y-m-d', '%Y-%m-%d'))`, `CASE WHEN col IN ('A', 'B') THEN col ELSE
NULL END`, `JSON_UNQUOTE(JSON_EXTRACT(col, '$.key'))` plain or inside `CAST(… AS SIGNED | DECIMAL(p,s))` or
`CHAR_LENGTH(…)`, `CHAR_LENGTH(JSON_EXTRACT(col, '$.key'))`, `DATE(col)`. Any other expression needs `asPostgres:`.
The two character counts are close, not equal, across the engines (a JSON null counts 4 on MariaDB and is NULL on
PostgreSQL; a JSON value is counted as each engine writes it): use them for sizes and thresholds, not for equality.
