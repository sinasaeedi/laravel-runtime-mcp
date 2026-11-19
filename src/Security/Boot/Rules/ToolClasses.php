<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Boot\Rules;

use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootRule;
use SinaSaeedi\RuntimeMcp\Tools\CustomTools;

/**
 * Spec 5.11, 7.4 (01-29): every entry of runtime-mcp.tools is a concrete class that extends RuntimeTool and is not a
 * tool of a plugin of this package (spec 4.2). The message names the position only: a wrong entry could be any text
 * (BootIssue).
 */
final class ToolClasses implements BootRule
{
    public const ID = 'tools.class';

    public const NOT_A_LIST = 'runtime-mcp.tools is not a list of class names.';

    /** %d is the position, from 0. */
    public const NOT_A_TOOL = 'Entry %d of runtime-mcp.tools is not a class that extends RuntimeTool.';

    /** %d is the position, from 0. */
    public const BUILT_IN = 'Entry %d of runtime-mcp.tools is a tool of this package. Turn its plugin on in runtime-mcp.plugins instead.';

    public function __construct(private readonly CustomTools $tools) {}

    public function id(): string
    {
        return self::ID;
    }

    public function check(): array
    {
        if ($this->tools->entries() === null) {
            return [BootIssue::error(self::ID, self::NOT_A_LIST)];
        }

        $issues = [];

        foreach ($this->tools->refusals() as $position => $refusal) {
            $message = $refusal === CustomTools::BUILT_IN ? self::BUILT_IN : self::NOT_A_TOOL;
            $issues[] = BootIssue::error(self::ID, sprintf($message, $position));
        }

        return $issues;
    }
}
