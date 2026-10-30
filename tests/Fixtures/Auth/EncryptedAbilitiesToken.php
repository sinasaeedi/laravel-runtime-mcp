<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth;

use Laravel\Sanctum\PersonalAccessToken;

/** An app's token model that encrypts the abilities: a row it cannot decrypt throws on read (01-35). */
final class EncryptedAbilitiesToken extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    protected $casts = [
        'abilities' => 'encrypted:array',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
