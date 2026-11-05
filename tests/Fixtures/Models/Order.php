<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * 01-21: the `order` alias on 01-11's orders table (plus internal_note, shipped_on and deleted_at). It has
 * one relation of each kind that starts at an order, two on their own connections, one that is not
 * allow-listed but eager-loaded by default ($with), one of a class Laravel does not ship, and one whose
 * key column is masked. The table has created_at but no updated_at.
 *
 * @property int $id
 * @property OrderStatus $status
 * @property string $total
 */
final class Order extends Model
{
    use SoftDeletes;

    protected $table = 'orders';

    protected $guarded = [];

    /** Not allow-listed: model_show must never query audits. */
    protected $with = ['audits'];

    /** Only display_total is allow-listed; margin throws if it runs. */
    protected $appends = ['display_total', 'margin'];

    protected $hidden = ['internal_note', 'deleted_at'];

    protected $casts = [
        'status' => OrderStatus::class,
        'total' => 'decimal:2',
        'shipped_on' => 'date',
    ];

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'order_tag')->withPivot('note');
    }

    /** @return MorphMany<Note, $this> */
    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'notable');
    }

    /** @return MorphOne<Note, $this> */
    public function latestNote(): MorphOne
    {
        return $this->morphOne(Note::class, 'notable')->latest('id');
    }

    /** @return MorphToMany<Label, $this> */
    public function labels(): MorphToMany
    {
        return $this->morphToMany(Label::class, 'labelable');
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return HasMany<Ledger, $this> */
    public function ledger(): HasMany
    {
        return $this->hasMany(Ledger::class);
    }

    /** @return HasMany<Audit, $this> */
    public function audits(): HasMany
    {
        return $this->hasMany(Audit::class);
    }

    /**
     * sessions is in denied_tables.
     *
     * @return HasMany<SessionRow, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(SessionRow::class, 'user_id', 'user_id');
    }

    /** @return CustomHasMany<OrderItem, $this> */
    public function custom(): CustomHasMany
    {
        return new CustomHasMany((new OrderItem)->newQuery(), $this, 'order_items.order_id', 'id');
    }

    /**
     * Matches on users.api_token, which masked_columns hides.
     *
     * @return BelongsTo<User, $this>
     */
    public function customerByToken(): BelongsTo
    {
        return $this->belongsTo(User::class, 'number', 'api_token');
    }

    public function getDisplayTotalAttribute(): string
    {
        return 'EUR '.$this->total;
    }

    public function getMarginAttribute(): never
    {
        throw new RuntimeException('margin is not allow-listed, so it must not run.');
    }

    /** An app format: model_show must still give ISO 8601 with an offset. */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i');
    }
}
