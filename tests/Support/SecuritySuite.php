<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

use Illuminate\Testing\TestResponse;
use RuntimeException;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;
use SinaSaeedi\RuntimeMcp\Prompts\Contracts\RuntimePrompt;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Mcp\OrderLookupTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\Order;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\Custom\ErpOrderTool;
use SinaSaeedi\RuntimeMcp\Tests\TestCase;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use Symfony\Component\HttpFoundation\Response;

/**
 * 01-30: the setup that the cross-cutting security tests share. Every tool of the package (every plugin, on or off),
 * one custom tool (01-29) and one wrapped tool (01-38) are served on the real endpoint with a log file and a
 * failed-job file to read. The default connection and the read-only connection are SQLite, so the database tools
 * answer `unavailable` before they look (their PostgreSQL and MySQL cases are in tests/Integration/{Pgsql,Mysql}/
 * SecuritySuite/).
 *
 * TOOLS is a constant because a Pest dataset is built before the app exists. registeredTools() reads the registry
 * inside a test; EveryToolTest ET1 fails when the two differ, so a new tool cannot skip the suite.
 */
final class SecuritySuite
{
    /** The strict environment of the suite: not in environments.relaxed (spec 4.6). */
    public const ENVIRONMENT = 'prod-eu';

    /**
     * Every registered tool: [minimal valid arguments, status on SQLite with serve(), capped at 1,000 bytes].
     * "capped" = its result with these arguments is larger than OutputCap::MIN_MAX_BYTES, so the cap must cut it.
     *
     * @var array<string, array{array<string, mixed>, string, bool}>
     */
    public const TOOLS = [
        'app_info' => [[], 'ok', true],
        'model_show' => [['model' => 'order', 'key' => 1], 'unavailable', false],
        'db_schema' => [[], 'unavailable', false],
        'db_query' => [['sql' => 'SELECT 1'], 'unavailable', false],
        'queue_overview' => [[], 'unavailable', false],
        'queue_failed_jobs_list' => [['limit' => 100], 'ok', true],
        'queue_failed_jobs_summary' => [[], 'ok', false],
        'queue_failed_job_show' => [['id' => 'f-1'], 'empty', false],
        'logs_search' => [['limit' => 200], 'ok', true],
        'logs_recent_errors' => [['limit' => 100], 'ok', true],
        'erp_order' => [['action' => 'status'], 'ok', false],
        'app_order_lookup' => [['order' => 7], 'ok', false],
    ];

    /** The package's own tools whose "could not look" case needs PostgreSQL or MySQL (NeverSilentlyEmptyTest). */
    public const DATABASE_TOOLS = ['db_schema', 'db_query', 'model_show'];

    /** The package's own tools that read no outside source, so they have no "could not look" case. */
    public const NO_SOURCE = ['app_info'];

    /** The fixture tools of the app, not of the package: one custom, one wrapped. */
    public const APP_TOOLS = ['erp_order', 'app_order_lookup'];

    /**
     * Every built-in prompt: its minimal valid arguments.
     *
     * @var array<string, array<string, string>>
     */
    public const PROMPTS = [
        'health_check' => [],
        'explain_record' => ['model' => 'order', 'key' => '1'],
        'investigate_failed_jobs' => [],
        'triage_recent_errors' => [],
    ];

    /** Distinct error messages for logs_recent_errors: words, not numbers, so each one is its own group. */
    private const ERRORS = [
        'Payment gateway refused the charge', 'Invoice renderer ran out of paper', 'Warehouse feed sent a broken row',
        'Mailer could not reach the relay', 'Search index rejected the document', 'Tax service answered slowly',
        'Shipping label printer is offline', 'Coupon engine found a loop', 'Report export hit the size limit',
        'Webhook signature did not match', 'Image resizer got an empty file', 'Ledger sync saw a duplicate',
    ];

    /** The failed-job file of the suite (the file source of 01-23 works on SQLite). */
    public static function failedJobsFile(): string
    {
        return sys_get_temp_dir().'/rmcp-security-suite-'.getmypid().'.json';
    }

    /**
     * The boot config of serve().
     *
     * @param  array<string, mixed>  $more
     * @return array<string, mixed>
     */
    public static function config(array $more = []): array
    {
        return [
            ...LogFiles::config(),
            'runtime-mcp.models' => ['order' => ['class' => Order::class, 'relations' => []]],
            'runtime-mcp.tools' => [ErpOrderTool::class],
            'runtime-mcp.wrapped_tools' => [
                ['class' => OrderLookupTool::class, 'name' => OrderLookupTool::NAME, 'tier' => 'read_sensitive', 'open_world' => false],
            ],
            'queue.failed' => ['driver' => 'file', 'path' => self::failedJobsFile()],
            ...$more,
        ];
    }

