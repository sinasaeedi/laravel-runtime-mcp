<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Mcp;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Laravel\Mcp\Enums\CacheScope;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Cacheable;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Tools\ToolSearch;
use Laravel\Mcp\Server\Transport\StdioTransport;
use LogicException;
use SinaSaeedi\RuntimeMcp\Security\Auth\ApiKey;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthMethod;
use SinaSaeedi\RuntimeMcp\Security\Auth\CurrentPrincipal;
use SinaSaeedi\RuntimeMcp\Security\Auth\PrincipalResolver;
use SinaSaeedi\RuntimeMcp\Server\RuntimeServer;
use SinaSaeedi\RuntimeMcp\Tools\CatalogSetting;

/**
 * The only subclass of laravel/mcp's Server. Built per request by Registrar::startServer().
 */
final class McpServer extends Server
{
    /** Spec rule 19: the only MCP methods this server answers. */
    public const METHODS = [
        'initialize', 'ping', 'server/discover', 'tools/list', 'tools/call', 'prompts/list', 'prompts/get',
    ];

    public function __construct(
        Transport $transport,
        RuntimeServer $definition,
        Repository $config,
        PrincipalResolver $resolver,
        CurrentPrincipal $principals,
        Container $container,
    ) {
        parent::__construct($transport);

        // Spec 5.3: the STDIO server runs as auth.stdio_principal. Only the mcp:start process gets a
        // StdioTransport; over HTTP, AuthenticateRequest has set the principal before this runs.
        if ($transport instanceof StdioTransport) {
            $principals->set($resolver->resolve(self::stdioPrincipal($config), AuthMethod::Stdio));
        }

        $this->name = $definition->name();
        $this->version = $definition->version();
        $this->instructions = $definition->instructions();
        $tools = McpToolAdapters::fromDefinition($definition, $container);
        // Spec 4.2, 5.17 (01-40): only the operator's catalog switch puts the tools behind search_tools and
        // execute_tools. ServerContext::tools() then builds a ToolSearch with `new` and lists only the two meta-tools.
        $this->tools = (new CatalogSetting($config))->enabled() ? [ToolSearch::class => $tools] : $tools;
        $this->prompts = McpPromptAdapters::fromDefinition($definition, $container, McpToolAdapters::byName($tools));
        $this->capabilities = [
            self::CAPABILITY_TOOLS => ['listChanged' => false],
            self::CAPABILITY_PROMPTS => ['listChanged' => false], // spec 5.14 (01-36)
        ];
        $this->methods = array_intersect_key($this->methods, array_flip(self::METHODS));

        // Every tools/call reaches the pipeline, also for a hidden tool, and an unknown name is
        // audited (01-09). tools/list stays laravel/mcp's: it hides what shouldRegister() denies.
        $this->addMethod('tools/call', Methods\CallTool::class);

        // Every prompts/get is audited, also for a hidden or unknown prompt (spec 5.14, 01-36).
        // prompts/list stays laravel/mcp's: it hides what shouldRegister() denies.
        $this->addMethod('prompts/get', Methods\GetPrompt::class);
    }

    /** Defence in depth for a cached registration: never run STDIO without a valid principal name. */
    private static function stdioPrincipal(Repository $config): string
    {
        $name = $config->get('runtime-mcp.auth.stdio_principal');

        if (! ApiKey::isValidName($name)) {
            throw new LogicException('The STDIO server needs a valid auth.stdio_principal.');
        }

        return $name;
    }

    /**
     * Spec 5.5: the lists depend on the principal, so no client may share or keep them.
     *
     * @return array<string, Cacheable>
     */
    protected function cacheHints(): array
    {
        return array_fill_keys(self::CACHEABLE_METHODS, new Cacheable(ttlMs: 0, scope: CacheScope::Private));
    }
}
