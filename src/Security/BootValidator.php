<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use LogicException;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;
use SinaSaeedi\RuntimeMcp\Security\Auth\ApiKey;
use SinaSaeedi\RuntimeMcp\Security\Auth\ApiKeyRing;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootReport;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootRule;
use SinaSaeedi\RuntimeMcp\Security\Boot\Rules\ApiKeysFormat;
use SinaSaeedi\RuntimeMcp\Security\Boot\Rules\ApiKeysPresent;
use SinaSaeedi\RuntimeMcp\Security\Boot\Rules\PluginsInstalled;
use SinaSaeedi\RuntimeMcp\Security\Boot\Rules\ReadOnlyConnection;
use Throwable;

/**
 * Spec 5.11: runs every core rule, then the rules of every active plugin. Reads config only.
 *
 * Never throws: a rule that throws (or cannot be built) becomes an error issue, so a bug in a rule
 * also fails closed. Not bound in the container: built per use, so it never holds a stale config.
 * doctor (01-12) calls the same validate().
 */
final class BootValidator
{
    /**
     * The core rules, in run order. Later stories append: 01-35 (auth.*), 01-29 (tools.*),
     * 01-36 (prompts.*), 01-38 (wrapped_tools.*).
     */
    public const RULES = [
        ApiKeysPresent::class,
        ApiKeysFormat::class,
        ReadOnlyConnection::class,
        PluginsInstalled::class,
    ];

    /** @param  list<class-string<BootRule>>  $rules */
    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
        private readonly PluginRegistry $plugins,
        private readonly array $rules = self::RULES,
    ) {}

    public function validate(): BootReport
    {
        $issues = [];

        foreach ($this->rules as $class) {
            array_push($issues, ...$this->run($class, fn (): BootRule => $this->build($class)));
        }

        array_push($issues, ...$this->pluginIssues());

        return new BootReport($issues, $this->httpReady(), $this->stdioReady());
    }

    /** @return list<BootIssue> */
    private function pluginIssues(): array
    {
        try {
            $plugins = $this->plugins->active();
        } catch (Throwable $e) {
            return [self::couldNotRun('plugins', $e)];
        }

        $issues = [];

        foreach ($plugins as $plugin) {
            $fallback = 'plugins.'.$plugin->name();

            try {
                $rules = $plugin->bootRules();
            } catch (Throwable $e) {
                $issues[] = self::couldNotRun($fallback, $e);

                continue;
            }

            foreach ($rules as $rule) {
                array_push($issues, ...$this->run($fallback, fn (): BootRule => $rule));
            }
        }

        return $issues;
    }

    /**
     * @param  Closure(): BootRule  $rule
     * @return list<BootIssue>
     */
    private function run(string $fallbackId, Closure $rule): array
    {
        $id = $fallbackId;

        try {
            $instance = $rule();
            $id = $instance->id();

            return $instance->check();
        } catch (Throwable $e) {
            return [self::couldNotRun($id, $e)];
        }
    }

    /** @param  class-string<BootRule>  $class */
    private function build(string $class): BootRule
    {
        $rule = $this->container->make($class);

        if (! $rule instanceof BootRule) {
            throw new LogicException("[{$class}] is not a BootRule.");
        }

        return $rule;
    }

    private static function couldNotRun(string $id, Throwable $e): BootIssue
    {
        // The class only: an exception message can hold a config value.
        return BootIssue::error($id, "Rule {$id} could not run: ".$e::class.'.');
    }

    /** Spec 5.11: the api_key driver with no key never registers the HTTP route. 01-35 checks the other drivers. */
    private function httpReady(): bool
    {
        return $this->config->get('runtime-mcp.auth.driver') !== 'api_key'
            || ! ApiKeyRing::fromConfig($this->config)->isEmpty();
    }

    /** Spec 5.3: unset (or not a valid name) = the STDIO server does not start. */
    private function stdioReady(): bool
    {
        return ApiKey::isValidName($this->config->get('runtime-mcp.auth.stdio_principal'));
    }
}
