<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth;

use Illuminate\Foundation\Auth\User;
use Laravel\Sanctum\HasApiTokens;

/**
 * An app's user model that other models extend (01-35 re-review). Not final: the owner rule must
 * tell a subclass on the same table from one on its own table or connection.
 */
class BaseTokenUser extends User
{
    use HasApiTokens;

    protected $table = 'users';
}
