<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Mcp\Methods;

use Generator;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\ToolInvoker;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use SinaSaeedi\RuntimeMcp\Audit\AuditEntry;
use SinaSaeedi\RuntimeMcp\Audit\AuditLogger;
use SinaSaeedi\RuntimeMcp\Audit\AuditUnavailable;
use SinaSaeedi\RuntimeMcp\Audit\Reason;
use SinaSaeedi\RuntimeMcp\Mcp\ClientInfo;
use SinaSaeedi\RuntimeMcp\Mcp\McpToolAdapters;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthMethod;
use SinaSaeedi\RuntimeMcp\Security\Auth\CurrentPrincipal;
use SinaSaeedi\RuntimeMcp\Security\Environment;
use SinaSaeedi\RuntimeMcp\Server\RuntimeServer;
use Throwable;

/**
 * Our tools/call handler (01-09), in place of laravel/mcp's Methods\CallTool. That one looks the
 * tool up in ServerContext::tools(), which drops every tool whose shouldRegister() is false, so a
 * call to a hidden tool failed with "Tool [x] not found." before the pipeline could deny and audit
 * it (spec 5.7). This one searches every registered adapter, hidden ones too, and invokes it through
 * laravel/mcp's ToolInvoker exactly as the original does; stage 2 then denies and audits.
 *
 * An unknown name gives laravel/mcp's own JSON-RPC error, and one denied audit entry: probing for
 * tool names is worth seeing.
 */
final class CallTool implements Errable, Method
{
    /** laravel/mcp's text and code (Methods\CallTool, ErrorCode::INVALID_PARAMS). */
    public const NOT_FOUND = 'Tool [%s] not found.';

    public const MISSING_NAME = 'Missing [name] parameter.';

    public const INVALID_PARAMS = -32602;

    /** The longest tool name kept in the audit entry: the longest valid name (spec 4.3). */
    public const MAX_AUDITED_NAME = 64;

    public function __construct(
        private readonly RuntimeServer $definition,
        private readonly Container $container,
        private readonly CurrentPrincipal $principals,
        private readonly Environment $environment,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return JsonRpcResponse|Generator<JsonRpcResponse>
     *
     * @throws JsonRpcException
     */
    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        $name = $request->get('name');

        if ($name === null) {
            throw new JsonRpcException(self::MISSING_NAME, self::INVALID_PARAMS, $request->id);
        }

        foreach (McpToolAdapters::fromDefinition($this->definition, $this->container) as $adapter) {
            if ($adapter->name() === $name) {
                return (new ToolInvoker)->invoke($adapter, $request);
            }
        }

        // A name that is not a string is shown by its type, never cast ("Array to string conversion").
        $shown = is_string($name) ? $name : get_debug_type($name);
        $this->auditUnknown($shown, $request);

        throw new JsonRpcException(sprintf(self::NOT_FOUND, $shown), self::INVALID_PARAMS, $request->id);
    }

    /**
     * One denied tool_call entry, filled like the audit stage fills it. A failed write is reported and
     * the error still goes out: the error holds no data, so there is nothing to withhold.
     */
    private function auditUnknown(string $name, JsonRpcRequest $request): void
    {
        $principal = $this->principals->get();
        $arguments = $request->get('arguments');

        $entry = AuditEntry::toolCall(
            environment: $this->environment->name(),
            tool: mb_substr($name, 0, self::MAX_AUDITED_NAME),
            params: is_array($arguments) ? $arguments : [], // raw: AuditLogger redacts and caps them
            allowed: false,
            reason: Reason::UnknownTool,
            principal: $principal?->name,
            userId: $principal?->userId(),
            authMethod: $principal?->method->value,
            ip: $principal?->method === AuthMethod::Stdio ? null : $this->container->make(Request::class)->ip(),
            client: ClientInfo::from($request->meta()),
        );

        try {
            $this->audit->toolCall($entry);
        } catch (AuditUnavailable $e) {
            $this->report($e);
        }
    }

    private function report(AuditUnavailable $e): void
    {
        try {
            $this->container->make(ExceptionHandler::class)->report($e);
        } catch (Throwable) {
            // Nothing left to tell. The not-found error still goes out.
        }
    }
}
