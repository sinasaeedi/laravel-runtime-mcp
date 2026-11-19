<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Mcp\Wrapped;

use Generator;
use JsonException;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Content\Notification;
use Laravel\Mcp\Server\Content\Text;
use LogicException;
use SinaSaeedi\RuntimeMcp\Security\Redaction\Redactor;
use SinaSaeedi\RuntimeMcp\Tools\Pipeline\RunTool;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

/**
 * Spec 5.16 (01-38): what an official handle() returns, as a ToolResult. Text and structured output become `data`;
 * content that is not text (an image, audio, a blob, a resource link) is left out, and the result says so (rule 11).
 * Redaction (stage 7) and the size cap (stage 8) then apply as for every tool.
 */
final class ResponseConverter
{
    public const ERROR = 'The tool returned an error: %s';

    public const NO_CONTENT = 'The tool returned no content.';

    public const LEFT_OUT_ONE = '1 non-text item (an image, audio, a blob or a link) was left out; this server returns text only.';

    /** %d is the number of items, 2 or more. */
    public const LEFT_OUT = '%d non-text items (images, audio, blobs or links) were left out; this server returns text only.';

    public const NOT_A_RESPONSE = 'The wrapped tool returned a value that is not an MCP response.';

    public function __construct(private readonly Redactor $redactor) {}

    public function convert(mixed $value): ToolResult
    {
        $factory = $this->factory($value);
        $errors = [];
        $texts = [];
        $left = 0;

        foreach ($factory->responses() as $response) {
            $content = $response->content();

            if ($content instanceof Notification) {
                continue;
            }

            if ($response->isError()) {
                $errors[] = $content instanceof Text ? (string) $content : '';

                continue;
            }

            if ($content instanceof Text) { // StructuredContentText too: it extends Text
                $texts[] = (string) $content;
            } else {
                $left++; // Image, Audio, Blob, ResourceLink, and any Content class laravel/mcp adds later
            }
        }

        if ($errors !== []) {
            // Redacted whole, then cut (RunTool::cut()): a secret cut in half is one no pattern matches.
            return ToolResult::unavailable(sprintf(self::ERROR, mb_substr($this->redactor->redactString(implode(' ', $errors)), 0, RunTool::MAX_MESSAGE)));
        }

        $data = $this->data($factory->getStructuredContent(), $texts);

        if ($left === 0) {
            return $data === null ? ToolResult::empty(self::NO_CONTENT) : ToolResult::ok($data);
        }

        // Content was cut, not absent: partial, also when nothing is left (rule 11, spec 5.16).
        return ToolResult::partial($data ?? [], $left === 1 ? self::LEFT_OUT_ONE : sprintf(self::LEFT_OUT, $left), $left);
    }

    /**
     * The value as one ResponseFactory, the way laravel/mcp reads it (InteractsWithResponses::toResponseFactory()): a
     * Generator is read to its end and its notifications are dropped (also v1.2.1's Response::progress()); one factory,
     * alone or as the only item, is used as it is; otherwise each item is a Response or a string, and a string with a
     * NUL byte is a blob.
     */
    private function factory(mixed $value): ResponseFactory
    {
        if ($value instanceof Generator) {
            $items = [];

            foreach ($value as $item) {
                if (! $item instanceof Response || ! $item->isNotification()) {
                    $items[] = $item;
                }
            }

            $value = $items;
        }

        if (is_array($value) && count($value) === 1) {
            $value = array_values($value)[0];
        }

        if ($value instanceof ResponseFactory) {
            return $value;
        }

        $responses = [];

        foreach (is_array($value) ? $value : [$value] as $item) {
            $responses[] = match (true) {
                $item instanceof Response => $item,
                is_string($item) => str_contains($item, "\0") ? Response::blob($item) : Response::text($item),
                default => throw new LogicException(self::NOT_A_RESPONSE),
            };
        }

        return new ResponseFactory($responses);
    }

    /**
     * structuredContent first (its text copy is not read again); else one text that is a JSON object or array; else
     * the texts, joined with a blank line; else null.
     *
     * @param  array<string, mixed>|null  $structured
     * @param  list<string>  $texts
     * @return array<array-key, mixed>|null
     */
    private function data(?array $structured, array $texts): ?array
    {
        if ($structured !== null) {
            return $structured;
        }

        if (count($texts) === 1) {
            try {
                $decoded = json_decode($texts[0], true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $decoded = null;
            }

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $texts === [] ? null : ['text' => implode("\n\n", $texts)];
    }
}
