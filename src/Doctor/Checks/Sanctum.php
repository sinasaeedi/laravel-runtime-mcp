<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Doctor\Checks;

use Composer\InstalledVersions;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\Sanctum as SanctumConfig;
use SinaSaeedi\RuntimeMcp\Doctor\Check;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Security\Auth\UserModel;
use SinaSaeedi\RuntimeMcp\Security\Boot\Rules\SanctumInstalled;
use SinaSaeedi\RuntimeMcp\Security\Grants\DeniedTables;
use SinaSaeedi\RuntimeMcp\Security\Grants\InvalidGrantConfig;
use Throwable;

/**
 * Spec 5.3, 7.3 (01-35): the sanctum driver. Only for that driver: the app's user model can own tokens,
 * and the read-only role is denied the token table. A missing laravel/sanctum is the
 * auth.sanctum.installed boot rule, which the Boot rules check prints, so here it is only a warning.
 *
 * Prints a class or a table name only when it has the shape of one (01-12).
 */
final class Sanctum implements Check
{
    private const CLASS_NAME = '/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D';

    private const TABLE_NAME = '/^[A-Za-z0-9_.]{1,64}$/D';

    /** @param  string  $package  the container uses the default; a test names a package that is not installed */
    public function __construct(
        private readonly Repository $config,
        private readonly string $package = SanctumInstalled::PACKAGE,
    ) {}

    public function title(): string
    {
        return 'Sanctum';
    }

    public function run(): array
    {
        if ($this->config->get('runtime-mcp.auth.driver') !== 'sanctum') {
            return [CheckResult::pass('Not used: auth.driver is not `sanctum`.')];
        }

        if (! InstalledVersions::isInstalled($this->package) || ! class_exists(SanctumConfig::class)) {
            return [CheckResult::warn(
                'Not checked: laravel/sanctum is not installed (see Boot rules).',
                'Run composer require laravel/sanctum.',
            )];
        }

        return [$this->ownerModel(), $this->tokenTable()];
    }

    private function ownerModel(): CheckResult
    {
        $model = UserModel::fromConfig($this->config);

        if ($model === null) {
            return CheckResult::fail(
                'The user provider of the default guard has no model, so no token can have an owner, and every token is rejected.',
                'Use an eloquent user provider for the default guard in config/auth.php.',
            );
        }

        $shown = preg_match(self::CLASS_NAME, $model) === 1 ? "`{$model}`" : 'The user model';

        if (! class_exists($model) || ! in_array(HasApiTokens::class, class_uses_recursive($model), true)) {
            return CheckResult::fail(
                "{$shown} cannot own tokens, so every token is rejected.",
                'Add the Laravel\Sanctum\HasApiTokens trait to the user model.',
            );
        }

        return CheckResult::pass("{$shown} can own tokens.");
    }

    /** As AuthenticateSanctumToken: the app's value, unchecked by Sanctum. */
    private static function tokenModel(): mixed
    {
        return SanctumConfig::personalAccessTokenModel();
    }

    private function tokenTable(): CheckResult
    {
        $model = self::tokenModel();
        $instance = is_string($model) && class_exists($model) ? new $model : null;
        $table = $instance instanceof Model ? $instance->getTable() : 'personal_access_tokens';
        $named = preg_match(self::TABLE_NAME, $table) === 1;
        $shown = $named ? "`{$table}`" : 'The token table';

        try {
            $denied = DeniedTables::fromConfig($this->config)->contains(
                ...self::schemaAndTable($instance instanceof Model ? $instance : null, $table),
            );
        } catch (InvalidGrantConfig) {
            return CheckResult::warn(
                'Not checked: database.denied_tables is not an array (see Mask lists).',
                'Set runtime-mcp.database.denied_tables to a list of table names.',
            );
        }

        if ($denied) {
            return CheckResult::pass("{$shown} is in denied_tables.");
        }

        return CheckResult::warn(
            "{$shown} is not in denied_tables, so the read-only role may read token rows.",
            'Put '.($named ? $table : 'the token table').' back in runtime-mcp.database.denied_tables.',
        );
    }

    /**
     * The token table as denied_tables matches it: "schema.table" splits, and a plain name gets the
     * default schema of the model's connection (Laravel's own rule, read from the config, no query), so
     * a "public.personal_access_tokens" entry matches on PostgreSQL. Without a model: no schema.
     *
     * @return array{string, string}
     */
    private static function schemaAndTable(?Model $instance, string $table): array
    {
        if ($instance === null) {
            return ['', $table];
        }

        try {
            [$schema, $name] = $instance->getConnection()->getSchemaBuilder()->parseSchemaAndTable($table, true);
        } catch (Throwable) {
            // An unknown connection, or a three-part name: match it as it is.
            return ['', $table];
        }

        return [$schema ?? '', $name];
    }
}
