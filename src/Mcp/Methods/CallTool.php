<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Mcp\Methods;

use Generator;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Http\Request;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\ToolInvoker;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use SinaSaeedi\RuntimeMcp\Audit\AuditEntry;
use SinaSaeedi\RuntimeMcp\Audit\AuditLogger;
use SinaSaeedi\RuntimeMcp\Audit\AuditUnavailable;
use SinaSaeedi\RuntimeMcp\Audit\Reason;
use SinaSaeedi\RuntimeMcp\Mcp\ClientInfo;
use SinaSaeedi\RuntimeMcp\Mcp\McpToolAdapter;
use SinaSaeedi\RuntimeMcp\Mcp\McpToolAdapters;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthMethod;
use SinaSaeedi\RuntimeMcp\Security\Auth\CurrentPrincipal;
use SinaSaeedi\RuntimeMcp\Security\Authorization\ToolGate;
use SinaSaeedi\RuntimeMcp\Security\Environment;
use SinaSaeedi\RuntimeMcp\Server\RuntimeServer;
use SinaSaeedi\RuntimeMcp\Tools\CatalogSetting;
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
 *
 * With the catalog on (spec 5.17, 01-40), search_tools and execute_tools are laravel/mcp's own tools, not adapters:
 * this handler audits them (the outer entry), and before execute_tools runs it writes a denied entry for each inner
 * name that laravel/mcp would refuse before our pipeline could see it (hidden or unknown). A direct call of a tool name
 * still works: the pipeline guards it.
 */
final class CallTool implements Errable, Method
{
    /** laravel/mcp's text and code (Methods\CallTool, ErrorCode::INVALID_PARAMS). */
    public const NOT_FOUND = 'Tool [%s] not found.';

    public const MISSING_NAME = 'Missing [name] parameter.';

    public const INVALID_PARAMS = -32602;

    /** The longest tool name kept in the audit entry: the longest valid name (spec 4.3). */
    public const MAX_AUDITED_NAME = 64;

    /** The names of laravel/mcp's two catalog meta-tools (SearchTools, ExecuteTools). The tools.reserved boot rule keeps them free (01-40). */
    public const SEARCH_TOOLS = 'search_tools';

    public const EXECUTE_TOOLS = 'execute_tools';

    public const META_TOOLS = [self::SEARCH_TOOLS, self::EXECUTE_TOOLS];

    /**
     * The rules that ExecuteTools::handle() checks before it runs any call (laravel/mcp v1.1.0 to v1.2.1,
     * src/Server/Tools/ExecuteTools.php), without its `max:` (the app's mcp.tool_search.max_tool_calls, added at run
     * time). A batch that fails one runs nothing, so it gets no pre-scan entry. Check them again on a laravel/mcp update.
     */
    public const BATCH_RULES = [
        'calls' => ['required', 'array', 'min:1'],
        'calls.*' => ['array:name,arguments'],
        'calls.*.name' => ['required', 'string', 'max:255'],
        'calls.*.arguments' => ['array'],
    ];

    public function __construct(
        private readonly RuntimeServer $definition,
        private readonly Container $container,
        private readonly CurrentPrincipal $principals,
        private readonly Environment $environment,
        private readonly AuditLogger $audit,
        private readonly ToolGate $gate,
        private readonly CatalogSetting $catalog,
        private readonly Factory $validation,
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

        $adapters = McpToolAdapters::fromDefinition($this->definition, $this->container);

        if (in_array($name, self::META_TOOLS, true) && $this->catalog->enabled()) {
            return $this->callMetaTool($name, $request, $context, $adapters);
        }

        foreach ($adapters as $adapter) {
            if ($adapter->name() === $name) {
                return (new ToolInvoker)->invoke($adapter, $request);
            }
        }

        // A name that is not a string is shown by its type, never cast ("Array to string conversion").
        $shown = is_string($name) ? $name : get_debug_type($name);
        $arguments = $request->get('arguments');
        $this->write($this->entry($shown, is_array($arguments) ? $arguments : [], $request, allowed: false, reason: Reason::UnknownTool));

        throw new JsonRpcException(sprintf(self::NOT_FOUND, $shown), self::INVALID_PARAMS, $request->id);
    }

