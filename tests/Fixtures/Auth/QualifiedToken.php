<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth;

use Laravel\Sanctum\PersonalAccessToken;

/** An app's token model on a schema-qualified table, for doctor (01-35 re-review). */
final class QualifiedToken extends PersonalAccessToken
{
    protected $table = 'auth.tokens';
}
