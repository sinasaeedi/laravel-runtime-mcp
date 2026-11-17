<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use SinaSaeedi\RuntimeMcp\Security\Auth\ClientSetup;
use SinaSaeedi\RuntimeMcp\Security\Auth\KeyIssuer;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Spec 5.3, 7.2 (01-27): a new API key for one developer, shown once, with the .env value, the client command, a
 * Gate example and the inspector hint. 01-28 (install) runs it for the first developer.
 *
 * It prints only: no file, no log line, no event of its own (rule 6, spec 5.3 "shows it once"). Never pass this
 * output through the Redactor, as DoctorCommand does: the key is "rmcp_…" and would become a marker.
 */
final class KeyCommand extends Command
{
    public const NAME = 'runtime-mcp:key';

    public const SANCTUM_DRIVER = 'The auth driver is `sanctum`. Use php artisan runtime-mcp:token <email>, or set the driver to `api_key` first.';

    public const OTHER_DRIVER = 'runtime-mcp.auth.driver is not `api_key`, so no key can work. Run php artisan runtime-mcp:doctor.';

    public const CONFIG_CACHE = 'If you use config:cache, run it again after you edit .env.';

    /** @var string */
    protected $signature = self::NAME.' {name : The developer\'s key name, for example sina}';

    /** @var string */
    protected $description = 'Create a Runtime MCP API key, show it once, and print the .env value and the client command';

    public function handle(KeyIssuer $issuer, ClientSetup $client, Repository $config): int
    {
        $driver = $config->get('runtime-mcp.auth.driver');

        // One driver per server (spec 5.3): with any other driver the key could never authenticate.
        if ($driver !== 'api_key') {
            $this->components->error(OutputFormatter::escape($driver === 'sanctum' ? self::SANCTUM_DRIVER : self::OTHER_DRIVER));

            return self::FAILURE;
        }

        $name = $this->argument('name');

        try {
            $issued = $issuer->issue(is_string($name) ? $name : '');
        } catch (InvalidArgumentException $e) {
            $this->components->error(OutputFormatter::escape($e->getMessage()));

            return self::FAILURE;
        }

        $environment = $client->environment();

        $this->newLine();
        $this->text(" Key for \"{$issued->name}\" on {$client->serverLabel()} — shown once. Store it in your MCP client or password manager.");
        $this->newLine();
        $this->text("   {$issued->key}");
        $this->newLine();
        $this->text(" 1. Set this in the {$environment} server's .env (the server keeps only the hash):");
        $this->newLine();
        $this->text("   RUNTIME_MCP_API_KEYS={$issued->envValue}");
        $this->newLine();
        $this->text('   '.self::CONFIG_CACHE);
        $this->newLine();
        $this->text(' 2. Add the server to Claude Code:');
        $this->newLine();
        $this->text('   '.$client->clientCommand($issued->key));
        $this->newLine();
        $this->text(' 3. Define a Gate for each tool this key may use, for example in AppServiceProvider::boot():');
        $this->newLine();
        $this->text("   Gate::define('runtime-mcp.app_info', fn (Principal|User \$who) => true); // Principal: SinaSaeedi\\RuntimeMcp\\Principal");
        $this->newLine();
        $this->text(' 4. Optional: check the endpoint from your own machine with the MCP Inspector:');
        $this->newLine();
        $this->text('   npx @modelcontextprotocol/inspector');
        $this->text('   '.$client->inspectorHint($issued->key));
        $this->newLine();

        foreach ([...$issued->warnings, ...$client->warnings()] as $warning) {
            $this->components->warn(OutputFormatter::escape($warning));
        }

        return self::SUCCESS;
    }

    /** Every printed value can come from the config (APP_NAME, APP_URL): a "<…>" in it is text, not a style tag. */
    private function text(string $line): void
    {
        $this->line(OutputFormatter::escape($line));
    }
}
