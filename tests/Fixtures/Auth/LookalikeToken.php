<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth;

use Illuminate\Database\Eloquent\Model;

/**
 * A model with Sanctum's findToken() shape that is not a PersonalAccessToken. An app could set it with
 * Sanctum::usePersonalAccessTokenModel(); the Sanctum driver refuses it before any lookup (01-35).
 */
final class LookalikeToken extends Model
{
    public static function findToken(string $token): self
    {
        return new self;
    }
}
