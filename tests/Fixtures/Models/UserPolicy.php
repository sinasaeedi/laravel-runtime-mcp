<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use SinaSaeedi\RuntimeMcp\Principal;

/** 01-21 (Q2): a related record passes its own Policy. The user named "Blocked" is denied. */
final class UserPolicy
{
    public function view(Principal|Authenticatable $who, User $user): bool
    {
        return $user->name !== 'Blocked';
    }
}
