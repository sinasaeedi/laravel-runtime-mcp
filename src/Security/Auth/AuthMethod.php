<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Auth;

/**
 * How the principal was established. The value is the audit's auth_method (spec 5.7). A case is never renamed.
 *
 * Testing: the test kit set the principal directly, inside the app's tests only (spec 7.5, 01-39). The audit of a test
 * call must not look like a real key or token.
 */
enum AuthMethod: string
{
    case ApiKey = 'api_key';
    case Stdio = 'stdio';
    case Sanctum = 'sanctum';
    case Testing = 'testing';
}
