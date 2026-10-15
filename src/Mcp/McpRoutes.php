<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Mcp;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;
use SinaSaeedi\RuntimeMcp\Mcp\Http\EnsureEndpointEnabled;

final class McpRoutes
{
    public const ROUTE_NAME = 'runtime-mcp';

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
            // 01-07 inserts EnsureAllowedHostAndOrigin here
            ...$configured,
            // 01-06 inserts AuthenticateApiKey here
        ];
    }

    /** Null when runtime-mcp.path is not a non-empty string. */
    public function path(): ?string
    {
        $path = $this->config->get('runtime-mcp.path');

        return is_string($path) && trim($path, '/ ') !== '' ? $path : null;
    }

    /** Registers the HTTP route. A bad path or middleware entry registers nothing (fail closed). */
    public function register(): void
    {
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
