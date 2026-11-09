<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Queue\Doctor;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Arr;
use Illuminate\Support\ConfigurationUrlParser;
use InvalidArgumentException;
use SinaSaeedi\RuntimeMcp\Doctor\Check;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\FailedJobReader;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\FailedJobSource;

/**
 * Spec 7.3, 01-23 Part E: where the failed-job tools read. Config only, as QueuesCheck and ReadOnlyConnectionOptions:
 * it opens no connection, and it prints no connection name, database name, host or path (01-12, 01-14).
 *
 * Laravel writes failed jobs on queue.failed.database; the tools read the read-only connection. When the two name
 * different databases, the tools may read another failed_jobs table with no error. 01-13's check 3 makes the same
 * compare ("a read replica keeps its name").
 */
final class FailedJobsCheck implements Check
{
    public const TABLE = 'The failed-job tools read table `%s` on the read-only connection.';

    public const DIFFERENT = 'The read-only connection and queue.failed.database name different databases, so the failed-job tools may read another `%s` table.';

    public const DIFFERENT_FIX = 'Point the read-only connection at the database of queue.failed.database (a read replica keeps its name).';

    public const NOT_CHECKED = 'Not checked until RUNTIME_MCP_DB_CONNECTION names a connection in config/database.php (see Boot rules).';

    public const UNKNOWN = 'Not checked: the connection of queue.failed.database or the read-only connection names no database in config/database.php.';

    public const FILE = 'The failed-job tools read the failed-job file (at most %s bytes).';

    public const DYNAMODB = 'Failed jobs are stored in DynamoDB, which the failed-job tools do not read in v1.0.0.';

    public const DYNAMODB_FIX = 'Read them in the AWS console, or store failed jobs in the database (queue.failed.driver = database-uuids).';

    public const NONE_FIX = 'Set queue.failed in config/queue.php; Laravel\'s default is the database-uuids driver and the failed_jobs table.';

    public function __construct(private readonly Repository $config) {}

    public function title(): string
    {
        return 'Failed jobs';
    }

    public function run(): array
    {
        $source = FailedJobSource::fromConfig($this->config);

        if ($source->kind === FailedJobSource::FILE) {
            return [CheckResult::pass(sprintf(self::FILE, number_format(FailedJobReader::MAX_FILE_BYTES)))];
        }

        if ($source->kind === FailedJobSource::NONE) {
            return [$source->dynamoDb
                ? CheckResult::warn(self::DYNAMODB, self::DYNAMODB_FIX)
                : CheckResult::warn((string) $source->note, self::NONE_FIX)];
        }

        $readOnly = $this->config->get('runtime-mcp.database.connection');

        if (! is_string($readOnly) || trim($readOnly) === '') {
            return [CheckResult::warn(self::NOT_CHECKED)];
        }

        // Laravel's providers use queue.failed.database; unset, DatabaseManager takes the default connection.
        $writer = $this->config->get('queue.failed.database');
        $writer = is_string($writer) && $writer !== '' ? $writer : $this->config->get('database.default');
        $written = is_string($writer) ? $this->database($writer) : null;
        $read = $this->database($readOnly);

        if ($written === null || $read === null) {
            return [CheckResult::warn(self::UNKNOWN)];
        }

        return $written === $read
            ? [CheckResult::pass(sprintf(self::TABLE, (string) $source->table))]
            : [CheckResult::warn(sprintf(self::DIFFERENT, (string) $source->table), self::DIFFERENT_FIX)];
    }

    /** A connection's database, as DatabaseManager finds it (Arr::get(), and a url can set it), or null. */
    private function database(string $name): ?string
    {
        $connections = $this->config->get('database.connections');
        $settings = is_array($connections) ? Arr::get($connections, $name) : null;

        if (is_array($settings)) {
            $settings = array_filter($settings, is_string(...), ARRAY_FILTER_USE_KEY);
        } elseif (! is_string($settings)) {
            return null;
        }

        try {
            $database = (new ConfigurationUrlParser)->parseConfiguration($settings)['database'] ?? null;
        } catch (InvalidArgumentException) {
            return null;
        }

        return is_string($database) && $database !== '' ? $database : null;
    }
}
