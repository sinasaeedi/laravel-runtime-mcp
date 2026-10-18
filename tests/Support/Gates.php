<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

use Illuminate\Support\Facades\Gate;

/**
 * Default deny (01-09) hides and denies every tool without a Gate. Tests that are not about the
 * Gate allow the fixture tools here. Call it after bootWith() or rebootApplication(): a new app has
 * a new Gate, with no abilities.
 */
final class Gates
{
    /** Every fixture tool name (01-03 and 01-08). */
    public const FIXTURE_TOOLS = [
        'echo', 'second_echo', 'secret', 'throwing', 'note_less', 'result',
        'fake_ping', 'second_ping', 'optional_ping',
    ];

    /** Defines runtime-mcp.<tool> as a Gate that allows every principal. */
    public static function allow(string ...$tools): void
    {
        foreach ($tools as $tool) {
            Gate::define('runtime-mcp.'.$tool, fn (): bool => true);
        }
    }

    public static function allowFixtureTools(): void
    {
        self::allow(...self::FIXTURE_TOOLS);
    }
}
