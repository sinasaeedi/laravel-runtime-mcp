<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\ReadOnly;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * 01-16: app code that writes while a read tool only reads. Loading the model fires a `retrieved`
 * observer that inserts into ro_events; the views_seen accessor increments the views column. Both use
 * the default connection, as app code does.
 *
 * @property int $id
 * @property string $n
 * @property int $views
 */
final class ObservedOrder extends Model
{
    public $timestamps = false;

    protected $table = 'ro_orders';

    protected $guarded = [];

    protected static function booted(): void
    {
        self::retrieved(function (ObservedOrder $order): void {
            DB::table('ro_events')->insert(['what' => 'retrieved '.$order->id]);
        });
    }

    public function getViewsSeenAttribute(): int
    {
        $this->increment('views');

        return $this->views;
    }
}
