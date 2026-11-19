<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use LogicException;
use SinaSaeedi\RuntimeMcp\Mcp\McpToolAdapter;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Spec 7.4 (01-29): scaffolds a custom tool in the app on RuntimeTool, so the guard pipeline applies. It asks for the
 * tier and whether the tool calls an external system, and the adapter derives the annotations from both (01-08).
 *
 * Built on Laravel's GeneratorCommand, as laravel/mcp's make:mcp-tool: the app's root namespace, the reserved-word
 * check, --force and the folder. Every check runs before anything is asked or written, and every refusal exits 1
 * through Command::fail() (one error line, exit code 1): GeneratorCommand returns false for its own errors, and
 * Command::execute() casts that to exit code 0. handle() keeps the parent's bool|null type; null is exit code 0.
 *
 * No write tier (rule 10): Tier has no write case, and --tier=write_safe gets the v1.13.0 message. 01-39 adds the test
 * file and --no-test.
 */
final class MakeToolCommand extends GeneratorCommand
{
    public const NAME = 'runtime-mcp:make-tool';

    /** The stub, relative to the package root and, once published (runtime-mcp-stubs), to the app's base path. */
    public const STUB = 'stubs/runtime-mcp-tool.stub';

    /** One StudlyCase class name: no folder, no namespace, no dash. */
    public const CLASS_PATTERN = '/^[A-Z][A-Za-z0-9]*$/D';

    public const BAD_CLASS = 'The name must be one StudlyCase class name, for example OrderStatusFromErp.';

    /** %1$s is the class, %2$s the tool name that it gives. */
    public const BAD_TOOL_NAME = 'The tool name from %1$s would be "%2$s", which is not lower snake case of at most 64 characters. Choose another class name.';

    public const RESERVED = 'The name %s is reserved by PHP.';

    /** %s is the path of the file. */
    public const EXISTS = 'The file %s already exists. Pass --force to replace it.';

    public const NOT_WRITTEN = 'The tool file was not written.';

    public const WRITE_TIER = 'Write tools arrive in v1.13.0, with a human approval for every call.';

    public const BAD_TIER = 'The tier must be read or read_sensitive.';

    public const TIER_NEEDED = 'Pass --tier=read or --tier=read_sensitive.';

    public const TIER_QUESTION = 'Which tier does this tool have?';

    /** Shown before the tier question, one line per tier. */
    public const TIER_HELP = [
        'read: harmless facts, like versions or counts.',
        'read_sensitive: data about customers, orders, logs.',
    ];

    public const OPEN_WORLD_QUESTION = 'Does this tool call an external system (an API, SOAP, another database)?';

    /** %1$s is the tool name, %2$s its ability. */
    public const TOOL_NAME = 'Tool name: %1$s (Gate: %2$s). Change name() in the file if you want another name.';

    public const NEXT = 'Next steps:';

    /** %s is the class. */
    public const STEP_REGISTER = '  1. Add %s::class to runtime-mcp.tools in config/runtime-mcp.php.';

    /** %s is the ability. */
    public const STEP_GATE = "  2. Define the Gate: Gate::define('%s', fn (Principal|User \$who) => …); // Principal: SinaSaeedi\\RuntimeMcp\\Principal";

    public const STEP_TEST = '  3. Write a test that calls it through the server.';

    /** Spec 5.9 (01-16): the read-only transaction covers only the default connection and the listed ones. */
    public const CLAIM = 'The tier is your claim: the package blocks database writes inside the tool on the default connection and on each connection in runtime-mcp.read_only_transactions.connections (add every other connection the tool uses there), but not HTTP, SOAP, Redis or file writes.';

    /** Printed when the tool does not call an external system. */
    public const OPEN_WORLD_HINT = 'If the tool calls an external system, return true from opensWorld().';

    /** @var string */
    protected $signature = self::NAME.' {name : The class name, for example OrderStatusFromErp}'
        .' {--tier= : read or read_sensitive}'
        .' {--open-world : The tool calls an external system (an API, SOAP, another database)}'
        .' {--force : Replace the file when it exists}';

    /** @var string */
    protected $description = 'Create a Runtime MCP tool that runs behind the guard pipeline';

    /** @var string */
    protected $type = 'Runtime MCP tool';

