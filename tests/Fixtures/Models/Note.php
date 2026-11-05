<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** 01-21: the `note` alias. Its MorphTo target comes from each row's notable_type. */
final class Note extends Model
{
    public $timestamps = false;

    protected $table = 'notes';

    protected $guarded = [];

    /** @return MorphTo<Model, $this> */
    public function notable(): MorphTo
    {
        return $this->morphTo();
    }
}
