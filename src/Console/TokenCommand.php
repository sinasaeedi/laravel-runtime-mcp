<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\Sanctum as SanctumCheck;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthenticateSanctumToken;
use SinaSaeedi\RuntimeMcp\Security\Auth\ClientSetup;
use SinaSaeedi\RuntimeMcp\Security\Auth\UserModel;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

/**
 * Spec 5.3, 7.2a, rule 21 (01-27): a Sanctum token for one user, with exactly the ability `runtime-mcp` and an
 * expiry, shown once, with the same client command and inspector hint as runtime-mcp:key.
 *
 * It refuses, before it writes a row, every case in which AuthenticateSanctumToken (01-35) would reject the token:
 * another driver, no Sanctum, a user model that cannot own tokens, a token model or a connection that the driver does
 * not read, a broken sanctum.expiration. It writes one row
 * (the token) and nothing else: no log line, no file. The token is never passed through the Redactor.
 *
 * Names laravel/sanctum, so SanctumIsolationTest lists this file. Registered also when Sanctum is not installed:
 * the imports load nothing until handle() runs, and the class_exists() check comes first.
 */
final class TokenCommand extends Command
{
    public const NAME = 'runtime-mcp:token';

    public const MAX_DAYS = 3650;

    public const API_KEY_DRIVER = 'The auth driver is `api_key`. Use php artisan runtime-mcp:key <name>, or set the driver to `sanctum` first.';

    public const OTHER_DRIVER = 'runtime-mcp.auth.driver is not `sanctum`, so no token can work. Run php artisan runtime-mcp:doctor.';

    public const NOT_INSTALLED = 'laravel/sanctum is not installed. Run composer require laravel/sanctum.';

    public const BAD_EXPIRATION = 'sanctum.expiration is not a number of minutes, so every token is rejected. Set it to null or a whole number.';

    public const BAD_DAYS = '--days must be a whole number from 0 to 3650 (0 = no expiry).';

    public const BAD_NAME = '--name must be 1 to 255 characters.';

    public const NO_MODEL = 'The default guard\'s user provider is not an eloquent provider, so a token can have no owner. Use an eloquent provider in config/auth.php.';

    public const TOKEN_MODEL = 'The Sanctum token model is not a Laravel\\Sanctum\\PersonalAccessToken, so every token is rejected. Check Sanctum::usePersonalAccessTokenModel().';

    public const CONNECTIONS = 'The user model and the Sanctum token model use different database connections, and one of them names none, so the token could never work. Name the connection in both models, or use one connection.';

    public const NO_USER = 'No user has this email.';

    public const NOT_NEW_TOKEN = 'The user model\'s createToken() did not return a Laravel\\Sanctum\\NewAccessToken, so the token cannot be shown. Revoke the row it may have saved by its name.';

    public const NEVER_EXPIRES = 'This token never expires. Delete it when the developer leaves.';

    /** @var string */
    protected $signature = self::NAME.' {email : The email of the user who owns the token}'
        .' {--name=runtime-mcp : The token name (the docs revoke tokens by this name)}'
        .' {--days=90 : Days until the token expires; 0 = no expiry}';

    /** @var string */
    protected $description = 'Create a Runtime MCP Sanctum token for a user, show it once, and print the client command';

    /** @param  string  $sanctum  the class that must exist for Sanctum (a test names a missing one) */
    public function __construct(private readonly string $sanctum = Sanctum::class)
    {
        parent::__construct();
    }