    /**
     * search_tools or execute_tools (01-40 Part B): the pre-scan, the call through laravel/mcp's ToolInvoker, then the
     * outer audit entry. execute_tools answers with a Generator (ExecuteTools::handle() returns an array, and
     * ToolInvoker streams every iterable), but its inner calls have already run inside handle(): the Generator only
     * formats the messages. So the answer is collected here, audited, and sent again unchanged (SSE over HTTP, as
     * laravel/mcp sends it). Auditing inside the stream would run after the route middleware has returned.
     *
     * @param  list<McpToolAdapter>  $adapters
     * @return JsonRpcResponse|Generator<JsonRpcResponse>
     */
    private function callMetaTool(string $name, JsonRpcRequest $request, ServerContext $context, array $adapters): Generator|JsonRpcResponse
    {
        $started = hrtime(true);
        $arguments = $request->get('arguments');
        $arguments = is_array($arguments) ? $arguments : [];
        $calls = $name === self::EXECUTE_TOOLS && is_array($arguments['calls'] ?? null) && array_is_list($arguments['calls'])
            ? $arguments['calls']
            : null;

        if ($calls !== null && $this->batchRuns($arguments)) {
            $this->preScan($calls, $adapters, $request);
        }

        $tool = $context->tools()->first(fn (Tool $tool): bool => $tool->name() === $name);

        if (! $tool instanceof Tool) {
            throw new JsonRpcException(sprintf(self::NOT_FOUND, $name), self::INVALID_PARAMS, $request->id);
        }

        $answer = (new ToolInvoker)->invoke($tool, $request);
        $messages = [];

        foreach ($answer instanceof JsonRpcResponse ? [$answer] : $answer as $message) {
            if ($message instanceof JsonRpcResponse) {
                $messages[] = $message;
            }
        }

        $last = $messages === [] ? null : $messages[count($messages) - 1];
        $result = $last?->content['result'] ?? null;
        $result = is_array($result) ? $result : null;
        $text = data_get($result, 'content.0.text');

        $this->write($this->entry(
            $name,
            $name === self::EXECUTE_TOOLS ? ['names' => self::names($arguments['calls'] ?? null)] : $arguments,
            $request,
            allowed: true,
            failed: $result === null || data_get($result, 'isError') === true,
            durationMs: intdiv(hrtime(true) - $started, 1_000_000),
            outputBytes: is_string($text) ? strlen($text) : null,
            details: $name === self::EXECUTE_TOOLS ? ['inner_calls' => $calls === null ? 0 : count($calls)] : [],
        ));

        if ($answer instanceof JsonRpcResponse) {
            return $answer;
        }

        return (function () use ($messages): Generator {
            yield from $messages;
        })();
    }

