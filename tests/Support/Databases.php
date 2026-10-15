<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * A database test is never skipped in silence in CI: it is skipped with the reason locally, and it
 * fails when RUNTIME_MCP_TEST_REQUIRE_DATABASES=1.
 */
final class Databases
{
    public static function requiresPostgres(): void
    {
        self::requires('testing_pgsql');
    }

    public static function requiresMysql(): void
    {
        self::requires('testing_mysql');
    }

    private static function requires(string $connection): void
    {
        try {
            DB::connection($connection)->getPdo();

            return;
        } catch (Throwable $e) {
            DB::purge($connection);
            $reason = sprintf(
                'Connection [%s] is not reachable: %s. Start the databases with '
                .'`docker compose up -d --wait` (see docker-compose.yml).',
                $connection,
                $e->getMessage(),
            );
        }

        if (getenv('RUNTIME_MCP_TEST_REQUIRE_DATABASES') === '1') {
            Assert::fail($reason);
        }

        Assert::markTestSkipped($reason);
    }
}
