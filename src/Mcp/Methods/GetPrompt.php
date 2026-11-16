<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Mcp\Methods;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use SinaSaeedi\RuntimeMcp\Audit\AuditEntry;
use SinaSaeedi\RuntimeMcp\Audit\AuditLogger;
use SinaSaeedi\RuntimeMcp\Audit\AuditUnavailable;
use SinaSaeedi\RuntimeMcp\Audit\Reason;
use SinaSaeedi\RuntimeMcp\Mcp\ClientInfo;
use SinaSaeedi\RuntimeMcp\Mcp\McpPromptAdapter;
use SinaSaeedi\RuntimeMcp\Mcp\McpPromptAdapters;
use SinaSaeedi\RuntimeMcp\Mcp\McpToolAdapters;
use SinaSaeedi\RuntimeMcp\Prompts\PromptArgument;
use SinaSaeedi\RuntimeMcp\Prompts\PromptInput;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthMethod;
use SinaSaeedi\RuntimeMcp\Security\Auth\CurrentPrincipal;
use SinaSaeedi\RuntimeMcp\Security\Authorization\PromptGate;
use SinaSaeedi\RuntimeMcp\Security\Environment;
use SinaSaeedi\RuntimeMcp\Security\Redaction\Redactor;
use SinaSaeedi\RuntimeMcp\Server\RuntimeServer;
use Throwable;

/**
 * Our prompts/get handler (spec 5.14), in place of laravel/mcp's Methods\GetPrompt. That one looks the
 * prompt up in ServerContext::prompts(), which drops every prompt whose shouldRegister() is false, and
 * writes no audit entry. This one searches every registered adapter, hidden ones too, and writes exactly
 * one prompt_get entry for every request that names a prompt (spec 5.7).
 *
 * Unknown, hidden and denied prompts get laravel/mcp's own "not found" error, so a caller cannot probe
 * which prompts exist; the audit has the real reason. No rate limit: a prompt runs no query and returns
 * no app data; AuditLogger::promptGet() bounds the audit instead (01-17 Part D).
 */
final class GetPrompt implements Method
{
    /** laravel/mcp's texts and code (ResolvesPrompts, GetPrompt). */
    public const NOT_FOUND = 'Prompt [%s] not found.';

    public const MISSING_NAME = 'Missing [name] parameter.';

    public const INVALID_PARAMS = -32602;

    /** Ours: laravel/mcp's ValidationMessages has no prefix. %s is the redacted messages, cut. */
    public const INVALID_ARGUMENTS = 'Invalid params: %s';

    public const INTERNAL_ERROR = -32603;

    /** The fixed answer when a prompt's own code failed. It holds no exception text. */
    public const FAILURE = 'The prompt failed inside the server. The full error is in the server log.';

    /** The audit could not be written, so the text is withheld (rule 12: the audit is never relaxed). */
    public const WITHHELD = 'The audit log is not available, so the prompt is withheld.';

    /** Spec 5.14: every argument value is a string of at most 200 characters. */
    public const MAX_VALUE = 200;

    /** The longest part of the error that comes from validation messages, in characters (as RunTool). */
    public const MAX_MESSAGE = 300;

    /** The longest unknown argument name repeated in a message (as RunTool). */
    public const MAX_KEY = 64;

    /** The `in` message of an argument with allowed values. %1$s is the name, %2$s the values. */
    public const ONE_OF = 'The %1$s argument must be one of: %2$s.';

    /** The `in` message of an argument whose allowed list is empty (no model alias configured). */
    public const NONE_ALLOWED = 'The %s argument has no allowed value on this server.';

    public const NOT_ALLOWED = 'The %s argument is not allowed.';

    public function __construct(
        private readonly RuntimeServer $definition,
        private readonly Container $container,
        private readonly CurrentPrincipal $principals,
        private readonly Environment $environment,
        private readonly AuditLogger $audit,
        private readonly PromptGate $gate,
        private readonly Factory $validation,
    ) {}

