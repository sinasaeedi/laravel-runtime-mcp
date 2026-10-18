<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Auth;

use RuntimeException;
use Throwable;

/**
 * auth.link_users links a key name, but no user can be found for it (fail closed, spec 1.4.3).
 * The message names the key only: never the email, never a provider message.
 */
final class LinkedUserMissing extends RuntimeException
{
    public function __construct(public readonly string $name, ?Throwable $previous = null)
    {
        parent::__construct("Key [{$name}] is linked to a user that cannot be found.", 0, $previous);
    }
}
