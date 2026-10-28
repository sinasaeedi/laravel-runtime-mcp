<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Boot\Rules;

use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootRule;
use SinaSaeedi\RuntimeMcp\Security\Environment;

/**
 * Spec 4.6, 5.11: the environment name is empty or only spaces (APP_ENV= in .env). The server name
 * would be "MyApp ()", so the agent could not tell this server from the others.
 */
final class EnvironmentName implements BootRule
{
    public const ID = 'environment.name';

    public function __construct(private readonly Environment $environment) {}

    public function id(): string
    {
        return self::ID;
    }

    public function check(): array
    {
        if (trim($this->environment->name()) !== '') {
            return [];
        }

        return [BootIssue::error(self::ID, 'The environment name is empty. Set APP_ENV, for example to `production`, so the server name tells the agent which server it is on.')];
    }
}