    /** @throws JsonRpcException */
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $started = hrtime(true);
        $name = $request->get('name');

        if ($name === null) {
            throw new JsonRpcException(self::MISSING_NAME, self::INVALID_PARAMS, $request->id);
        }

        // A name that is not a string is shown by its type, never cast (as CallTool).
        $shown = is_string($name) ? $name : get_debug_type($name);
        // laravel/mcp's JsonRpcRequest::toRequest() refused arguments that are not an object before this ran.
        $arguments = $request->get('arguments');
        $arguments = is_array($arguments) ? $arguments : [];

        $tools = McpToolAdapters::byName(McpToolAdapters::fromDefinition($this->definition, $this->container));
        $adapter = self::find(McpPromptAdapters::fromDefinition($this->definition, $this->container, $tools), $name);

        if ($adapter === null) {
            $this->auditRefusal($this->entry($request, $shown, $arguments, $started, false, Reason::UnknownPrompt));

            throw new JsonRpcException(sprintf(self::NOT_FOUND, $shown), self::INVALID_PARAMS, $request->id);
        }

        $decision = $this->gate->decide($this->principals->get(), $adapter->runtimePrompt(), $tools);

        if (! $decision->allowed) {
            $this->auditRefusal($this->entry($request, $shown, $arguments, $started, false, $decision->reason, tool: $decision->tool));

            throw new JsonRpcException(sprintf(self::NOT_FOUND, $shown), self::INVALID_PARAMS, $request->id);
        }

        try {
            $text = $this->text($adapter, $arguments);
        } catch (ValidationException $e) {
            $this->auditRefusal($this->entry($request, $shown, $arguments, $started, true, failed: true, errorClass: $e::class));

            throw new JsonRpcException(sprintf(self::INVALID_ARGUMENTS, $this->messages($e)), self::INVALID_PARAMS, $request->id);
        } catch (Throwable $e) {
            $this->report($e);
            $this->auditRefusal($this->entry($request, $shown, $arguments, $started, true, failed: true, errorClass: $e::class));

            throw new JsonRpcException(self::FAILURE, self::INTERNAL_ERROR, $request->id);
        }

        try {
            $this->audit->promptGet($this->entry($request, $shown, $arguments, $started, true));
        } catch (AuditUnavailable $e) {
            $this->report($e);

            throw new JsonRpcException(self::WITHHELD, self::INTERNAL_ERROR, $request->id);
        }

        // The shape of laravel/mcp's GetPrompt::serializable(): one user message (Decisions).
        $message = Response::text($text);

