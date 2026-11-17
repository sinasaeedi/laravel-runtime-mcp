<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Auth;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use SinaSaeedi\RuntimeMcp\Mcp\McpRoutes;
use SinaSaeedi\RuntimeMcp\Security\Environment;
use SinaSaeedi\RuntimeMcp\Server\RuntimeServer;

/**
 * Spec 4.6, 7.2, 7.2a (01-27): what a developer needs to add this server to an MCP client. The same for an API key
 * (runtime-mcp:key) and a Sanctum token (runtime-mcp:token), so both commands name the server the same way.
 *
 * Reads config only. Never URL::to() or route(): the route does not exist while the endpoint is off (the default),
 * and in console the URL generator may not follow app.url (Testbench: http://localhost). Prints, never runs: the
 * inspector runs on the developer's machine (rule 4).
 */
final class ClientSetup
{
    public const PLACEHOLDER_ORIGIN = 'https://<your-host>';

    public const DEFAULT_PATH = '/mcp';

    public const APP_URL_WARNING = 'APP_URL is not set; replace <your-host> with the address of this server.';

    public const PATH_WARNING = 'runtime-mcp.path is not valid, so the endpoint has no route. Run php artisan runtime-mcp:doctor.';

    public const ENDPOINT_WARNING = 'The endpoint is off on this server (RUNTIME_MCP_ENABLED is not true). Set it before you connect.';

    /** Characters that need no quotes in a POSIX shell, PowerShell or cmd. Anything else is single-quoted. */
    private const BARE = '/^[A-Za-z0-9._:\/-]+$/D';

    /** Characters that stay literal inside double quotes in a POSIX shell (no $, `, \, ", !). */
    private const DOUBLE_QUOTABLE = '/^[A-Za-z0-9._~+\/=|:-]+$/D';

    public function __construct(
        private readonly Repository $config,
        private readonly Environment $environment,
        private readonly RuntimeServer $server,
        private readonly McpRoutes $routes,
    ) {}

    /** "<app name> (<environment>)", as the MCP server names itself (spec 4.6, 01-02). */
    public function serverLabel(): string
    {
        return $this->server->name();
    }

    public function environment(): string
    {
        return $this->environment->name();
    }

    /**
     * slug(app name) + "-" + slug(environment), for example "myapp-staging" (spec 4.6). Str::slug() gives only a-z,
     * 0-9 and single "-" (Str::ascii() drops a letter it cannot convert), which every MCP client accepts. An empty
     * part (an app name of only symbols, or of letters with no ASCII form) becomes "app" or "env".
     */
    public function serverName(): string
    {
        return self::slug($this->server->appName(), 'app').'-'.self::slug($this->environment->name(), 'env');
    }

    /** APP_URL (or the placeholder) + the route path with one leading "/", for example https://staging.example.com/mcp. */
    public function url(): string
    {
        $base = $this->config->get('app.url');
        $base = is_string($base) && trim($base) !== '' ? rtrim(trim($base), '/') : self::PLACEHOLDER_ORIGIN;
        $path = $this->routes->path();

        return $base.'/'.($path === null ? trim(self::DEFAULT_PATH, '/') : trim($path, '/'));
    }

    /** Spec 7.2: the Claude Code command, with the environment in the server name. */
    public function clientCommand(string $secret): string
    {
        return 'claude mcp add --transport http '.$this->serverName().' '.self::shellWord($this->url())
            .' --header '.self::headerWord('Authorization: Bearer '.$secret);
    }

    /** Spec 7.2: how to check the endpoint with the MCP Inspector, from the developer's own machine. */
    public function inspectorHint(string $secret): string
    {
        return '→ Transport: Streamable HTTP · URL: '.$this->url().' · Header: Authorization: Bearer '.$secret;
    }

    /** @return list<string> what stops this setup from working on this server, as config shows it */
    public function warnings(): array
    {
        $warnings = [];
        $base = $this->config->get('app.url');

        if (! is_string($base) || trim($base) === '') {
            $warnings[] = self::APP_URL_WARNING;
        }

        if ($this->routes->path() === null) {
            $warnings[] = self::PATH_WARNING;
        }

        if ($this->config->get('runtime-mcp.enabled') !== true) {
            $warnings[] = self::ENDPOINT_WARNING;
        }

        return $warnings;
    }

    private static function slug(string $text, string $fallback): string
    {
        $slug = Str::slug($text);

        return $slug === '' ? $fallback : $slug;
    }

    /** As it is when every character is safe bare, else in single quotes (a ' becomes '\''). */
    private static function shellWord(string $word): string
    {
        return preg_match(self::BARE, $word) === 1 ? $word : self::singleQuoted($word);
    }

    /** In double quotes, as spec 7.2 prints it, unless a character is special there: then in single quotes. */
    private static function headerWord(string $header): string
    {
        $value = substr($header, strlen('Authorization: Bearer '));

        return preg_match(self::DOUBLE_QUOTABLE, $value) === 1 ? '"'.$header.'"' : self::singleQuoted($header);
    }

    private static function singleQuoted(string $word): string
    {
        return "'".str_replace("'", "'\\''", $word)."'";
    }
}