    /** The answers for buildClass(), set in handle() before the parent writes the file. */
    private ?Tier $tier = null;

    private bool $opensWorld = false;

    private string $toolName = '';

    public function handle(): ?bool
    {
        $class = $this->getNameInput();

        if (preg_match(self::CLASS_PATTERN, $class) !== 1) {
            $this->refuse(self::BAD_CLASS);
        }

        if ($this->isReservedName($class)) {
            $this->refuse(sprintf(self::RESERVED, $class));
        }

        $tool = self::toolName($class);

        if (preg_match(McpToolAdapter::NAME_PATTERN, $tool) !== 1) {
            $this->refuse(sprintf(self::BAD_TOOL_NAME, $class, $tool));
        }

        $path = $this->getPath($this->qualifyClass($class));

        if ($this->option('force') !== true && $this->files->exists($path)) {
            $this->refuse(sprintf(self::EXISTS, $path));
        }

        $this->tier = $this->askTier();
        $this->opensWorld = $this->askOpensWorld();
        $this->toolName = $tool;

        // The checks above cover the parent's own refusals (a PHP word, an existing file); this is defence in depth.
        if (parent::handle() === false) {
            $this->refuse(self::NOT_WRITTEN);
        }

        $this->nextSteps($this->qualifyClass($class), $tool);

        return null;
    }

    /** Spec 7.4: the snake_case class name without a trailing "Tool": OrderStatusFromErp → order_status_from_erp. */
    public static function toolName(string $class): string
    {
        return Str::snake(Str::replaceEnd('Tool', '', $class));
    }

    protected function getStub(): string
    {
        $published = $this->laravel->basePath(self::STUB);

        return $this->files->exists($published) ? $published : dirname(__DIR__, 2).'/'.self::STUB;
    }

    /** @param  string  $rootNamespace */
    protected function getDefaultNamespace($rootNamespace): string
    {
        // Not app/Mcp/Tools: laravel/mcp's make:mcp-tool writes there, and runtime-mcp.tools refuses its tools.
        return $rootNamespace.'\\RuntimeMcp\\Tools';
    }

    /** @param  string  $name */
    protected function buildClass($name): string
    {
        if ($this->tier === null) {
            throw new LogicException('buildClass() runs only after handle() has the tier.');
        }

        return str_replace(
            ['{{ tool }}', '{{ tier }}', '{{ openWorld }}'],
            [$this->toolName, $this->tier->name, $this->opensWorld ? 'true' : 'false'],
            parent::buildClass($name),
        );
    }

    private function askTier(): Tier
    {
        $value = $this->option('tier');

        if ($value === null) {
            if (! $this->input->isInteractive()) {
                $this->refuse(self::TIER_NEEDED);
            }

            foreach (self::TIER_HELP as $line) {
                $this->line($line);
            }

            $value = $this->choice(self::TIER_QUESTION, [Tier::Read->value, Tier::ReadSensitive->value]);
        }

        if ($value === 'write_safe') {
            $this->refuse(self::WRITE_TIER);
        }

        return (is_string($value) ? Tier::tryFrom($value) : null) ?? $this->refuse(self::BAD_TIER);
    }

    private function askOpensWorld(): bool
    {
        if ($this->option('open-world') === true) {
            return true;
        }

        return $this->input->isInteractive() && $this->confirm(self::OPEN_WORLD_QUESTION, false);
    }

    private function nextSteps(string $class, string $tool): void
    {
        $ability = 'runtime-mcp.'.$tool;

        $this->text(sprintf(self::TOOL_NAME, $tool, $ability));
        $this->text(self::NEXT);
        $this->text(sprintf(self::STEP_REGISTER, $class));
        $this->text(sprintf(self::STEP_GATE, $ability));
        $this->text(self::STEP_TEST);
        $this->text(self::CLAIM);

        if (! $this->opensWorld) {
            $this->text(self::OPEN_WORLD_HINT);
        }
    }

    /** One error line and exit code 1: Command::run() catches the ManuallyFailedException that fail() throws. */
    private function refuse(string $message): never
    {
        $this->fail(OutputFormatter::escape($message));
    }

    /** Plain text: a class name or a "<" in the Gate example is never read as an output tag. */
    private function text(string $line): void
    {
        $this->line(OutputFormatter::escape($line));
    }
}
