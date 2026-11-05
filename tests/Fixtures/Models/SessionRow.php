<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/** 01-21: the sessions table is in denied_tables, so the role cannot read it. */
final class SessionRow extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'sessions';
}
