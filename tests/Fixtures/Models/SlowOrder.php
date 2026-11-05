<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 01-21: a global scope that runs longer than the statement timeout (Q1: the timeout covers Eloquent too).
 * MySQL and MariaDB: BENCHMARK() over a column, not a constant. MariaDB computes a constant condition of a
 * prepared statement before max_statement_time applies, so BENCHMARK(…, md5('a')) ran to its end (probed).
 */
final class SlowOrder extends Model
{
    public $timestamps = false;

    protected $table = 'orders';

    protected static function booted(): void
    {
        self::addGlobalScope('slow', function (Builder $query): void {
            // The model's connection, not the builder's: Builder::getConnection() applies the scopes again (endless recursion).
            $query->getModel()->getConnection()->getDriverName() === 'pgsql'
                ? $query->whereRaw('(select 1 from pg_sleep(2)) = 1')
                : $query->whereRaw('benchmark(1000000000, md5(orders.number)) = 0');
        });
    }
}
