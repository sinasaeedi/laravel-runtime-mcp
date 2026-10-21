<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth;

use Illuminate\Foundation\Auth\User;
use Laravel\Sanctum\HasApiTokens;

/**
 * Another model that can own Sanctum tokens, as an app's API clients or teams often can. It is not the
 * app's user model, so its token never opens the endpoint (01-35, Sina's Q2).
 */
final class OtherTokenOwner extends User
{
    use HasApiTokens;

    protected $table = 'other_token_owners';
}