        return JsonRpcResponse::result($request->id, [
            'description' => $adapter->description(),
            'messages' => [[
                'role' => $message->role()->value,
                'content' => $message->content()->toPrompt($adapter),
            ]],
        ]);
    }

    /** @param  list<McpPromptAdapter>  $adapters */
    private static function find(array $adapters, mixed $name): ?McpPromptAdapter
    {
        foreach ($adapters as $adapter) {
            if ($adapter->name() === $name) {
                return $adapter;
            }
        }

        return null;
    }

    /**
     * Validates, redacts, then builds the text. Every step runs the prompt's own code or the Redactor
     * (whose constructor throws for a bad extra_patterns entry), so the caller catches every Throwable.
     *
     * @param  array<array-key, mixed>  $arguments
     *
     * @throws ValidationException
     */
    private function text(McpPromptAdapter $adapter, array $arguments): string
    {
        $context = $adapter->context();
        $values = $this->validate($adapter->runtimePrompt()->arguments($context), $arguments);
        $redactor = $this->container->make(Redactor::class);

        return $adapter->runtimePrompt()->message(new PromptInput($context, array_map($redactor->redactString(...), $values)));
    }

    /**
     * Spec 5.14: every value is a string of at most 200 characters, with the prompt's rules; a key that
     * no argument names is refused (spec 4.3, as RunTool). An optional argument sent as "" or null is
     * absent: a client can send "" for a field its user left blank, and `filled` would refuse the call.
     *
     * @param  list<PromptArgument>  $declared
     * @param  array<array-key, mixed>  $arguments
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    private function validate(array $declared, array $arguments): array
    {
        $rules = [];
        $messages = [];

        foreach ($declared as $argument) {
            if (! $argument->required && in_array($arguments[$argument->name] ?? null, ['', null], true)) {
                unset($arguments[$argument->name]);
            }

            $list = [$argument->required ? 'required' : 'filled', 'string', 'max:'.self::MAX_VALUE, ...$argument->rules];

            if ($argument->allowed !== null) {
                $list[] = Rule::in($argument->allowed);
                $messages[$argument->name.'.in'] = $argument->allowed === []
                    ? sprintf(self::NONE_ALLOWED, $argument->name)
                    : sprintf(self::ONE_OF, $argument->name, implode(', ', $argument->allowed));
            }

            $rules[$argument->name] = $list;
        }

        $unknown = [];

        foreach (array_keys($arguments) as $key) {
            if (! isset($rules[(string) $key])) {
                $unknown[] = mb_substr((string) $key, 0, self::MAX_KEY);
            }
        }

        $validator = $this->validation->make($arguments, $rules, $messages);
        $validator->after(function (Validator $validator) use ($unknown): void {
            foreach ($unknown as $key) {
                $validator->errors()->add($key, sprintf(self::NOT_ALLOWED, $key));
            }
        });

        $values = [];

        foreach ($validator->validate() as $key => $value) {
            $values[(string) $key] = is_string($value) ? $value : '';
        }

        return $values;
    }

    /** Redacts the whole message, then cuts it (as RunTool: a cut first would hide half a secret from the patterns). */
    private function messages(ValidationException $e): string
    {
        $text = implode(' ', $e->validator->errors()->all());

        try {
            $text = $this->container->make(Redactor::class)->redactString($text);
        } catch (Throwable) {
            $text = Redactor::UNSCANNABLE;
        }

        return mb_substr($text, 0, self::MAX_MESSAGE);
    }

    /**
     * One prompt_get entry, filled like CallTool fills an unknown-tool entry.
     *
     * @param  array<array-key, mixed>  $arguments  raw: AuditLogger redacts and caps them
     */
    private function entry(
        JsonRpcRequest $request,
        string $name,
        array $arguments,
        int $started,
        bool $allowed,
        ?Reason $reason = null,
        bool $failed = false,
        ?string $errorClass = null,
        ?string $tool = null,
    ): AuditEntry {
        $principal = $this->principals->get();

        return AuditEntry::promptGet(
            environment: $this->environment->name(),
            prompt: $name,
            params: $arguments,
            allowed: $allowed,
            reason: $reason,
            failed: $failed,
            errorClass: $errorClass,
            durationMs: intdiv(hrtime(true) - $started, 1_000_000),
            principal: $principal?->name,
            userId: $principal?->userId(),
            authMethod: $principal?->method->value,
            ip: $principal?->method === AuthMethod::Stdio ? null : $this->container->make(Request::class)->ip(),
            client: ClientInfo::from($request->meta()),
            tool: $tool,
        );
    }

    /**
     * An entry whose answer is an error: a failed write is reported and the error still goes out. The
     * error holds no data, so there is nothing to withhold (as CallTool).
     */
    private function auditRefusal(AuditEntry $entry): void
    {
        try {
            $this->audit->promptGet($entry);
        } catch (AuditUnavailable $e) {
            $this->report($e);
        }
    }

    private function report(Throwable $e): void
    {
        try {
            $this->container->make(ExceptionHandler::class)->report($e);
        } catch (Throwable) {
            // Nothing left to tell. The error still goes out.
        }
    }
}
