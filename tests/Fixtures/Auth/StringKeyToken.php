<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth;

use Laravel\Sanctum\PersonalAccessToken;

/** An app's token model with a string key (a UUID, for example): Sanctum skips the digit rule (01-35). */
final class StringKeyToken extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    protected $keyType = 'string';

    public $incrementing = false;
}