    public function handle(Repository $config, ClientSetup $client): int
    {
        $driver = $config->get('runtime-mcp.auth.driver');

        if ($driver !== 'sanctum') {
            return $this->refuse($driver === 'api_key' ? self::API_KEY_DRIVER : self::OTHER_DRIVER);
        }

        if (! class_exists($this->sanctum)) {
            return $this->refuse(self::NOT_INSTALLED);
        }

        // AuthenticateSanctumToken::fresh() applies it to every token: null, 0, '0' or '' = none, else whole minutes.
        $expiration = $config->get('sanctum.expiration');
        $minutes = match (true) {
            $expiration === null, $expiration === 0, $expiration === '0', $expiration === '' => 0,
            is_int($expiration) && $expiration > 0 => $expiration,
            is_string($expiration) && ctype_digit($expiration) => (int) $expiration,
            default => null,
        };

        if ($minutes === null) {
            return $this->refuse(self::BAD_EXPIRATION);
        }

        $days = $this->option('days');

        if (! is_string($days) || ! ctype_digit($days) || (int) $days > self::MAX_DAYS) {
            return $this->refuse(self::BAD_DAYS);
        }

        $name = $this->option('name');

        if (! is_string($name) || trim($name) === '' || mb_strlen($name) > 255) {
            return $this->refuse(self::BAD_NAME);
        }

        $model = UserModel::fromConfig($config);

        if ($model === null) {
            return $this->refuse(self::NO_MODEL);
        }

        // The rule of Doctor\Checks\Sanctum, plus what AuthenticateSanctumToken::owner() needs of the owner.
        if (! class_exists($model)
            || ! is_a($model, Model::class, true)
            || ! is_a($model, Authenticatable::class, true)
            || ! in_array(HasApiTokens::class, class_uses_recursive($model), true)) {
            $shown = preg_match(SanctumCheck::CLASS_NAME, $model) === 1 ? "`{$model}`" : 'The user model';

            return $this->refuse("{$shown} cannot own a token: it must be an Eloquent model that is Authenticatable and uses Laravel\\Sanctum\\HasApiTokens.");
        }

        // AuthenticateSanctumToken::handle() rejects every token of another token model.
        $tokens = self::tokenModel();

        if (! is_string($tokens) || ! is_a($tokens, PersonalAccessToken::class, true)) {
            return $this->refuse(self::TOKEN_MODEL);
        }

        try {
            $users = new $model;
            $tokenModel = new $tokens;
            $usersOn = $users->getConnection()->getName();
            $tokensOn = $tokenModel->getConnection()->getName();
        } catch (Throwable $e) {
            return $this->refuse('The database connection of the user model or the token model is not valid ('.$e::class.').');
        }

        // The driver reads the token on the token model's connection and its owner on the user model's (01-35). But
        // createToken() writes the token on the owner's connection when the token model names none, and morphTo
        // loads the owner on the token's when the user model names none: on two connections, both must name theirs.
        if ($usersOn !== $tokensOn && ($users->getConnectionName() === null || $tokenModel->getConnectionName() === null)) {
            return $this->refuse(self::CONNECTIONS);
        }

        $email = $this->argument('email');
        $email = is_string($email) ? $email : '';

        try {
            // The user model's own query (01-35): the owner is then an instance of it, in its table.
            $user = $model::query()->where('email', $email)->first();
        } catch (Throwable $e) {
            // The class only: a QueryException message holds the SQL and its bindings.
            return $this->refuse('The user lookup failed ('.$e::class.').');
        }

        if (! $user instanceof $model || ! method_exists($user, 'createToken')) {
            return $this->refuse(self::NO_USER);
        }

        $expiresAt = (int) $days === 0 ? null : Carbon::now()->addDays((int) $days);

        try {
            // Rule 21: exactly this one ability, never Sanctum's default ['*'].
            $new = $user->createToken($name, [AuthenticateSanctumToken::ABILITY], $expiresAt);
        } catch (Throwable $e) {
            return $this->refuse('The token could not be saved ('.$e::class.'). Check that the personal_access_tokens table exists.');
        }

        if (! $new instanceof NewAccessToken) {
            return $this->refuse(self::NOT_NEW_TOKEN);
        }

        $token = $new->plainTextToken;

        $this->newLine();
        $this->text(" Token for \"{$email}\" on {$client->serverLabel()} — shown once. Store it in your MCP client or password manager.");
        $this->newLine();
        $this->text("   {$token}");
        $this->newLine();
        $this->text(' 1. Add the server to Claude Code:');
        $this->newLine();
        $this->text('   '.$client->clientCommand($token));
        $this->newLine();
        $this->text(' 2. Define a Gate for each tool this user may use, for example in AppServiceProvider::boot():');
        $this->newLine();
        $this->text("   Gate::define('runtime-mcp.app_info', fn (User \$user) => true);");
        $this->newLine();
        $this->text(' 3. Optional: check the endpoint from your own machine with the MCP Inspector:');
        $this->newLine();
        $this->text('   npx @modelcontextprotocol/inspector');
        $this->text('   '.$client->inspectorHint($token));
        $this->newLine();
        $this->text($expiresAt === null
            ? ' The token is stored in this server\'s database.'
            : " The token is stored in this server's database and expires on {$expiresAt->toDateString()} (".(int) $days.' days).');
        $this->text(' Revoke it with: $user->tokens()->where(\'name\', '.var_export($name, true).')->delete()');
        $this->newLine();

        $warnings = $client->warnings();

        if ($minutes > 0) {
            array_unshift($warnings, "Sanctum's sanctum.expiration also applies: this token stops working {$minutes} minutes after it was created.");
        } elseif ($expiresAt === null) {
            array_unshift($warnings, self::NEVER_EXPIRES);
        }

        foreach ($warnings as $warning) {
            $this->components->warn(OutputFormatter::escape($warning));
        }

        return self::SUCCESS;
    }

    /**
     * Sanctum::personalAccessTokenModel(), as the app configured it, as AuthenticateSanctumToken reads it.
     * Typed mixed: Sanctum does not check the value, so neither may PHPStan assume it.
     */
    private static function tokenModel(): mixed
    {
        return Sanctum::personalAccessTokenModel();
    }

    private function refuse(string $message): int
    {
        $this->components->error(OutputFormatter::escape($message));

        return self::FAILURE;
    }

    /** The email, the token name and the URL can hold "<…>": text, not a style tag. */
    private function text(string $line): void
    {
        $this->line(OutputFormatter::escape($line));
    }
}
