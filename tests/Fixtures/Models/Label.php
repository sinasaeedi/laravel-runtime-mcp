<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/** 01-21: the labels table. */
final class Label extends Model
{
    public $timestamps = false;

    protected $table = 'labels';

    protected $guarded = [];
}
