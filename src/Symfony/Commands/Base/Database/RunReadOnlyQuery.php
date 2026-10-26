<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Base\Database;

use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'app:db:read',
    description: 'Runs ONE read-only statement (SELECT / WITH / SHOW / EXPLAIN / DESCRIBE) against a Doctrine connection and prints the rows. Writes are rejected; a LIMIT is enforced. JSON on stdout by default.',
    hidden: false
)]
class RunReadOnlyQuery extends Command
{
    /** @var string[] The only statement keywords this command executes */
    public const array ALLOWED_LEADING_KEYWORDS = ['SELECT', 'WITH', 'SHOW', 'EXPLAIN', 'DESCRIBE', 'DESC'];

    /**
     * @var string[] Keyword sequences refused before the statement reaches the database: the file writes, and the
     * shared-lock clauses a READ ONLY transaction still allows (MariaDB accepts LOCK IN SHARE MODE there; FOR SHARE is
     * the MySQL 8 spelling). FOR UPDATE and writing stored functions are refused by the transaction itself.
     */
    public const array REJECTED_KEYWORD_SEQUENCES = ['INTO OUTFILE', 'INTO DUMPFILE', 'LOCK IN SHARE MODE', 'FOR SHARE'];

    /** @var int MySQL/MariaDB error "Cannot execute statement in a READ ONLY transaction" */
    public const int ERROR_CODE_READ_ONLY_TRANSACTION = 1792;

    /** @var string[] Statement keywords that carry their own row count and must not get a LIMIT appended */
    public const array KEYWORDS_WITHOUT_LIMIT = ['SHOW', 'EXPLAIN', 'DESCRIBE', 'DESC'];

    /** @var int Rows returned when --limit is not passed */
    public const int DEFAULT_ROW_LIMIT = 200;

