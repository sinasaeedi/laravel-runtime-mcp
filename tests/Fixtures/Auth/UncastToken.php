<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth;

use Laravel\Sanctum\PersonalAccessToken;

/** An app's token model without the expires_at cast: the value is a plain string (01-35). */
final class UncastToken extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    protected $casts = ['abilities' => 'json'];
}
