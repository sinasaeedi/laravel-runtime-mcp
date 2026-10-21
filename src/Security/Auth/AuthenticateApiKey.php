<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Auth;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use SinaSaeedi\RuntimeMcp\Audit\Reason;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec 5.3: only a request with a key whose hash is in this server's RUNTIME_MCP_API_KEYS reaches
 * laravel/mcp. Every rejection writes one warning audit entry, dispatches AuthFailed (spec 5.7), and
 * gets the same 401 (AuthRejection).
 *
 * AuthenticateRequest (01-35) runs it for the api_key driver only.
 */
final class AuthenticateApiKey
{
    public function __construct(
        private readonly Repository $config,
        private readonly AuthRejection $rejection,
        private readonly CurrentPrincipal $principals,
        private readonly PrincipalResolver $resolver,
    ) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $credential = BearerCredential::fromRequest($request);

        if ($credential->reason !== null) {
            return $this->rejection->respond($request, $credential->reason, AuthMethod::ApiKey, $credential->details);
        }

        $name = ApiKeyRing::fromConfig($this->config)->match($credential->token);

        if ($name === null) {
            return $this->rejection->respond($request, Reason::UnknownKey, AuthMethod::ApiKey, $credential->details);
        }

        try {
            $principal = $this->resolver->resolve($name, AuthMethod::ApiKey);
        } catch (LinkedUserMissing) {
            return $this->rejection->respond($request, Reason::LinkedUserMissing, AuthMethod::ApiKey, $credential->details);
        }

        $this->principals->set($principal);

        return $next($request);
    }
}
