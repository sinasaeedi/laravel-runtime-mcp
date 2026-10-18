<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Auth;

use SinaSaeedi\RuntimeMcp\Principal;

/**
 * Holds the principal of this request (spec 4.4 step 1, 5.13). Bound as scoped (rule 14): Octane
 * flushes scoped instances between requests, so a principal never leaks into the next request.
 *
 * Laravel's auth guards are never touched (Auth::setUser()): the principal is explicit.
 */
final class CurrentPrincipal
{
    private ?Principal $principal = null;

    public function set(Principal $principal): void
    {
        $this->principal = $principal;
    }

    public function get(): ?Principal
    {
        return $this->principal;
    }
}
