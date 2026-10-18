<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Mcp;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;
use SinaSaeedi\RuntimeMcp\Mcp\Http\EnsureEndpointEnabled;
use SinaSaeedi\RuntimeMcp\Security\Auth\ApiKey;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthenticateApiKey;
use SinaSaeedi\RuntimeMcp\Security\Http\EnsureAllowedHostAndOrigin;

final class McpRoutes
{
    public const ROUTE_NAME = 'runtime-mcp';

    /** The STDIO handle: php artisan mcp:start runtime-mcp. */
    public const LOCAL_HANDLE = 'runtime-mcp';

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
            AuthenticateApiKey::class, // 01-35 replaces it with AuthenticateRequest
        ];
    }

    /** Null when runtime-mcp.path is not a non-empty string. */
    public function path(): ?string
    {
        $path = $this->config->get('runtime-mcp.path');

        return is_string($path) && trim($path, '/ ') !== '' ? $path : null;
    }

    /**
     * Registers the STDIO server, then the HTTP route. A bad path or middleware entry registers no
     * route (fail closed); those are HTTP settings, so STDIO is registered before that check.
     */
    public function register(): void
    {
        // Spec 5.3: unset (or not a valid name) = the STDIO server does not start. 01-10 adds its flag.
        if (ApiKey::isValidName($this->config->get('runtime-mcp.auth.stdio_principal'))) {
            Mcp::local(self::LOCAL_HANDLE, McpServer::class);
        }

        $middleware = $this->middleware();
        $path = $this->path();

        if ($middleware === null || $path === null) {
            return;
        }

        Route::middleware($middleware)->group(function () use ($path): void {
            Mcp::web($path, McpServer::class)
                ->name(self::ROUTE_NAME)
                ->withoutMiddleware(AddWwwAuthenticateHeader::class);
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
