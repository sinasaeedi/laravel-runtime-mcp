<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 01-21 triage: an allow-listed accessor whose own query PostgreSQL refuses with 22P02, after the lookup found
 * the record. That is a refused read, not "the key does not fit" (Q4 is about the lookup).
 */
final class RefusedAccessorOrder extends Model
{
    public $timestamps = false;

    protected $table = 'orders';

    public function getRefusedAttribute(): mixed
    {
        return $this->getConnection()->scalar("select 'abc'::integer");
    }
}
