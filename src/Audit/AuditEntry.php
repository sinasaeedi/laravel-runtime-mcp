<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Audit;

use Illuminate\Support\Carbon;

/**
 * Spec 5.7: one audit entry. These fields and no others. No field can hold result data (rule 16).
 *
 * The caller passes the raw params; AuditLogger redacts and caps them before it stores the entry
 * (withParams()). The value object never resolves a service.
 */
final readonly class AuditEntry
{
    public const KIND_TOOL_CALL = 'tool_call';

    public const KIND_REQUEST_REJECTED = 'request_rejected';

    public const DECISION_ALLOWED = 'allowed';

    public const DECISION_DENIED = 'denied';

    public const OUTCOME_SUCCESS = 'success';

    public const OUTCOME_ERROR = 'error';

    /** The longest tool name and details string kept, in characters. */
    public const MAX_TEXT = 255;

    /**
     * @param  array<array-key, mixed>  $params
     * @param  array{name: string, version: ?string}|null  $client
     * @param  array<string, string|int>  $details
     */
    private function __construct(
        public string $kind,
        public string $timestamp,
        public string $environment,
        public ?string $principal,
        public int|string|null $userId,
        public ?string $authMethod,
        public ?string $ip,
        public ?array $client,
        public ?string $tool,
        public array $params,
        public string $decision,
        public ?string $reason,
        public ?string $outcome,
        public ?string $errorClass,
        public ?int $durationMs,
        public ?int $rowCount,
        public ?int $outputBytes,
        public array $details,
    ) {}

    /**
     * One tools/call (01-08). Denied: outcome, error class null. Allowed: outcome success or error.
     *
     * @param  array<array-key, mixed>  $params  the raw arguments; AuditLogger redacts them
     * @param  array{name: string, version: ?string}|null  $client  from ClientInfo::from()
     * @param  array<string, string|int>  $details
     */
    public static function toolCall(
        string $environment,
        string $tool,
        array $params,
        bool $allowed,
        ?Reason $reason = null,
        bool $failed = false,
        ?string $errorClass = null,
        ?int $durationMs = null,
        ?int $rowCount = null,
        ?int $outputBytes = null,
        ?string $principal = null,
        int|string|null $userId = null,
        ?string $authMethod = null,
        ?string $ip = null,
        ?array $client = null,
        array $details = [],
    ): self {
        $outcome = $allowed ? ($failed ? self::OUTCOME_ERROR : self::OUTCOME_SUCCESS) : null;

        return new self(
            kind: self::KIND_TOOL_CALL,
            timestamp: self::now(),
            environment: $environment,
            principal: $principal,
            userId: $userId,
            authMethod: $authMethod,
            ip: $ip,
            client: $client,
            tool: mb_substr($tool, 0, self::MAX_TEXT),
            params: $params,
            decision: $allowed ? self::DECISION_ALLOWED : self::DECISION_DENIED,
            reason: $allowed ? null : $reason?->value,
            outcome: $outcome,
            errorClass: $outcome === self::OUTCOME_ERROR ? $errorClass : null,
            durationMs: $durationMs,
            rowCount: $rowCount,
            outputBytes: $outputBytes,
            details: self::cutDetails($details),
        );
    }

    /**
     * One request rejected at the HTTP layer (01-06, 01-07, 01-35). Never a credential in $details.
     *
     * @param  array<string, string|int>  $details  scheme, host or origin
     */
    public static function rejectedRequest(
        string $environment,
        Reason $reason,
        ?string $authMethod,
        ?string $ip,
        array $details = [],
    ): self {
        return new self(
            kind: self::KIND_REQUEST_REJECTED,
            timestamp: self::now(),
            environment: $environment,
            principal: null,
            userId: null,
            authMethod: $authMethod,
            ip: $ip,
            client: null,
            tool: null,
            params: [],
            decision: self::DECISION_DENIED,
            reason: $reason->value,
            outcome: null,
            errorClass: null,
            durationMs: null,
            rowCount: null,
            outputBytes: null,
            details: self::cutDetails($details),
        );
    }

    /**
     * The same entry with the params that AuditLogger stores (redacted and capped).
     *
     * @param  array<array-key, mixed>  $params
     */
    public function withParams(array $params): self
    {
        return new self(
            $this->kind, $this->timestamp, $this->environment, $this->principal, $this->userId,
            $this->authMethod, $this->ip, $this->client, $this->tool, $params, $this->decision,
            $this->reason, $this->outcome, $this->errorClass, $this->durationMs, $this->rowCount,
            $this->outputBytes, $this->details,
        );
    }

    /** @return array<string, mixed> the JSON shape of spec 5.7, in this order */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'timestamp' => $this->timestamp,
            'environment' => $this->environment,
            'principal' => $this->principal,
            'user_id' => $this->userId,
            'auth_method' => $this->authMethod,
            'ip' => $this->ip,
            'client' => $this->client,
            'tool' => $this->tool,
            'params' => $this->params,
            'decision' => $this->decision,
            'reason' => $this->reason,
            'outcome' => $this->outcome,
            'error_class' => $this->errorClass,
            'duration_ms' => $this->durationMs,
            'row_count' => $this->rowCount,
            'output_bytes' => $this->outputBytes,
            'details' => $this->details,
        ];
    }

    /** ISO 8601 with offset in the app timezone (spec 4.4). Carbon::setTestNow() applies. */
    private static function now(): string
    {
        return Carbon::now()->toIso8601String();
    }

    /**
     * @param  array<string, string|int>  $details
     * @return array<string, string|int>
     */
    private static function cutDetails(array $details): array
    {
        return array_map(
            fn (string|int $value): string|int => is_string($value) ? mb_substr($value, 0, self::MAX_TEXT) : $value,
            $details,
        );
    }
}
