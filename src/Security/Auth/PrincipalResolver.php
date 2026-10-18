<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Auth;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Config\Repository;
use SinaSaeedi\RuntimeMcp\Principal;
use Throwable;

/**
 * Spec 5.3: builds the Principal for a key name, with the linked User when auth.link_users links it.
 *
 * Fail closed: a linked name whose user cannot be found, a link that is not a non-empty string, and
 * a link_users value that is not an array (or null) all throw LinkedUserMissing. 01-39 calls it with
 * AuthMethod::Testing, so link_users works there as in production.
 */
final class PrincipalResolver
{
    public function __construct(
        private readonly Repository $config,
        private readonly AuthManager $auth,
    ) {}

    /** @throws LinkedUserMissing */
    public function resolve(string $name, AuthMethod $method): Principal
    {
        $links = $this->config->get('runtime-mcp.auth.link_users');

        if ($links === null) {
            return new Principal($name, $method);
        }

        if (! is_array($links)) {
            throw new LinkedUserMissing($name);
        }

        if (! array_key_exists($name, $links)) {
            return new Principal($name, $method);
        }

        $email = $links[$name];

        // Never look a user up with an empty email: a linked name with no usable email is a bad link.
        if (! is_string($email) || trim($email) === '') {
            throw new LinkedUserMissing($name);
        }

        try {
            $user = $this->provider()?->retrieveByCredentials(['email' => $email]);
        } catch (Throwable $e) {
            // An unknown provider driver, a missing users table: closed, and doctor (01-12) says why.
            throw new LinkedUserMissing($name, $e);
        }

        if ($user === null) {
            throw new LinkedUserMissing($name);
        }

        return new Principal($name, $method, $user);
    }

    /** The app's own user provider: the one of its default guard, or its default provider. */
    private function provider(): ?UserProvider
    {
        $guard = $this->config->get('auth.defaults.guard');
        $provider = is_string($guard) ? $this->config->get("auth.guards.{$guard}.provider") : null;

        return $this->auth->createUserProvider(is_string($provider) ? $provider : null);
    }
}