    /**
     * BATCH_RULES, the list rule and the "arguments is an object" rule of ExecuteTools::handle(), with
     * mcp.tool_search.max_tool_calls read as ToolSearch reads it.
     *
     * @param  array<array-key, mixed>  $arguments
     */
    private function batchRuns(array $arguments): bool
    {
        $rules = self::BATCH_RULES;
        $rules['calls'][] = 'max:'.$this->catalog->toolSearchMaxToolCalls();

        if ($this->validation->make($arguments, $rules)->fails()) {
            return false;
        }

        foreach (is_array($arguments['calls']) ? $arguments['calls'] : [] as $call) {
            $inner = is_array($call) ? ($call['arguments'] ?? []) : [];

            if (is_array($inner) && $inner !== [] && array_is_list($inner)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Spec 5.17: one denied entry for each inner name that laravel/mcp refuses before ToolInvoker (ToolSearch::
     * invokeTool(): "not found in the catalog", "is not available"), also for a name after the point where the batch
     * stops: the agent asked for it. A visible tool gets no entry here: its pipeline writes one.
     *
     * @param  list<mixed>  $calls  checked by batchRuns(): each an array with a string name
     * @param  list<McpToolAdapter>  $adapters
     */
    private function preScan(array $calls, array $adapters, JsonRpcRequest $request): void
    {
        $byName = [];

        foreach ($adapters as $adapter) {
            $byName[$adapter->name()] ??= $adapter;
        }

        foreach ($calls as $call) {
            $name = is_array($call) && is_string($call['name'] ?? null) ? $call['name'] : null;

            if ($name === null) {
                continue;
            }

            $adapter = $byName[$name] ?? null;
            $arguments = $call['arguments'] ?? [];
            $arguments = is_array($arguments) ? $arguments : [];
            $reason = $adapter === null ? Reason::UnknownTool : $this->hiddenReason($adapter, $arguments, $request);

            if ($reason === null) {
                continue;
            }

            $this->write($this->entry($name, $arguments, $request, allowed: false, reason: $reason, details: ['via' => self::EXECUTE_TOOLS]));
        }
    }

    /**
     * Null when ToolSearch lets the call through; else why shouldRegister() hides it (the Gate first, as 01-38).
     * ToolSearch::invokeTool() asks eligibleForRegistration() with the inner call's Request bound as mcp.request (its
     * arguments, the parent's _meta), and a wrapped tool's own shouldRegister() may read it: so it is asked the same way
     * here, and the outer Request is put back after it (01-40 triage).
     *
     * @param  array<array-key, mixed>  $arguments
     */
    private function hiddenReason(McpToolAdapter $adapter, array $arguments, JsonRpcRequest $request): ?Reason
    {
        $inner = [];

        foreach ($arguments as $key => $value) {
            $inner[(string) $key] = $value;
        }

        $outer = $this->container->bound('mcp.request') ? $this->container->make('mcp.request') : null;
        $this->container->instance('mcp.request', new McpRequest($inner, $request->meta()));

        try {
            $visible = $adapter->eligibleForRegistration();
        } finally {
            if (is_object($outer)) {
                $this->container->instance('mcp.request', $outer);
            } else {
                $this->container->forgetInstance('mcp.request');
            }
        }

        if ($visible) {
            return null;
        }

        $decision = $this->gate->decide($this->principals->get(), $adapter->runtimeTool());

        return $decision->allowed ? Reason::NotAvailable : ($decision->reason ?? Reason::GateDenied);
    }

    /**
     * The inner names of a batch for the outer entry, never their arguments (rule 16). A name that is not a string is
     * shown by its type.
     *
     * @return list<string>
     */
    private static function names(mixed $calls): array
    {
        if (! is_array($calls) || ! array_is_list($calls)) {
            return [];
        }

        return array_map(function (mixed $call): string {
            $name = is_array($call) ? ($call['name'] ?? null) : null;

            return is_string($name) ? $name : get_debug_type($name);
        }, $calls);
    }

    /**
     * One tool_call entry, filled like the audit stage fills it.
     *
     * @param  array<array-key, mixed>  $params  raw: AuditLogger redacts and caps them
     * @param  array<string, string|int>  $details
     */
    private function entry(
        string $tool,
        array $params,
        JsonRpcRequest $request,
        bool $allowed,
        ?Reason $reason = null,
        bool $failed = false,
        ?int $durationMs = null,
        ?int $outputBytes = null,
        array $details = [],
    ): AuditEntry {
        $principal = $this->principals->get();

        return AuditEntry::toolCall(
            environment: $this->environment->name(),
            tool: mb_substr($tool, 0, self::MAX_AUDITED_NAME),
            params: $params,
            allowed: $allowed,
            reason: $reason,
            failed: $failed,
            durationMs: $durationMs,
            outputBytes: $outputBytes,
            principal: $principal?->name,
            userId: $principal?->userId(),
            authMethod: $principal?->method->value,
            ip: $principal?->method === AuthMethod::Stdio ? null : $this->container->make(Request::class)->ip(),
            client: ClientInfo::from($request->meta()),
            details: $details,
        );
    }

    /**
     * A failed write is reported and the answer still goes out: an unknown or hidden name holds no data, each inner
     * result of a batch passed its own audit stage (spec 5.7), and search_tools lists only tool definitions.
     */
    private function write(AuditEntry $entry): void
    {
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
            // Nothing left to tell. The answer still goes out.
        }
    }
}
