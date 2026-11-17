<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * An app's token model that names the default connection of the tests (01-27 triage): with a user model that names
 * another connection, Sanctum writes and reads the token here and loads the owner on the user model's connection.
 */
final class DefaultConnectionToken extends PersonalAccessToken
{
    public const CONNECTION = 'testing';

    protected $connection = self::CONNECTION;

    protected $table = 'personal_access_tokens';
}
