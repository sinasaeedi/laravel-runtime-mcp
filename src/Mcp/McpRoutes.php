<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Mcp;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;
use SinaSaeedi\RuntimeMcp\Mcp\Http\EnsureEndpointEnabled;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthenticateRequest;
use SinaSaeedi\RuntimeMcp\Security\Http\EnsureAllowedHostAndOrigin;

final class McpRoutes
{
    public const ROUTE_NAME = 'runtime-mcp';

    /** The STDIO handle: php artisan mcp:start runtime-mcp. */
    public const LOCAL_HANDLE = 'runtime-mcp';

    /**
     * laravel/mcp 1.2.0+ puts this on every Mcp::web() route. It allows only localhost, app.url and
     * mcp.allowed_origins, so it refuses an origin that runtime-mcp.allowed_origins allows (and
     * always refuses "null"). EnsureAllowedHostAndOrigin owns the Origin check (spec 5.4), so the
     * route drops it. A string, not ::class: the class does not exist in laravel/mcp 1.1.
     */
    public const LARAVEL_VALIDATE_ORIGIN = 'Laravel\Mcp\Server\Middleware\ValidateOrigin';

    public function __construct(private readonly Repository $config) {}

    /**
     * Group middleware, in execution order. It runs before laravel/mcp's route middleware.
     *
     * @return list<string>|null null when runtime-mcp.middleware is not a list of non-empty strings
     */
    public function middleware(): ?array
    {
        $configured = $this->configuredMiddleware();

        if ($configured === null) {
            return null;
        }

        return [
            EnsureEndpointEnabled::class,
            EnsureAllowedHostAndOrigin::class, // spec 5.4: before the operator's rules and auth
            ...$configured,
            AuthenticateRequest::class, // 01-35: picks the api_key or the sanctum driver per request
        ];
    }

    /** Null when runtime-mcp.path is not a non-empty string. */
    public function path(): ?string
    {
        $path = $this->config->get('runtime-mcp.path');

        return is_string($path) && trim($path, '/ ') !== '' ? $path : null;
    }

    /**
     * Registers the STDIO server, then the HTTP route, each only when boot validation allows it
     * (01-10: BootReport::stdioAllowed(), httpAllowed()). A bad path or middleware entry registers no
     * route (fail closed); those are HTTP settings, so STDIO is registered before that check.
     */
    public function register(bool $http, bool $stdio): void
    {
        if ($stdio) {
            Mcp::local(self::LOCAL_HANDLE, McpServer::class);
        }

        if (! $http) {
            return;
        }

        $middleware = $this->middleware();
        $path = $this->path();

        if ($middleware === null || $path === null) {
            return;
        }

        // The exclusions sit on the group, so they also reach the GET and DELETE routes of Mcp::web().
        Route::middleware($middleware)
            ->withoutMiddleware([AddWwwAuthenticateHeader::class, self::LARAVEL_VALIDATE_ORIGIN])
            ->group(function () use ($path): void {
                Mcp::web($path, McpServer::class)->name(self::ROUTE_NAME);
            });
    }

    /** @return list<string>|null */
    private function configuredMiddleware(): ?array
    {
        $configured = $this->config->get('runtime-mcp.middleware', []);

        if (! is_array($configured) || ! array_is_list($configured)) {
            return null;
        }

        $list = [];

        foreach ($configured as $entry) {
            if (! is_string($entry) || $entry === '') {
                return null;
            }

            $list[] = $entry;
        }

        return $list;
    }
}
