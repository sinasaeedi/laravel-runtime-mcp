<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Mcp\Wrapped;

use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;
use LogicException;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;
use Throwable;

/**
 * Spec 5.16 (01-38): one official laravel/mcp tool behind the guard pipeline. It is a RuntimeTool, so McpToolAdapter
 * wraps it like every other tool: the name, the tier, the annotations, the Gate, the envelope and the outputSchema are
 * this package's. The official tool keeps its handle(), its schema() and its own validation.
 *
 * Built per request by WrappedTools::build(), so the constructor stays cheap (no database, no I/O).
 */
final class WrappedTool extends RuntimeTool
{
    public function __construct(
        private readonly Tool $tool,
        private readonly string $name,
        private readonly Tier $tier,
        private readonly bool $openWorld,
        private readonly Container $container,
    ) {}

    /** The official class: tools.unique and the description warning name it, never WrappedTool. */
    public function wrappedClass(): string
    {
        return $this->tool::class;
    }

    /** The config `name`: our rule, never laravel/mcp's kebab-case default. */
    public function name(): string
    {
        return $this->name;
    }

    /** The official description (#[Description], the property, or the class-name fallback); the adapter appends UNTRUSTED. */
    public function description(): string
    {
        return $this->tool->description();
    }

    public function tier(): Tier
    {
        return $this->tier;
    }

    public function opensWorld(): bool
    {
        return $this->openWorld;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return $this->tool->schema($schema);
    }

    /**
     * One `sometimes` rule per top-level property of the official schema, so 01-08's unknown-key check still refuses an
     * argument the schema does not name. The values are the official tool's to validate, in handle() (spec 3.2), and
     * `sometimes` keeps every value exactly as sent.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (array_keys($this->tool->schema(new JsonSchemaTypeFactory)) as $key) {
            $rules[(string) $key] = ['sometimes'];
        }

        return $rules;
    }

    /**
     * The tool's own shouldRegister(), through laravel/mcp. McpToolAdapter::shouldRegister() calls it during tools/list,
     * outside any try, and laravel/mcp rethrows in debug mode (spec 3.2): a throw is reported and hides the tool, as
     * ToolGate does for a Gate that throws (01-09).
     */
    public function available(): bool
    {
        try {
            return $this->tool->eligibleForRegistration();
        } catch (Throwable $e) {
            $this->report($e);

            return false;
        }
    }

    /**
     * Stage 5 for the official tool, after RunTool has asked available() (a refusal is denied there, as `not_available`,
     * before the arguments are checked). Its Request is bound as mcp.request for the whole call: with a progressToken the
     * pipeline runs inside 01-37's generator, after Server::runMethodHandle() has forgotten laravel/mcp's binding, so a
     * Request resolved there would be empty. The value is converted inside the binding and inside the read-only
     * transaction, so a Generator runs to its end here.
     */
    public function execute(ToolInput $input): ToolResult
    {
        // laravel/mcp's Tool declares no handle(); wrapped_tools.class has checked that the class has one.
        $handle = [$this->tool, 'handle'];

        if (! is_callable($handle)) {
            throw new LogicException('['.$this->tool::class.'] has no handle() method.');
        }

        $arguments = [];

        foreach ($input->all() as $key => $value) {
            $arguments[(string) $key] = $value;
        }

        $outer = $this->container->bound('mcp.request') ? $this->container->make('mcp.request') : null;
        $this->container->instance('mcp.request', new Request($arguments));

        try {
            return $this->container->make(ResponseConverter::class)->convert($this->container->call($handle));
        } finally {
            // Put back what was there, exactly as ToolSearch::invokeTool() does for an inner call.
            if (is_object($outer)) {
                $this->container->instance('mcp.request', $outer);
            } else {
                $this->container->forgetInstance('mcp.request');
            }
        }
    }

    private function report(Throwable $e): void
    {
        try {
            $this->container->make(ExceptionHandler::class)->report($e);
        } catch (Throwable) {
            // Nothing left to tell. The tool stays hidden.
        }
    }
}
