<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts;

use SinaSaeedi\RuntimeMcp\Prompts\Contracts\RuntimePrompt;

/** A RuntimePrompt subclass that cannot be built: prompts.class refuses it (not instantiable). */
abstract class AbstractFixturePrompt extends RuntimePrompt {}
