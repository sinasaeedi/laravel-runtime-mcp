<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth;

use Illuminate\Foundation\Auth\User;
use Laravel\Sanctum\HasApiTokens;

/**
 * The app's user model in the Sanctum tests (01-35): tests set auth.providers.users.model to it
 * (Tokens::config()). Testbench's own Illuminate\Foundation\Auth\User cannot own tokens.
 */
final class TokenUser extends User
{
    use HasApiTokens;

    protected $table = 'users';
}
