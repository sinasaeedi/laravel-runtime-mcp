<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Auth;

use Illuminate\Contracts\Config\Repository;

/**
 * Spec 5.3 (01-35): the app's user model, the one that may own a runtime-mcp Sanctum token. It is the
 * model of the default guard's provider (or of auth.defaults.provider), as PrincipalResolver and
 * AuthManager::createUserProvider() find the provider. Only an `eloquent` provider has a model.
 *
 * Reads config only. The Sanctum driver rejects every token while this is null; doctor says why.
 */
final class UserModel
{
    public static function fromConfig(Repository $config): ?string
    {
        $guard = $config->get('auth.defaults.guard');
        $provider = is_string($guard) ? $config->get("auth.guards.{$guard}.provider") : null;
        $provider = is_string($provider) ? $provider : $config->get('auth.defaults.provider');

        if (! is_string($provider) || $config->get("auth.providers.{$provider}.driver") !== 'eloquent') {
            return null;
        }

        $model = $config->get("auth.providers.{$provider}.model");

        return is_string($model) && $model !== '' ? $model : null;
    }
}
