<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tools\Pipeline;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use JsonException;
use LogicException;
use SinaSaeedi\RuntimeMcp\Audit\AuditEntry;
use SinaSaeedi\RuntimeMcp\Audit\AuditLogger;
use SinaSaeedi\RuntimeMcp\Audit\AuditUnavailable;
use SinaSaeedi\RuntimeMcp\Principal;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthMethod;
use SinaSaeedi\RuntimeMcp\Security\Environment;
use SinaSaeedi\RuntimeMcp\Tools\ResultEnvelope;
use SinaSaeedi\RuntimeMcp\Tools\ToolCall;
use SinaSaeedi\RuntimeMcp\Tools\ToolOutcome;
use Throwable;

/**
 * Stage 9 (spec 4.4, 5.7), the outermost stage: it sees every outcome, also denied and failed
 * ones, encodes the envelope once, writes the audit entry, and fails closed when it cannot.
 *
 * The envelope is encoded here, before the audit, so the audit and the agent see the same outcome
 * and output_bytes is the real size of one copy of the envelope JSON (spec 5.8, 01-17).
 */
final class AuditStage implements Stage
{
    /** An inner stage threw. Class only: its message has not passed redaction. */
    public const INNER_FAILURE = 'The tool failed: %s.';

    public const NOT_ENCODABLE = 'The tool result could not be encoded as JSON.';

    public const WITHHELD = 'The audit log is not available, so the result is withheld.';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Environment $environment,
        private readonly Container $container,
    ) {}

    public function handle(ToolCall $call, Closure $next): ToolOutcome
    {
        try {
            $outcome = $next($call);
        } catch (Throwable $e) {
            $this->report($e);
            $outcome = ToolOutcome::failed($e, sprintf(self::INNER_FAILURE, $e::class));
        }

        $environment = $this->environment->name();
        [$outcome, $json] = $this->encode($outcome, $environment);

        try {
            $this->audit->toolCall($this->entry($call, $outcome, $environment, strlen($json)));
        } catch (AuditUnavailable $e) {
            // Fail closed (01-05): no audit entry, no result. The withheld outcome itself cannot be
            // audited; the app's error log gets the reason.
            $this->report($e);
            [$outcome, $json] = $this->encode(ToolOutcome::failed($e, self::WITHHELD), $environment);
        }

        return $outcome->withEnvelope(self::decode($json));
    }

    /** @return array{ToolOutcome, string} */
    private function encode(ToolOutcome $outcome, string $environment): array
    {
        try {
            return [$outcome, ResultEnvelope::fromResult($outcome->result, $environment)->toJson()];
        } catch (JsonException $e) {
            $this->report($e);
            $failed = ToolOutcome::failed($e, self::NOT_ENCODABLE);

            return [$failed, ResultEnvelope::fromResult($failed->result, $environment)->toJson()];
        }
    }

    /**
     * The clean envelope: invalid UTF-8 is already U+FFFD, so Response::structured() cannot throw.
     *
     * @return array<string, mixed>
     */
    private static function decode(string $json): array
    {
        $envelope = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($envelope)) {
            throw new LogicException('The envelope did not decode to an object.');
        }

        /** @var array<string, mixed> $envelope */
        return $envelope;
    }

    private function entry(ToolCall $call, ToolOutcome $outcome, string $environment, int $outputBytes): AuditEntry
    {
        $principal = $call->principal();

        return AuditEntry::toolCall(
            environment: $environment,
            tool: $call->tool->name(),
            params: $call->arguments, // raw: AuditLogger redacts and caps them (01-05)
            allowed: $outcome->allowed,
            reason: $outcome->reason,
            failed: $outcome->isError,
            errorClass: $outcome->errorClass,
            durationMs: intdiv(hrtime(true) - $call->startedAt, 1_000_000),
            rowCount: $outcome->result->rowCount,
            outputBytes: $outputBytes,
            principal: $principal?->name,
            userId: $principal?->userId(),
            authMethod: $principal?->method->value,
            ip: $this->ip($principal),
            client: $call->client,
        );
    }

    /** Null for STDIO: the console request has no client address. */
    private function ip(?Principal $principal): ?string
    {
        if ($principal?->method === AuthMethod::Stdio) {
            return null;
        }

        return $this->container->make(Request::class)->ip();
    }

    /** A denial is never reported: it is a normal outcome. The handler may fail too. */
    private function report(Throwable $e): void
    {
        try {
            $this->container->make(ExceptionHandler::class)->report($e);
        } catch (Throwable) {
            // Nothing left to tell. The error result still goes out.
        }
    }
}
