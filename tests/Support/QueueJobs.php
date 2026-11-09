<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's jobs table (its queue migration), for the database queue driver (01-22). Rows go in with plain
 * inserts: a push would resolve the queue connection, and the tests check which connections are resolved.
 */
final class QueueJobs
{
    /** @param  array<string, int>  $jobs  the number of rows per queue name */
    public static function create(?string $connection = null, string $table = 'jobs', array $jobs = []): void
    {
        $schema = Schema::connection($connection);
        $schema->dropIfExists($table);
        $schema->create($table, function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        foreach ($jobs as $queue => $count) {
            for ($i = 0; $i < $count; $i++) {
                DB::connection($connection)->table($table)->insert([
                    'queue' => $queue, 'payload' => '{}', 'attempts' => 0, 'available_at' => 0, 'created_at' => 0,
                ]);
            }
        }
    }

    public static function drop(?string $connection, string ...$tables): void
    {
        foreach ($tables as $table) {
            Schema::connection($connection)->dropIfExists($table);
        }
    }
}
