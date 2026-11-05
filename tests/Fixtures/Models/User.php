<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * 01-21: the `customer` alias on 01-11's users table. The role reads id, name, email, created_at and
 * updated_at only. UserPolicy denies the user named "Blocked".
 *
 * @property string $name
 */
final class User extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasOne<Order, $this> */
    public function latestOrder(): HasOne
    {
        return $this->hasOne(Order::class)->latest('id');
    }

    /** @return HasManyThrough<OrderItem, Order, $this> */
    public function items(): HasManyThrough
    {
        return $this->hasManyThrough(OrderItem::class, Order::class);
    }

    /**
     * Matches on users.api_token, which masked_columns hides: the parent's key column is unreadable.
     *
     * @return HasMany<Order, $this>
     */
    public function byToken(): HasMany
    {
        return $this->hasMany(Order::class, 'number', 'api_token');
    }

    /** @return HasOneThrough<OrderItem, Order, $this> */
    public function firstItem(): HasOneThrough
    {
        return $this->hasOneThrough(OrderItem::class, Order::class)->oldest('order_items.id');
    }
}
