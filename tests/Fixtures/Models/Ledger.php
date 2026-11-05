<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/** 01-21: a model on a connection this server does not configure: building a relation to it throws. */
final class Ledger extends Model
{
    public $timestamps = false;

    protected $connection = 'nowhere';

    protected $table = 'ledgers';
}
