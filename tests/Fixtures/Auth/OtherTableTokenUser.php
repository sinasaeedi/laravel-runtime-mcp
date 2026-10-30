<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth;

/** A subclass of the user model on its own table: its ids are not the users' ids (01-35 re-review). */
final class OtherTableTokenUser extends BaseTokenUser
{
    protected $table = 'token_admins';
}
