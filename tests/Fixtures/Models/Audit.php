<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/** 01-21: the audits table. */
final class Audit extends Model
{
    public $timestamps = false;

    protected $table = 'audits';

    protected $guarded = [];
}
