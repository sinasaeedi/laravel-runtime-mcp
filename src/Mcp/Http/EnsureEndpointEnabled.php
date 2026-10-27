<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Mcp\Http;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootState;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The kill switch: a cached route still answers 404 when the endpoint is off, or when boot
 * validation did not allow the HTTP endpoint (01-10). With route:cache the route exists even though
 * McpRoutes::register() skipped it, so this is the second lock.
 */
final class EnsureEndpointEnabled
{
    public function __construct(
        private readonly Repository $config,
        private readonly BootState $boot,
    ) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->config->get('runtime-mcp.enabled') !== true || ! $this->boot->httpAllowed()) {
            // The router's own exception and message (AbstractRouteCollection), so a closed endpoint
            // answers exactly as a path with no route. abort(404) sends an empty message instead.
            throw new NotFoundHttpException(sprintf('The route %s could not be found.', $request->path()));
        }

        return $next($request);
    }
}
