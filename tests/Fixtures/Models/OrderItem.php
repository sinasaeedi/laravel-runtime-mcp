<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/** 01-21: 01-11's order_items. */
final class OrderItem extends Model
{
    public $timestamps = false;

    protected $table = 'order_items';

    protected $guarded = [];

    protected $casts = ['quantity' => 'integer'];
}