    protected function configure(): void
    {
        $this->addArgument(
            'statement',
            InputArgument::REQUIRED,
            'The read-only SQL statement, e.g. "SELECT id, email FROM users WHERE id = 581".'
        );
        $this->addOption(
            'scope',
            null,
            InputOption::VALUE_REQUIRED,
            'Connection scope: ' . EntityManagerFactory::SCOPE_DEFAULT . ' or ' . EntityManagerFactory::SCOPE_LEGACY_DB . '.',
            EntityManagerFactory::SCOPE_DEFAULT
        );
        $this->addOption(
            'limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Maximum number of rows. Appended as LIMIT when the statement carries none of its own.',
            (string)self::DEFAULT_ROW_LIMIT
        );
        $this->addOption(
            'format',
            null,
            InputOption::VALUE_REQUIRED,
            'Output format: json (default, machine readable on stdout) or table (console table).',
            'json'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $statement = trim((string)$input->getArgument('statement'));
        $scope = strtoupper((string)$input->getOption('scope'));
        $rowLimit = max(1, (int)$input->getOption('limit'));
        $format = strtolower((string)$input->getOption('format'));

        $rejectionReason = $this->getRejectionReason($statement);
        if ($rejectionReason !== null) {
            $output->writeln('<error>' . $rejectionReason . '</error>');
            return Command::INVALID;
        }

        $statementToExecute = $this->appendLimitIfMissing($statement, $rowLimit);

        try {
            $connection = EntityManagerFactory::getInstance($scope)->getConnection();
            $rows = $this->fetchRowsInReadOnlyTransaction($connection, $statementToExecute);
        } catch (Throwable $throwable) {
            $readOnlyRefusal = $throwable instanceof DriverException
                && $throwable->getCode() === self::ERROR_CODE_READ_ONLY_TRANSACTION;
            $output->writeln(
                '<error>' . ($readOnlyRefusal
                    ? 'The statement writes or takes locks and is refused in the read-only transaction: '
                    : '') . $throwable->getMessage() . '</error>'
            );
            return Command::FAILURE;
        }

        if ($format === 'table') {
            $this->writeRowsAsTable($rows, $output);
            return Command::SUCCESS;
        }

        $output->writeln(
            json_encode(
                ['scope' => $scope, 'statement' => $statementToExecute, 'rowCount' => count($rows), 'rows' => $rows],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
            OutputInterface::OUTPUT_RAW
        );
        return Command::SUCCESS;
    }

    /**
     * Runs the statement inside `START TRANSACTION READ ONLY` and rolls back afterwards, so a SELECT that calls a
     * writing stored function or locks rows (FOR UPDATE) is refused by the database instead of committing in
     * autocommit. Start, statement and rollback are raw statements on this one connection: a single
     * START TRANSACTION goes to the writer through ProxySQL and pins the following statements there, where a separate
     * "next transaction is read only" flag could be multiplexed onto another client's backend connection.
     *
     * @param Connection $connection The connection of the chosen scope
     * @param string $statement The checked statement, LIMIT appended
     * @return array<int, array<string, mixed>> The rows
     * @throws Throwable
     */
    protected function fetchRowsInReadOnlyTransaction(Connection $connection, string $statement): array
    {
        $connection->executeStatement('START TRANSACTION READ ONLY');
        try {
            return $connection->executeQuery($statement)->fetchAllAssociative();
        } finally {
            try {
                $connection->executeStatement('ROLLBACK');
            } catch (Throwable) {
                // the session is unusable (e.g. connection lost) — dropping it ends the transaction on the server
                $connection->close();
            }
        }
    }

    /**
     * Names why the statement is not accepted, or null when it may run.
     *
     * The first gate only: it stops the obvious write, the SELECT ... INTO OUTFILE file write and the shared-lock
     * clauses. Everything that passes runs in a READ ONLY transaction ({@see self::fetchRowsInReadOnlyTransaction()}),
     * which refuses writes and FOR UPDATE on the database side. A user that only holds SELECT rights is still the
     * strongest boundary.
     */
    protected function getRejectionReason(string $statement): ?string
    {
        if ($statement === '') {
            return 'Empty statement.';
        }
        // A trailing semicolon is fine, a second statement behind it is not.
        if (str_contains(rtrim($statement, "; \t\n\r"), ';')) {
            return 'Only one statement per call - remove the ";".';
        }

        $statementWithoutLeadingComments = $this->stripLeadingCommentsAndParentheses($statement);
        $leadingKeyword = strtoupper((string)preg_replace('/^([A-Za-z]+).*$/s', '$1', $statementWithoutLeadingComments));
        if (!in_array($leadingKeyword, self::ALLOWED_LEADING_KEYWORDS, true)) {
            return 'This command runs read-only statements only ('
                . implode(' / ', self::ALLOWED_LEADING_KEYWORDS)
                . "), got '{$leadingKeyword}'.";
        }

        $statementNormalizedForKeywordSearch = (string)preg_replace('/\s+/', ' ', strtoupper($statement));
        foreach (self::REJECTED_KEYWORD_SEQUENCES as $rejectedKeywordSequence) {
            if (str_contains($statementNormalizedForKeywordSearch, $rejectedKeywordSequence)) {
                return "'{$rejectedKeywordSequence}' writes a file and is not allowed here.";
            }
        }

        return null;
    }

    /**
     * Removes leading line comments, block comments and opening parentheses so a statement that starts with a
     * comment or with an opening parenthesis is still recognized by its first real keyword.
     */
    protected function stripLeadingCommentsAndParentheses(string $statement): string
    {
        $previousStatement = null;
        while ($previousStatement !== $statement) {
            $previousStatement = $statement;
            $statement = ltrim($statement);
            $statement = (string)preg_replace('#^--[^\n]*\n#', '', $statement);
            $statement = (string)preg_replace('#^/\*.*?\*/#s', '', $statement);
            $statement = (string)preg_replace('#^\(+#', '', $statement);
        }
        return $statement;
    }

    /**
     * Appends "LIMIT <rowLimit>" to a row-returning statement that carries no LIMIT of its own, so a forgotten WHERE
     * cannot pull a whole table into the console.
     */
    protected function appendLimitIfMissing(string $statement, int $rowLimit): string
    {
        $statementWithoutTrailingSemicolon = rtrim($statement, "; \t\n\r");
        $leadingKeyword = strtoupper(
            (string)preg_replace('/^([A-Za-z]+).*$/s', '$1', $this->stripLeadingCommentsAndParentheses($statement))
        );
        if (in_array($leadingKeyword, self::KEYWORDS_WITHOUT_LIMIT, true)) {
            return $statementWithoutTrailingSemicolon;
        }
        if (preg_match('/\bLIMIT\s+\d/i', $statementWithoutTrailingSemicolon)) {
            return $statementWithoutTrailingSemicolon;
        }
        return $statementWithoutTrailingSemicolon . ' LIMIT ' . $rowLimit;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    protected function writeRowsAsTable(array $rows, OutputInterface $output): void
    {
        if ($rows === []) {
            $output->writeln('<comment>(no rows)</comment>');
            return;
        }
        $table = new Table($output);
        $table->setHeaders(array_keys($rows[0]));
        foreach ($rows as $row) {
            $table->addRow(
                array_map(
                    static fn(mixed $columnValue): string => $columnValue === null
                        ? 'NULL'
                        : (is_scalar($columnValue) ? (string)$columnValue : json_encode($columnValue)),
                    $row
                )
            );
        }
        $table->render();
        $output->writeln('<info>' . count($rows) . ' row(s)</info>');
    }
}
