<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Failed jobs for the 01-23 and 01-24 tests, written by Laravel's own provider (app('queue.failer')->log()), so the
 * payload, the exception text and failed_at have the real shapes. The payload is the shape Queue::createPayload()
 * writes for a queued job; its serialized command holds CUSTOMER_EMAIL, which no failed-job tool may return.
 */
final class FailedJobs
{
    public const CUSTOMER_EMAIL = 'ada.customer@example.com';

    public const TOOLS = ['queue_failed_jobs_list', 'queue_failed_jobs_summary'];

    /**
     * 01-11's read-only role on $privileged (testing_pgsql or testing_mysql), its fixture failed_jobs table emptied,
     * Laravel's provider writing there (queue.failed.database: Testbench's default is sqlite), and both tools allowed.
     * The default connection stays SQLite, as in every integration test.
     *
     * @param  array<string, mixed>  $config  more boot config; a later key wins (queue.failed.driver after queue.failed)
     * @param  (Closure(string): void)|null  $extend  runs on $privileged before the role is made (its grants follow the tables)
     */
    public static function serve(string $privileged, array $config = [], ?Closure $extend = null): ReadOnlyRole
    {
        $role = ReadOnlyRole::setUp(currentTest(), $privileged, [
            'runtime-mcp.enabled' => true,
            'cache.limiter' => 'array',
            'queue.failed' => ['driver' => 'database-uuids', 'database' => $privileged, 'table' => 'failed_jobs'],
            ...$config,
        ], extend: function (string $connection) use ($extend): void {
            // ReadOnlyRole::schema() seeds one failed job at the database's CURRENT_TIMESTAMP; these tests count their own.
            DB::connection($connection)->table('failed_jobs')->delete();

            if ($extend !== null) {
                $extend($connection);
            }
        });
        Gates::allow(...self::TOOLS);

        return $role;
    }

    public static function payload(string $class = 'App\\Jobs\\SendInvoice', ?string $uuid = null): string
    {
        $command = 'O:'.strlen($class).':"'.$class.'":1:{s:5:"email";s:'.strlen(self::CUSTOMER_EMAIL).':"'.self::CUSTOMER_EMAIL.'";}';

        return (string) json_encode([
            'uuid' => $uuid ?? (string) Str::uuid(),
            'displayName' => $class,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'maxTries' => 3,
            'maxExceptions' => null,
            'failOnTimeout' => false,
            'backoff' => null,
            'timeout' => 60,
            'retryUntil' => null,
            'data' => ['commandName' => $class, 'command' => $command],
            'attempts' => 3,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Logs one failure at $at (Laravel writes failed_at as Date::now()) and returns its ID as a string.
     */
    public static function log(string $class, Throwable $exception, string $at, string $queue = 'default', string $connection = 'redis', ?string $payload = null): string
    {
        // The test's own clock (travelTo()) comes back after the write.
        $now = Carbon::getTestNow();
        Carbon::setTestNow(CarbonImmutable::parse($at));

        try {
            $id = app(FailedJobProviderInterface::class)->log($connection, $queue, $payload ?? self::payload($class), $exception);
        } finally {
            Carbon::setTestNow($now);
        }

        // DatabaseFailedJobProvider returns the int id, the others the uuid.
        return (string) $id;
    }

    /** The first line of the exception's string form, as the tools show it (before any cut). */
    public static function firstLine(Throwable $exception): string
    {
        return explode("\n", (string) $exception, 2)[0];
    }
}
