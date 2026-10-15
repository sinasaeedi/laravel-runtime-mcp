<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Mcp\Http;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The kill switch: a cached route still answers 404 when the endpoint is off.
 */
final class EnsureEndpointEnabled
{
    public function __construct(private readonly Repository $config) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->config->get('runtime-mcp.enabled') !== true) {
            abort(404);
        }

        return $next($request);
    }
}
