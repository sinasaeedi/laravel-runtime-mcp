<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Testing;

use PHPUnit\Framework\Assert;
use SinaSaeedi\RuntimeMcp\Audit\AuditEntry;
use SinaSaeedi\RuntimeMcp\Mcp\Methods\GetPrompt;

/**
 * The answer to one test-kit prompts/get (01-39, spec 5.14), with the audit entries that it wrote. Our prompts/get
 * handler (01-36, Methods\GetPrompt) answers a hidden or unknown prompt with laravel/mcp's own "not found" error and
 * audits it as a denied prompt_get entry.
 */
final class RuntimeMcpPromptResult
{
    /** %1$s is the prompt, %2$s the text. */
    public const NOT_SEEN = 'The text [%2$s] is not in the messages of the prompt [%1$s].';

    /** %1$s is the prompt, %2$s the answer. */
    public const NOT_HIDDEN = 'The prompt [%1$s] is not hidden: %2$s';

    /** %1$s is the prompt, %2$s the count. */
    public const AUDIT_COUNT = 'The prompt [%1$s] wrote %2$d denied prompt_get audit entries, not 1.';

    /**
     * @param  array<array-key, mixed>  $response  the JSON-RPC response
     * @param  list<AuditEntry>  $entries  the audit entries written while the request ran
     */
    public function __construct(
        private readonly string $name,
        private readonly array $response,
        private readonly array $entries,
        private readonly bool $eventsFaked,
    ) {}

    /** Some message text (result.messages.*.content.text) contains $text. */
    public function assertSee(string $text): self
    {
        $messages = data_get($this->response, 'result.messages');
        $seen = false;

        foreach (is_array($messages) ? $messages : [] as $message) {
            $content = data_get($message, 'content.text');
            $seen = $seen || (is_string($content) && str_contains($content, $text));
        }

        Assert::assertTrue($seen, sprintf(self::NOT_SEEN, $this->name, $text));

        return $this;
    }

    /**
     * The "not found" error (-32602 "Prompt [name] not found.") that a hidden or unknown prompt gets, and exactly one
     * denied prompt_get audit entry.
     */
    public function assertHidden(): self
    {
        $error = $this->response['error'] ?? null;
        $hidden = is_array($error)
            && ($error['code'] ?? null) === GetPrompt::INVALID_PARAMS
            && ($error['message'] ?? null) === sprintf(GetPrompt::NOT_FOUND, $this->name);

        Assert::assertTrue($hidden, sprintf(self::NOT_HIDDEN, $this->name, (string) json_encode($this->response)));

        $denied = array_filter($this->entries, fn (AuditEntry $entry): bool => $entry->kind === AuditEntry::KIND_PROMPT_GET
            && $entry->decision === AuditEntry::DECISION_DENIED);
        $message = sprintf(self::AUDIT_COUNT, $this->name, count($denied)).($denied === [] && $this->eventsFaked ? RuntimeMcpResult::EVENTS_FAKED : '');

        Assert::assertCount(1, $denied, $message);

        return $this;
    }
}
