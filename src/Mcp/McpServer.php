<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Mcp;

use Laravel\Mcp\Enums\CacheScope;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Cacheable;
use Laravel\Mcp\Server\Contracts\Transport;
use SinaSaeedi\RuntimeMcp\Server\RuntimeServer;

/**
 * The only subclass of laravel/mcp's Server. Built per request by Registrar::startServer().
 */
final class McpServer extends Server
{
    /** Spec rule 19: the only MCP methods this server answers. */
    public const METHODS = [
        'initialize', 'ping', 'server/discover', 'tools/list', 'tools/call', 'prompts/list', 'prompts/get',
    ];

    public function __construct(Transport $transport, RuntimeServer $definition)
    {
        parent::__construct($transport);

        $this->name = $definition->name();
        $this->version = $definition->version();
        $this->instructions = $definition->instructions();
        $this->tools = $definition->tools();
        $this->capabilities = [
            self::CAPABILITY_TOOLS => ['listChanged' => false],
            self::CAPABILITY_PROMPTS => ['listChanged' => false], // spec 5.14, 01-36 fills the list
        ];
        $this->methods = array_intersect_key($this->methods, array_flip(self::METHODS));
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
