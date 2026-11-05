<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/** 01-21: a model on its own connection, as spec 6.2 describes. The tests configure `billing`. */
final class Invoice extends Model
{
    public $timestamps = false;

    protected $connection = 'billing';

    protected $table = 'invoices';

    protected $guarded = [];
}
