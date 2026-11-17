<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * An app's token model on the connection of OtherConnectionTokenUser (01-27 triage). Sanctum writes and reads it there;
 * a token model on a connection that the user model does not name is the case runtime-mcp:token must refuse.
 */
final class OtherConnectionToken extends PersonalAccessToken
{
    protected $connection = OtherConnectionTokenUser::CONNECTION;

    protected $table = 'personal_access_tokens';
}
