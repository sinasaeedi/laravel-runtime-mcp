<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/** 01-21: the tags table. */
final class Tag extends Model
{
    public $timestamps = false;

    protected $table = 'tags';

    protected $guarded = [];
}
