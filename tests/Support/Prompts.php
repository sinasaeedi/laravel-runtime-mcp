<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The prompts of 01-36 over HTTP: boot with the base plugins, allow tools, call prompts/list and
 * prompts/get with the default test key.
 */
final class Prompts
{
    /** Every tool that a built-in prompt needs or names "if it is available" (spec 6.11). */
    public const BUILT_IN_TOOLS = [
        'app_info', 'queue_overview', 'queue_failed_jobs_summary', 'queue_failed_jobs_list', 'queue_failed_job_show',
        'logs_recent_errors', 'logs_search', 'model_show',
    ];

    /**
     * The endpoint on, the base plugins, and a Gate that allows each tool in $allow.
     *
     * @param  array<string, mixed>  $config
     * @param  list<string>  $allow
     */
    public static function serve(array $config = [], ?string $environment = null, array $allow = self::BUILT_IN_TOOLS): void
    {
        AuditLog::clear();
        currentTest()->bootWith(['runtime-mcp.enabled' => true, ...$config], $environment);
        Gates::allow(...$allow);
    }

    /** @return list<string> the names in prompts/list */
    public static function listed(ProtocolEra $era = ProtocolEra::Legacy): array
    {
        $prompts = currentTest()->mcpPost('prompts/list', era: $era)->assertOk()->json('result.prompts');
        assert(is_array($prompts));

        return array_values(array_map(fn (mixed $prompt): string => is_array($prompt) && is_string($prompt['name'] ?? null) ? $prompt['name'] : '?', $prompts));
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     * @return TestResponse<Response>
     */
    public static function get(string $name, array $arguments = [], ProtocolEra $era = ProtocolEra::Legacy): TestResponse
    {
        return currentTest()->mcpPost('prompts/get', ['name' => $name, 'arguments' => (object) $arguments], era: $era);
    }

    /**
     * The text of the one message of a successful prompts/get.
     *
     * @param  array<array-key, mixed>  $arguments
     */
    public static function text(string $name, array $arguments = [], ProtocolEra $era = ProtocolEra::Legacy): string
    {
        $response = self::get($name, $arguments, $era)->assertOk();
        $text = $response->json('result.messages.0.content.text');
        assert(is_string($text), 'No text: '.$response->getContent());

        return $text;
    }
}
