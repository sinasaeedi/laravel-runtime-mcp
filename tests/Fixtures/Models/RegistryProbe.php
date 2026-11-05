<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models;

use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notifiable;

/**
 * 01-21 Part A: one real relation, and the method shapes the registry refuses. ModelsCheck also reads
 * notARelation() (not a Relation) and broken() (throws). The Laravel traits give methods that write, prune,
 * send mail or are relations: the registry refuses each of them by name (01-21 triage).
 */
final class RegistryProbe extends Model
{
    use MassPrunable, MustVerifyEmail, Notifiable, SoftDeletes;

    protected $table = 'probes';

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    /**
     * An app method that wraps Notifiable's relation: the app's own, so it may be allow-listed.
     *
     * @return MorphMany<DatabaseNotification, $this>
     */
    public function inbox(): MorphMany
    {
        return $this->notifications();
    }

    public function notARelation(): string
    {
        return 'no';
    }

    public function broken(): never
    {
        throw new \RuntimeException('The relation method failed.');
    }

    /** @return HasMany<OrderItem, $this> */
    public function withArgument(string $column): HasMany
    {
        return $this->hasMany(OrderItem::class, $column);
    }

    /** @return HasMany<OrderItem, self> */
    public static function staticOne(): HasMany
    {
        return (new self)->hasMany(OrderItem::class);
    }

    /** @return HasMany<OrderItem, $this> */
    protected function hiddenOne(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
