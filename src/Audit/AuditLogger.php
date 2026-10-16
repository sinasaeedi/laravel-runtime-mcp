<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Audit;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\LogManager;
use SinaSaeedi\RuntimeMcp\Audit\Events\AuthFailed;
use SinaSaeedi\RuntimeMcp\Audit\Events\ToolCalled;
use SinaSaeedi\RuntimeMcp\Audit\Events\ToolDenied;
use SinaSaeedi\RuntimeMcp\Audit\Events\ToolFailed;
use SinaSaeedi\RuntimeMcp\Security\Redaction\Redactor;
use Throwable;

/**
 * Spec 5.7: writes one audit entry, then dispatches one event.
 *
 * Bound as scoped (rule 14). It resolves the Redactor, the log, the event dispatcher and the
 * exception handler on each call, inside its own try: the Redactor's constructor can throw for a bad
 * extra_patterns entry, and a test fake (Event::fake(), Exceptions::fake()) made after this object
 * still applies.
 */
final class AuditLogger
{
    /** The params cap, in bytes of the JSON encoding (Part D). */
    public const MAX_PARAMS_BYTES = 16_384;

    /** Over the cap, each string longer than this many bytes is cut to it (Part D step 3). */
    public const MAX_STRING_BYTES = 1_024;

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
    ) {}

    /**
     * Writes a tool call entry, then dispatches ToolCalled, ToolDenied or ToolFailed.
     *
     * @throws AuditUnavailable when the entry cannot be written: the caller withholds the result
     */
    public function toolCall(AuditEntry $entry): void
    {
        try {
            $stored = $this->write($entry);
        } catch (Throwable $e) {
            throw new AuditUnavailable($e);
        }

        $this->dispatch(match (true) {
            $stored->decision === AuditEntry::DECISION_DENIED => new ToolDenied($stored),
            $stored->outcome === AuditEntry::OUTCOME_ERROR => new ToolFailed($stored),
            default => new ToolCalled($stored),
        });
    }

    /**
     * Writes a rejected request entry, then dispatches AuthFailed. Never throws: a failed write is
     * reported, and the caller sends its 401 or 403 unchanged (a rejection returns no data).
     */
    public function rejectedRequest(AuditEntry $entry): void
    {
        try {
            $stored = $this->write($entry);
        } catch (Throwable $e) {
            $this->report(new AuditUnavailable($e));

            return;
        }

        $this->dispatch(new AuthFailed($stored));
    }

    /** Redacts and caps the params, then writes the entry. Returns the stored entry. */
    private function write(AuditEntry $entry): AuditEntry
    {
        $redacted = $this->container->make(Redactor::class)->redact($entry->params);
        $stored = $entry->withParams(self::cap(is_array($redacted) ? $redacted : []));

        $this->container->make(LogManager::class)
            ->channel(AuditChannel::name($this->config))
            ->log(self::level($stored), 'runtime-mcp.'.$stored->kind, $stored->toArray());

        return $stored;
    }

    /** A listener that throws is reported, never raised: the entry is already written. */
    private function dispatch(object $event): void
    {
        try {
            $this->container->make(Dispatcher::class)->dispatch($event);
        } catch (Throwable $e) {
            $this->report($e);
        }
    }

    /** Reports to the app's exception handler. The handler may fail too (the same broken disk). */
    private function report(Throwable $e): void
    {
        try {
            $this->container->make(ExceptionHandler::class)->report($e);
        } catch (Throwable) {
            // Nothing left to tell. The caller's own result (an error, a 401, a 403) still goes out.
        }
    }

    private static function level(AuditEntry $entry): string
    {
        return match (true) {
            $entry->kind === AuditEntry::KIND_REQUEST_REJECTED => 'warning',
            $entry->decision === AuditEntry::DECISION_DENIED => 'warning',
            $entry->outcome === AuditEntry::OUTCOME_ERROR => 'error',
            default => 'info',
        };
    }

    /**
     * Part D: keep params of at most MAX_PARAMS_BYTES; else cut each long string; else replace them.
     *
     * @param  array<array-key, mixed>  $params
     * @return array<array-key, mixed>
     */
    private static function cap(array $params): array
    {
        $size = self::jsonBytes($params);

        if ($size <= self::MAX_PARAMS_BYTES) {
            return $params;
        }

        $cut = self::cutStrings($params);

        if (self::jsonBytes($cut) <= self::MAX_PARAMS_BYTES) {
            return $cut;
        }

        return ['_truncated' => '[params of '.$size.' bytes over the '.self::MAX_PARAMS_BYTES.'-byte cap]'];
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function cutStrings(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_string($item) && strlen($item) > self::MAX_STRING_BYTES) {
                // mb_strcut() never splits a UTF-8 character, so the kept part stays valid text.
                $kept = mb_strcut($item, 0, self::MAX_STRING_BYTES, 'UTF-8');
                $value[$key] = $kept.'…[truncated '.(strlen($item) - strlen($kept)).' bytes]';
            } elseif (is_array($item)) {
                $value[$key] = self::cutStrings($item);
            }
        }

        return $value;
    }

    /** @param  array<array-key, mixed>  $value */
    private static function jsonBytes(array $value): int
    {
        $json = json_encode($value, self::JSON_FLAGS);

        return $json === false ? PHP_INT_MAX : strlen($json);
    }
}
