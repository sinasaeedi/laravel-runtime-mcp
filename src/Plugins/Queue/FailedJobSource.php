<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Queue;

use Illuminate\Contracts\Config\Repository;

/**
 * Spec 6.6, 6.8, 01-23 Part A: where the failed jobs are, by the same choice that Laravel's
 * QueueServiceProvider::registerFailedJobServices() makes (checked in Laravel 12.69 and 13.36), in its order:
 *
 *   driver key present and null or 'null'  → none (NullFailedJobProvider)
 *   driver 'file'                          → file (FileFailedJobProvider)
 *   driver 'dynamodb'                      → none: an AWS call (Sina, 2026-11-06, the line of 01-22's sqs rule)
 *   driver 'database-uuids'                → table, ID column uuid (DatabaseUuidFailedJobProvider)
 *   any other driver or no driver, a table → table, ID column id (DatabaseFailedJobProvider)
 *   no table                               → none (NullFailedJobProvider)
 *
 * Config only: QueuePlugin::requiresReadOnlyConnection() uses it in boot validation, which never touches the
 * database (spec 5.11). It never resolves queue.failer: building the DynamoDB provider creates an AWS client.
 */
final readonly class FailedJobSource
{
    public const TABLE = 'table';

    public const FILE = 'file';

    public const NONE = 'none';

    /** A table name the notes may show (01-12: a config value is printed only when it has the shape of a name). */
    public const TABLE_PATTERN = '/^[A-Za-z0-9_$]{1,64}(\.[A-Za-z0-9_$]{1,64})?$/D';

    public const NOT_AN_ARRAY = 'queue.failed in config/queue.php is not a list of settings, so the failed jobs cannot be found.';

    public const NULL_DRIVER = 'Failed jobs are not stored (queue.failed.driver is null).';

    public const NO_TABLE = 'Failed jobs are not stored (queue.failed has no driver and no table).';

    public const BAD_TABLE = 'queue.failed.table in config/queue.php is not a table name, so the failed jobs cannot be found.';

    public const DYNAMODB = 'Failed jobs are stored in DynamoDB. Reading them would call AWS; v1.0.0 does not read DynamoDB.';

    /**
     * @param  self::TABLE|self::FILE|self::NONE  $kind
     * @param  string|null  $table  the table name (kind table)
     * @param  'uuid'|'id'|null  $idColumn  the column that holds the job's ID (kind table)
     * @param  string|null  $path  queue.failed.path when it is a non-empty string (kind file; null = Laravel's default)
     * @param  string|null  $note  why there is nothing to read (kind none)
     * @param  bool  $dynamoDb  kind none because the driver is dynamodb (doctor says so)
     */
    private function __construct(
        public string $kind,
        public ?string $table = null,
        public ?string $idColumn = null,
        public ?string $path = null,
        public ?string $note = null,
        public bool $dynamoDb = false,
    ) {}

    public static function fromConfig(Repository $config): self
    {
        $failed = $config->get('queue.failed');

        if (! is_array($failed)) {
            return self::none(self::NOT_AN_ARRAY);
        }

        $driver = $failed['driver'] ?? null;

        if (array_key_exists('driver', $failed) && ($driver === null || $driver === 'null')) {
            return self::none(self::NULL_DRIVER);
        }

        if ($driver === 'file') {
            $path = $failed['path'] ?? null;

            return new self(self::FILE, path: is_string($path) && $path !== '' ? $path : null);
        }

        if ($driver === 'dynamodb') {
            return new self(self::NONE, note: self::DYNAMODB, dynamoDb: true);
        }

        // Laravel: database-uuids reads $config['table'] directly; every other driver needs isset($config['table']).
        if ($driver !== 'database-uuids' && ! isset($failed['table'])) {
            return self::none(self::NO_TABLE);
        }

        $table = $failed['table'] ?? null;

        if (! is_string($table) || preg_match(self::TABLE_PATTERN, $table) !== 1) {
            return self::none(self::BAD_TABLE);
        }

        return new self(self::TABLE, table: $table, idColumn: $driver === 'database-uuids' ? 'uuid' : 'id');
    }

    private static function none(string $note): self
    {
        return new self(self::NONE, note: $note);
    }
}
