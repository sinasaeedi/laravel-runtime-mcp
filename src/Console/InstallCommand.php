<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Console;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use SinaSaeedi\RuntimeMcp\Plugins\Contracts\Plugin;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;
use SinaSaeedi\RuntimeMcp\Security\Auth\ApiKey;
use SinaSaeedi\RuntimeMcp\Security\Grants\InvalidGrantConfig;
use SinaSaeedi\RuntimeMcp\Security\Grants\RoleSqlGenerator;
use SinaSaeedi\RuntimeMcp\Security\Grants\SchemaSnapshot;
use SinaSaeedi\RuntimeMcp\Security\UnsupportedDriver;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

/**
 * Spec 4.6, 5.1, 7.1, 14 (01-28): the first command an operator runs. Five steps, each safe to run again: publish the
 * config, print the read-only role SQL and the connection for the app's real schema, create the first key (01-27),
 * suggest the plugins whose packages are installed, and list the next steps.
 *
 * It prints only. It never writes .env (rule 6), never runs the SQL, never connects as the read-only role, never defines
 * a Gate (01-09). The one file it writes is config/runtime-mcp.php, and only when it is not there. The SQL, the
 * connection and the env lines sit between fixed marker lines, so a script (01-33, 01-34) can cut them.
 */
final class InstallCommand extends Command
{
    public const NAME = 'runtime-mcp:install';

    /** The connection name in the printed snippet and in RUNTIME_MCP_DB_CONNECTION. */
    public const CONNECTION = 'runtime_mcp';

    public const FALLBACK_KEY_NAME = 'developer';

    public const SQL_BEGIN = '-- runtime-mcp:install role SQL begins';

    public const SQL_END = '-- runtime-mcp:install role SQL ends';

    public const CONNECTION_BEGIN = '// runtime-mcp:install connection begins';

    public const CONNECTION_END = '// runtime-mcp:install connection ends';

    public const ENV_BEGIN = '# runtime-mcp:install env lines begin';

    public const ENV_END = '# runtime-mcp:install env lines end';

    public const CONFIG_KEPT = 'config/runtime-mcp.php already exists; kept as is.';

    public const CONFIG_PUBLISHED = 'Published config/runtime-mcp.php.';

    public const CONFIG_FAILED = 'Could not publish config/runtime-mcp.php. Run php artisan vendor:publish --tag=runtime-mcp-config and check the rights of the config folder.';

    public const CONFIG_CACHED = 'Your config is cached. Run php artisan config:cache again after you edit the config or .env. A cache made before you installed this package has no runtime-mcp settings: then run php artisan config:clear, and run php artisan runtime-mcp:install again.';

    public const SCHEMA_FAILED = 'Could not read the tables of the default connection `%s` (%s). Check that the database is up and that the connection settings are right (php artisan db:show), then run php artisan runtime-mcp:install again.';

    public const NO_TABLES = 'The default connection has no tables yet. Run your migrations, then run php artisan runtime-mcp:install again for the grants.';

    public const PGSQL_HOST = '--host applies only to MySQL and MariaDB; a PostgreSQL role has no host. Limit the hosts in pg_hba.conf.';

    public const UNSUPPORTED = 'The read-only role SQL supports PostgreSQL, MySQL and MariaDB; this app uses %s.';

    public const DB_URL = 'Your default connection uses DB_URL. Set host, port and database in the snippet by hand, and do not add a url key: its user and password would replace the read-only ones.';

    public const OPTIONS = 'Your default connection sets PDO options (for example for TLS). Copy the ones the read-only connection needs into the snippet, but never PDO::ATTR_PERSISTENT.';

    public const READ_WRITE = 'Your default connection has read and write hosts. Give the read-only connection one host (a replica is fine); doctor warns about read and write keys.';

    public const POOLED = 'Your default connection goes through a transaction pooler (\'pooled\' or \'direct\'). Point the read-only connection at the direct host (DB_DIRECT_HOST) or at a session pooler: in transaction pooling the session reset after a query can reach another server session.';

    public const SANCTUM_KEY_STEP = 'The auth driver is `sanctum`: create a token for each developer with php artisan runtime-mcp:token <email>.';

