<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/** 01-21 Part E: $visible leaves ssn out, so only nickname (encrypted, visible) warns. */
final class VisibleProfile extends Model
{
    protected $table = 'profiles';

    protected $visible = ['id', 'nickname'];

    protected $casts = ['ssn' => 'encrypted', 'nickname' => 'encrypted'];
}
