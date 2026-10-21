<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Boot\Rules;

use Illuminate\Contracts\Config\Repository;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootRule;

/**
 * Spec 4.5, 5.11: auth.driver is not `api_key` or `sanctum`. The message prints the value only when
 * it has the shape of a driver name (01-12): never an arbitrary config string.
 */
final class AuthDriver implements BootRule
{
    public const ID = 'auth.driver';

    public const SUPPORTED = ['api_key', 'sanctum'];

    public function __construct(private readonly Repository $config) {}

    public function id(): string
    {
        return self::ID;
    }

    public function check(): array
    {
        $driver = $this->config->get('runtime-mcp.auth.driver');

        if (in_array($driver, self::SUPPORTED, true)) {
            return [];
        }

        $shown = is_string($driver) && preg_match('/^[a-z0-9_]{1,32}$/D', $driver) === 1 ? "`{$driver}`" : 'not a driver name';
        $later = $driver === 'passport' ? '; `passport` arrives in v1.12.0.' : '.';

        return [BootIssue::error(self::ID, "auth.driver is {$shown}. This version supports `api_key` and `sanctum`{$later}")];
    }
}
