<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * 01-21: loading it fires a `retrieved` observer that writes through the default connection (spec 5.9).
 *
 * @property int $id
 */
final class WatchedOrder extends Model
{
    public $timestamps = false;

    protected $table = 'orders';

    protected static function booted(): void
    {
        self::retrieved(function (WatchedOrder $order): void {
            DB::table('model_events')->insert(['what' => 'retrieved '.$order->id]);
        });
    }
}
