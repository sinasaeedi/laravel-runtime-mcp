<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth;

use Laravel\Sanctum\PersonalAccessToken;

/** An app's own token model on another table (Sanctum::usePersonalAccessTokenModel()), for doctor (01-35). */
final class CustomToken extends PersonalAccessToken
{
    protected $table = 'custom_tokens';
}