    public const KEY_LATER = 'No key was created. Run php artisan runtime-mcp:key <name> for each developer.';

    public const KEY_CONFIRM = 'Create a key for the first developer now?';

    public const KEY_QUESTION = 'Key name';

    public const SANCTUM_HINT = '`laravel/sanctum` is installed. You can use Sanctum tokens instead of API keys: set \'driver\' => \'sanctum\' in runtime-mcp.auth, then run php artisan runtime-mcp:token <email>. See docs/auth-and-gates.md.';

    public const NO_PLUGIN = 'No optional plugin in this version matches your installed packages.';

    public const NEXT_SQL = 'Run the SQL above as the database owner, with a strong password in place of CHANGE_ME.';

    public const NEXT_CONNECTION = 'Add the runtime_mcp connection to config/database.php and the env lines to this server\'s .env.';

    public const NEXT_FIX_ROLE = 'Fix the problem shown in step 2, then run php artisan runtime-mcp:install again for the role SQL.';

    public const NEXT_UNSUPPORTED = 'Turn off the plugins named in step 2, or move the app to PostgreSQL, MySQL or MariaDB.';

    public const NEXT_ENABLED = 'Set RUNTIME_MCP_ENABLED=true in this server\'s .env.';

    public const NEXT_KEY_MADE = 'Set the RUNTIME_MCP_API_KEYS value from step 3 in this server\'s .env.';

    public const NEXT_KEY_LATER = 'Run php artisan runtime-mcp:key <name> for each developer, and set the RUNTIME_MCP_API_KEYS value it prints.';

    public const NEXT_TOKEN = 'Create a token for each developer with php artisan runtime-mcp:token <email>.';

    public const NEXT_GATES = 'Define a Gate for each tool you want to expose (see docs/auth-and-gates.md). Every tool is denied until a Gate allows it.';

    public const NEXT_DOCTOR = 'Run php artisan runtime-mcp:doctor. It must pass before you connect an agent.';

    public const NEXT_CONNECT_MADE = 'Run the claude mcp add command from step 3 on your own machine.';

    public const NEXT_CONNECT_LATER = 'Run the claude mcp add command that runtime-mcp:key or runtime-mcp:token prints, on your own machine.';

    /**
     * The connection keys the snippet copies, as literal values, from the default connection (01-11, 01-19, 01-23).
     * sslrootcert is the server's CA file; sslcert and sslkey are not copied: a client certificate names the owner.
     */
    private const COPIED = [
        'pgsql' => ['charset', 'prefix', 'search_path', 'sslmode', 'sslrootcert', 'timezone'],
        'mysql' => ['charset', 'collation', 'prefix', 'timezone'],
        'mariadb' => ['charset', 'collation', 'prefix', 'timezone'],
    ];

    /** @var string */
    protected $signature = self::NAME
        .' {--role='.RoleSqlGenerator::DEFAULT_ROLE.' : The read-only role (database user) to create}'
        .' {--host='.RoleSqlGenerator::DEFAULT_HOST.' : MySQL and MariaDB only: the host part of the user}'
        .' {--key-name= : The first developer\'s key name (no question is asked)}';

    /** @var string */
    protected $description = 'Publish the Runtime MCP config, print the read-only role SQL and connection, and create the first key';

    /**
     * @param  string  $sanctumPackage  the Composer package of step 3a (a test names one that is not installed)
     * @param  string  $ownerFunction  the function that names the script owner (a test names one that does not exist)
     */
    public function __construct(
        private readonly string $sanctumPackage = 'laravel/sanctum',
        private readonly string $ownerFunction = 'get_current_user',
    ) {
        parent::__construct();
    }