    /**
     * Boots the endpoint with config(), writes the log file and the failed-job file, and allows every tool of TOOLS.
     *
     * @param  array<string, mixed>  $config
     * @param  list<string>|null  $allow  the tools whose Gate allows every principal (null: every tool of TOOLS)
     */
    public static function serve(array $config = [], ?string $environment = self::ENVIRONMENT, ?array $allow = null): void
    {
        self::clear();
        currentTest()->bootWith(self::config($config), $environment);
        WriteAttempts::tables('testing');
        self::seed();
        Gates::allow(...$allow ?? array_keys(self::TOOLS));
    }

    /**
     * A log file with 24 entries (12 info, 12 distinct errors) and 20 failed jobs, all in the last 24 hours, so the
     * tools with a default window of 24 hours (logs_recent_errors, queue_failed_jobs_summary) find them too.
     */
    public static function seed(): void
    {
        $lines = [];

        foreach (self::ERRORS as $i => $error) {
            $time = now()->subMinutes(120 - $i * 5)->format('Y-m-d H:i:s');
            $lines[] = LogFiles::line($time, 'info', 'Order batch finished for the morning run', ['batch' => 'b-'.$i]);
            $lines[] = LogFiles::line($time, 'error', $error, ['step' => 'checkout']);
        }

        LogFiles::write('app.log', $lines);

        for ($i = 0; $i < 20; $i++) {
            $class = 'App\\Jobs\\'.(['SendInvoice', 'SyncOrder', 'ShipParcel', 'MailReceipt'][$i % 4]);
            FailedJobs::log($class, new RuntimeException(self::ERRORS[$i % 12]), now()->subMinutes(100 - $i)->format('Y-m-d H:i:s'));
        }
    }

    public static function clear(): void
    {
        AuditLog::clear();
        LogFiles::clear();

        if (file_exists(self::failedJobsFile())) {
            unlink(self::failedJobsFile());
        }
    }

    /**
     * The names of every tool that the running app registers, from the registry (every plugin, on or off) and the
     * custom and wrapped config, mapped to the tool class.
     *
     * @return array<string, class-string>
     */
    public static function registeredTools(): array
    {
        $tools = [];

        foreach (app(PluginRegistry::class)->all() as $plugin) {
            foreach ($plugin->tools() as $class) {
                $tool = app($class);
                assert($tool instanceof RuntimeTool);
                $tools[$tool->name()] = $class;
            }
        }

        $tools[app(ErpOrderTool::class)->name()] = ErpOrderTool::class;
        $tools[OrderLookupTool::NAME] = OrderLookupTool::class;

        return $tools;
    }

    /**
     * The built-in prompts of every plugin, on or off: name => the tools it needs.
     *
     * @return array<string, list<string>>
     */
    public static function registeredPrompts(): array
    {
        $prompts = [];

        foreach (app(PluginRegistry::class)->all() as $plugin) {
            foreach ($plugin->prompts() as $class) {
                $prompt = app($class);
                assert($prompt instanceof RuntimePrompt);
                $prompts[$prompt->name()] = $prompt->tools();
            }
        }

        return $prompts;
    }

    /**
     * The entry of one tool in tools/list, or null when it is not listed.
     *
     * @return array<array-key, mixed>|null
     */
    public static function listed(string $tool, ProtocolEra $era = ProtocolEra::Legacy): ?array
    {
        $tools = currentTest()->mcpPost('tools/list', era: $era)->assertOk()->json('result.tools');

        foreach (is_array($tools) ? $tools : [] as $entry) {
            if (is_array($entry) && ($entry['name'] ?? null) === $tool) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * One tools/call of a tool of TOOLS with its arguments, over HTTP with $key.
     *
     * @return TestResponse<Response>
     */
    public static function call(string $tool, ProtocolEra $era = ProtocolEra::Legacy, ?string $key = TestCase::TEST_KEY): TestResponse
    {
        $params = ['name' => $tool, 'arguments' => (object) self::TOOLS[$tool][0]];

        return currentTest()->mcpPost('tools/call', $params, era: $era, key: $key)->assertOk();
    }

    /**
     * The envelope (structuredContent) of one call.
     *
     * @return array<array-key, mixed>
     */
    public static function envelope(string $tool, ProtocolEra $era = ProtocolEra::Legacy, ?string $key = TestCase::TEST_KEY): array
    {
        return FixtureTools::envelope(self::call($tool, $era, $key));
    }

    /**
     * Every string in a decoded JSON value, joined: what a search for a leaked secret must look through.
     */
    public static function strings(mixed $value): string
    {
        if (is_string($value)) {
            return $value."\n";
        }

        if (! is_array($value)) {
            return '';
        }

        $out = '';

        foreach ($value as $key => $item) {
            $out .= (is_string($key) ? $key."\n" : '').self::strings($item);
        }

        return $out;
    }
}
