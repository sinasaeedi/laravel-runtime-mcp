<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Testing;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use PHPUnit\Framework\Assert;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;

/**
 * Spec 7.5 (01-39): the test kit for a team's own tools, wrapped tools and prompts.
 *
 *     RuntimeMcp::test()->as('sina')->call('order_status_from_erp', ['order' => 'A-1'])->assertStatus('ok')->assertAudited();
 *     RuntimeMcp::assertToolContract(OrderStatusFromErp::class);
 *
 * Every call runs through the real tools/call and prompts/get handlers and the full guard pipeline (rule 3). It skips
 * only the HTTP middleware: it sets the principal directly. That is the one danger, so the kit runs only while the app
 * runs its tests (Part C): anywhere else it throws, and it registers nothing in the service provider (rule 12).
 */
final class RuntimeMcp
{
    /** %s is the class name given. */
    public const NOT_A_RUNTIME_TOOL = '[%s] is not a RuntimeTool. A wrapped tool keeps its own validation; test it with call().';

    /** A new chain with no principal: call() then gets the real no_principal denial. */
    public static function test(): PendingRuntimeMcp
    {
        $app = self::guard();

        return new PendingRuntimeMcp($app, AuditRecorder::listen($app->make(Dispatcher::class)));
    }

    /**
     * The 01-18 contract checks (name rule, ability, description, annotations, schema against rules both ways, and a
     * numeric rule for every schema maximum) for a custom tool. A custom tool has no plugin prefix (01-29).
     *
     * A class that is not a RuntimeTool, also one that does not exist, fails (Sina, 2026-11-02): a wrapped laravel/mcp
     * tool has only ['sometimes'] rules, so the schema check would fail on every limit, and a skipped check would pass
     * a tool that was not checked.
     */
    public static function assertToolContract(string $toolClass): void
    {
        $app = self::guard();

        // Checked before anything is built: a missing class is no RuntimeTool either.
        $tool = is_subclass_of($toolClass, RuntimeTool::class) ? $app->make($toolClass) : null;

        if (! $tool instanceof RuntimeTool) {
            Assert::fail(sprintf(self::NOT_A_RUNTIME_TOOL, $toolClass));
        }

        ToolContract::assert($tool);

        // ToolContract only fails. One counted assertion on the pass path, so PHPUnit never marks the team's test "risky"
        // (no assertions). assertThat() has no type assertion, so PHPStan does not call it always true.
        Assert::assertThat(true, Assert::isTrue());
    }

    /**
     * Part C: the kit sets a principal without a key, so it runs only in the app's tests (APP_ENV=testing; Laravel's
     * runningUnitTests() reads the env binding, 12.69 and 13.36). Then it needs PHPUnit, which every test run has.
     */
    private static function guard(): Container
    {
        $app = Container::getInstance();

        if (! $app instanceof Application || ! $app->runningUnitTests()) {
            throw RuntimeMcpTestKitMisused::outsideTests();
        }

        if (! class_exists('PHPUnit\Framework\Assert')) {
            throw RuntimeMcpTestKitMisused::noPhpUnit();
        }

        return $app;
    }
}
