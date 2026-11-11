<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Logs;

use Illuminate\Contracts\Config\Repository;

/**
 * Spec 4.5, 6.9, 01-25: runtime-mcp.logs. A bad value is replaced at run time and fails in doctor (LogsCheck), as
 * 01-17's output.max_bytes: the operator would think another budget is in force.
 */
final readonly class LogSettings
{
    public const DEFAULT_MAX_BYTES = 200_000;

    public const MIN_MAX_BYTES = 10_000;

    /** A channel name the tool offers and shows (Sina, 2026-10-20: print a config value only when it has the shape of a name). */
    public const NAME = '/^[A-Za-z0-9_.-]{1,64}$/D';

    public function __construct(private Repository $config) {}

    /** The read budget of one call, in bytes. */
    public function maxBytes(): int
    {
        return $this->configuredMaxBytes() ?? self::DEFAULT_MAX_BYTES;
    }

    public function maxBytesIsValid(): bool
    {
        return $this->configuredMaxBytes() !== null;
    }

    /**
     * The channels of runtime-mcp.logs.channels that the tool offers: name-shaped strings, each once, in order.
     *
     * @return list<string>
     */
    public function channels(): array
    {
        $channels = [];

        foreach ($this->entries() as $entry) {
            if (is_string($entry) && preg_match(self::NAME, $entry) === 1 && ! in_array($entry, $channels, true)) {
                $channels[] = $entry;
            }
        }

        return $channels;
    }

    /**
     * The 1-based positions of the entries that are not channel names (01-12: name the position, never the value).
     *
     * @return list<int>
     */
    public function badPositions(): array
    {
        $positions = [];

        foreach (array_values($this->entries()) as $index => $entry) {
            if (! is_string($entry) || preg_match(self::NAME, $entry) !== 1) {
                $positions[] = $index + 1;
            }
        }

        return $positions;
    }

    /** @return array<array-key, mixed> */
    private function entries(): array
    {
        $entries = $this->config->get('runtime-mcp.logs.channels');

        return is_array($entries) ? $entries : [];
    }

    private function configuredMaxBytes(): ?int
    {
        $value = $this->config->get('runtime-mcp.logs.max_bytes');

        return is_int($value) && $value >= self::MIN_MAX_BYTES && $value < PHP_INT_MAX ? $value : null;
    }
}
