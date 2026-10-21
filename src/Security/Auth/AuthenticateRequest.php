<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Auth;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use SinaSaeedi\RuntimeMcp\Audit\Reason;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec 4.5, 5.3: the auth step of the route (McpRoutes). It picks the driver from
 * runtime-mcp.auth.driver on every request, so a cached route follows the current config: `api_key`
 * (AuthenticateApiKey, 01-06) or `sanctum` (AuthenticateSanctumToken). One driver per server.
 *
 * Any other value is refused with unsupported_auth_driver. Boot validation (01-10, the auth.driver and
 * auth.sanctum.installed rules) turns the endpoint off first; this branch keeps a config change after
 * boot closed.
 */
final class AuthenticateRequest
{
    /** @param  string  $sanctum  the class that must exist for the sanctum driver (a test names a missing one) */
    public function __construct(
        private readonly Repository $config,
        private readonly Container $container,
        private readonly AuthRejection $rejection,
        private readonly string $sanctum = Sanctum::class,
    ) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $driver = $this->config->get('runtime-mcp.auth.driver');

        if ($driver === 'api_key') {
            return $this->container->make(AuthenticateApiKey::class)->handle($request, $next);
        }

        if ($driver === 'sanctum' && class_exists($this->sanctum)) {
            return $this->container->make(AuthenticateSanctumToken::class)->handle($request, $next);
        }

        $details = is_string($driver) ? ['driver' => mb_substr($driver, 0, 20)] : [];

        return $this->rejection->respond($request, Reason::UnsupportedAuthDriver, null, $details);
    }
}
