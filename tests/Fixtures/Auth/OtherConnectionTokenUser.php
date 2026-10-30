<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth;

/** A subclass of the user model on another connection: the same table name, other ids (01-35 re-review). */
final class OtherConnectionTokenUser extends BaseTokenUser
{
    public const CONNECTION = 'token_users_other';

    protected $connection = self::CONNECTION;
}
