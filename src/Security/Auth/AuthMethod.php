<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Auth;

/**
 * How the principal was established. The value is the audit's auth_method (spec 5.7).
 * 01-39 adds Testing = 'testing'. A case is never renamed.
 */
enum AuthMethod: string
{
    case ApiKey = 'api_key';
    case Stdio = 'stdio';
    case Sanctum = 'sanctum';
}
