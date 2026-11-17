<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth;

use Illuminate\Auth\GenericUser;
use Laravel\Sanctum\HasApiTokens;

/**
 * 01-27 TC6: Authenticatable and with HasApiTokens, but not an Eloquent model, so it has no query() and no table.
 * runtime-mcp:token refuses it before the lookup (its Model check).
 */
final class GenericTokenOwner extends GenericUser
{
    use HasApiTokens;
}
