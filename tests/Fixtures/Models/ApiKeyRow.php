<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/** 01-21: its primary key column, api_key, matches the default mask `*api_key*`, so it is unreadable. */
final class ApiKeyRow extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'key_rows';

    protected $primaryKey = 'api_key';
}