    public function handle(Repository $config, DatabaseManager $db, PluginRegistry $registry): int
    {
        $this->heading('Step 1 of 5: the config file');
        $failed = ! $this->publishConfig();

        $this->heading('Step 2 of 5: the read-only role');
        $role = self::stringOption($this->option('role'));
        $host = self::stringOption($this->option('host'));
        $roleStep = $this->roleSql($config, $db, $registry, $role, $host);
        $failed = $roleStep === 'failed' || $failed;

        $this->heading('Step 3 of 5: the first key');
        $keyStep = $this->firstKey($config);
        $failed = $keyStep === 'failed' || $failed;

        $this->heading('Step 4 of 5: plugins');
        $this->plugins($registry);

        $this->heading('Step 5 of 5: next steps');
        $this->nextSteps($roleStep, $keyStep);
        $this->newLine();

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The key name the question offers: the characters of the user name that a key name allows, at most 64. A name
     * that is still not valid (empty, or "rmcp_…") becomes "developer". get_current_user() is the owner of the
     * script file (artisan), not the shell user, and can be "".
     */
    public static function defaultKeyName(string $user): string
    {
        $name = substr(preg_replace('/[^A-Za-z0-9._-]+/', '', $user) ?? '', 0, 64);

        return ApiKey::isValidName($name) ? $name : self::FALLBACK_KEY_NAME;
    }

    /**
     * The runtime_mcp connection for config/database.php: env() calls with the stock DB_* names for the place of the
     * database (never config(): config is still loading inside config/database.php), the role as the default
     * username, and the copied settings of the default connection as literal values. Never url, read, write, sticky,
     * options, pooled or direct (Part A2).
     *
     * @param  array<array-key, mixed>  $default  the default connection's config
     * @return list<string>
     */
    public static function snippet(string $driver, string $role, array $default): array
    {
        $lines = [
            "'".self::CONNECTION."' => [",
            "    'driver' => ".var_export($driver, true).',',
            "    'host' => env('DB_HOST', '127.0.0.1'),",
            "    'port' => env('DB_PORT', '".($driver === 'pgsql' ? '5432' : '3306')."'),",
            "    'database' => env('DB_DATABASE', 'laravel'),",
            "    'username' => env('RUNTIME_MCP_DB_USERNAME', ".var_export($role, true).'),',
            "    'password' => env('RUNTIME_MCP_DB_PASSWORD'),",
        ];

        if ($driver !== 'pgsql') {
            $lines[] = "    'unix_socket' => env('DB_SOCKET', ''),";
        }

        $copied = [];

        foreach (self::COPIED[$driver] ?? [] as $key) {
            // An old pgsql connection names its search path `schema`: Laravel reads search_path ?? schema (01-19).
            $value = self::literal($key === 'search_path' ? ($default['search_path'] ?? $default['schema'] ?? null) : ($default[$key] ?? null));

            if ($value !== null) {
                $copied[] = "    '{$key}' => {$value},";
            }
        }

        if ($copied !== []) {
            $lines[] = '    // copied from your default connection:';
            $lines = [...$lines, ...$copied];
        }

        $lines[] = '],';

        return $lines;
    }

    private function publishConfig(): bool
    {
        $path = $this->laravel->configPath('runtime-mcp.php');

        if (is_file($path)) {
            $this->text(self::CONFIG_KEPT);
        } else {
            try {
                $this->callSilent('vendor:publish', ['--tag' => 'runtime-mcp-config']);
            } catch (Throwable) {
                // A config folder the user cannot write: copy() warns, and Laravel turns the warning into an
                // ErrorException. The check below says so; the message is not printed (it holds a path).
            }

            if (! is_file($path)) {
                $this->components->error(self::CONFIG_FAILED);

                return false;
            }

            $this->text(self::CONFIG_PUBLISHED);
        }

        if ($this->laravel instanceof CachesConfiguration && $this->laravel->configurationIsCached()) {
            $this->components->warn(self::CONFIG_CACHED);
        }

        return true;
    }

    /**
     * Config errors first (the generator reads the mask lists), then the schema, then the SQL for --role and --host.
     * Any error: no SQL and no snippet; the later steps still run.
     *
     * @return 'printed'|'unsupported'|'failed'
     */
    private function roleSql(Repository $config, DatabaseManager $db, PluginRegistry $registry, string $role, string $host): string
    {
        try {
            $generator = $this->laravel->make(RoleSqlGenerator::class);
        } catch (InvalidGrantConfig $e) {
            $this->components->error(OutputFormatter::escape($e->getMessage()));

            return 'failed';
        }

        $name = $db->getDefaultConnection();

        try {
            $snapshot = SchemaSnapshot::read($db->connection());
        } catch (UnsupportedDriver $e) {
            $this->unsupported($e->driver, $registry);

            return 'unsupported';
        } catch (Throwable $e) {
            // The class only: a QueryException message holds the SQL (01-27, 01-35).
            $this->components->error(OutputFormatter::escape(sprintf(self::SCHEMA_FAILED, $name, $e::class)));

            return 'failed';
        }

        try {
            $sql = $generator->forDriver($snapshot->driver, $snapshot, $role, $host);
        } catch (InvalidArgumentException $e) {
            $this->components->error(OutputFormatter::escape($e->getMessage()));

            return 'failed';
        }

        if ($snapshot->driver === 'pgsql' && $host !== RoleSqlGenerator::DEFAULT_HOST) {
            $this->components->warn(self::PGSQL_HOST);
        }

        if ($snapshot->tables === []) {
            $this->components->warn(self::NO_TABLES);
        }

        $this->text(self::SQL_BEGIN);

        foreach (explode("\n", rtrim($sql, "\n")) as $line) {
            $this->text($line);
        }

        $this->text(self::SQL_END);

        $default = $config->get('database.connections.'.$name);
        $default = is_array($default) ? $default : [];
        // The copied values come from the connection that read the schema: a url's query (sslmode, search_path) is
        // merged there, not in the config repository. The warnings below read the config as the operator wrote it.
        $live = $db->connection()->getConfig();
        $live = is_array($live) ? $live : $default;

        $this->newLine();
        $this->text('Add this connection to config/database.php, inside \'connections\':');
        $this->newLine();
        $this->text(self::CONNECTION_BEGIN);

        foreach (self::snippet($snapshot->driver, $role, $live) as $line) {
            $this->text($line);
        }

        $this->text(self::CONNECTION_END);
        $this->newLine();

        if (is_string($default['url'] ?? null) && trim($default['url']) !== '') {
            $this->components->warn(self::DB_URL);
        }

        if (is_array($default['options'] ?? null) && $default['options'] !== []) {
            $this->components->warn(self::OPTIONS);
        }

        if (isset($default['read']) || isset($default['write'])) {
            $this->components->warn(self::READ_WRITE);
        }

        // Laravel 13 marks a transaction pooler so: 'pooled' => true, or a 'direct' endpoint (spec 5.2: session pooling only).
        if (($default['pooled'] ?? false) === true || ! empty($default['direct'])) {
            $this->components->warn(self::POOLED);
        }

        $this->text('Add these lines to this server\'s .env:');
        $this->newLine();
        $this->text(self::ENV_BEGIN);
        $this->text('RUNTIME_MCP_DB_CONNECTION='.self::CONNECTION);
        $this->text('RUNTIME_MCP_DB_USERNAME='.$role);
        $this->text('# The password that replaces CHANGE_ME in the SQL above:');
        $this->text('RUNTIME_MCP_DB_PASSWORD=');
        $this->text(self::ENV_END);

        return 'printed';
    }

    /** The plugins that need the read-only connection, named as the ReadOnlyConnection boot rule names them (01-23). */
    private function unsupported(string $driver, PluginRegistry $registry): void
    {
        $names = array_values(array_map(
            fn (Plugin $plugin): string => $plugin->name(),
            array_filter($registry->active(), fn (Plugin $plugin): bool => $plugin->requiresReadOnlyConnection()),
        ));

        $text = sprintf(self::UNSUPPORTED, $driver);

        if ($names !== []) {
            $one = count($names) === 1;
            $text .= ' The '.Arr::join(array_map(fn (string $name): string => "`{$name}`", $names), ', ', ' and ')
                .($one ? ' plugin needs' : ' plugins need').' a read-only connection on one of them. Turn '
                .($one ? 'it' : 'them').' off in config/runtime-mcp.php ('
                .implode(', ', array_map(fn (string $name): string => "'{$name}' => false", $names))
                .'), or use PostgreSQL, MySQL or MariaDB.';
        }

        $this->components->warn(OutputFormatter::escape($text));
    }

    /** @return 'made'|'skipped'|'sanctum'|'failed' */
    private function firstKey(Repository $config): string
    {
        $driver = $config->get('runtime-mcp.auth.driver');

        // One driver per server (spec 5.3): runtime-mcp:key exits 1 with the sanctum driver.
        if ($driver === 'sanctum') {
            $this->text(self::SANCTUM_KEY_STEP);

            return 'sanctum';
        }

        $name = $this->option('key-name');

        if (! is_string($name)) {
            if (! $this->input->isInteractive() || ! $this->confirm(self::KEY_CONFIRM, true)) {
                $this->text(self::KEY_LATER);
                $this->sanctumHint($driver);

                return 'skipped';
            }

            $name = self::stringOption($this->ask(self::KEY_QUESTION, self::defaultKeyName($this->scriptOwner())));
        }

        // Its output is exactly runtime-mcp:key's (01-27); a refused name or another driver exits 1 there.
        $code = $this->call(KeyCommand::NAME, ['name' => $name]);
        $this->sanctumHint($driver);

        return $code === self::SUCCESS ? 'made' : 'failed';
    }

    /** The owner of artisan, or "" when the host turned the function off (disable_functions): then it does not exist. */
    private function scriptOwner(): string
    {
        if (! function_exists($this->ownerFunction)) {
            return '';
        }

        $owner = ($this->ownerFunction)();

        return is_string($owner) ? $owner : '';
    }

    /** Step 3a: only for the api_key driver, and only when Composer has laravel/sanctum (spec 5.3: the operator picks). */
    private function sanctumHint(mixed $driver): void
    {
        if ($driver === 'api_key' && InstalledVersions::isInstalled($this->sanctumPackage)) {
            $this->newLine();
            $this->text(self::SANCTUM_HINT);
        }
    }

    /** A plugin with a package, whose package is installed, that the operator has not turned on. Never a base plugin. */
    private function plugins(PluginRegistry $registry): void
    {
        $lines = [];

        foreach ($registry->all() as $plugin) {
            $package = $plugin->requiredPackage();

            if ($package === null || ! $registry->isInstalled($plugin) || $registry->isEnabled($plugin)) {
                continue;
            }

            $lines[] = "`{$package}` is installed. Enable its plugin with '{$plugin->name()}' => true in runtime-mcp.plugins.";
        }

        foreach ($lines === [] ? [self::NO_PLUGIN] : $lines as $line) {
            $this->text($line);
        }
    }

    /**
     * @param  'printed'|'unsupported'|'failed'  $roleStep
     * @param  'made'|'skipped'|'sanctum'|'failed'  $keyStep
     */
    private function nextSteps(string $roleStep, string $keyStep): void
    {
        $items = match ($roleStep) {
            'printed' => [self::NEXT_SQL, self::NEXT_CONNECTION],
            'unsupported' => [self::NEXT_UNSUPPORTED],
            'failed' => [self::NEXT_FIX_ROLE],
        };

        $items[] = self::NEXT_ENABLED;
        $items[] = match ($keyStep) {
            'made' => self::NEXT_KEY_MADE,
            'sanctum' => self::NEXT_TOKEN,
            'skipped', 'failed' => self::NEXT_KEY_LATER,
        };
        $items[] = self::NEXT_GATES;
        $items[] = self::NEXT_DOCTOR;
        $items[] = $keyStep === 'made' ? self::NEXT_CONNECT_MADE : self::NEXT_CONNECT_LATER;

        foreach ($items as $i => $item) {
            $this->text(($i + 1).'. '.$item);
        }
    }

    private function heading(string $text): void
    {
        $this->newLine();
        $this->line("<options=bold>{$text}</>");
        $this->newLine();
    }

    /** Table and column names come from the database, the app name and the prefix from the config: text, never a tag. */
    private function text(string $line): void
    {
        $this->line(OutputFormatter::escape($line));
    }

    private static function stringOption(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /** A string, or a list of strings (a PostgreSQL search_path), as PHP source; anything else is left out. */
    private static function literal(mixed $value): ?string
    {
        if (is_string($value)) {
            return var_export($value, true);
        }

        if (is_array($value) && $value !== [] && array_is_list($value) && array_filter($value, is_string(...)) === $value) {
            return '['.implode(', ', array_map(fn (string $item): string => var_export($item, true), $value)).']';
        }

        return null;
    }
}
