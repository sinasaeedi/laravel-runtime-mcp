<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Auth;

use Illuminate\Http\JsonResponse;

/**
 * Spec 5.3: the one 401 for every rejection, whatever the reason. The reason goes to the audit only,
 * so a caller cannot probe which keys or names exist. 01-35 returns the very same response.
 *
 * We write WWW-Authenticate ourselves: laravel/mcp's header can point a client to the app's own
 * OAuth server (spec 3.2), and McpRoutes removes that middleware from our route.
 */
final class Unauthenticated
{
    public const BODY = ['message' => 'Unauthenticated.'];

    public const HEADER = 'Bearer realm="runtime-mcp", error="invalid_token"';

    public static function response(): JsonResponse
    {
        return new JsonResponse(self::BODY, 401, ['WWW-Authenticate' => self::HEADER]);
    }
}
