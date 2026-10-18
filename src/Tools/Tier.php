<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tools;

/**
 * Spec 5.10: the risk tier of a tool. The values are public API.
 *
 * No write_safe case in v1.0.0 (rule 10): v1.13.0 adds it together with its approval flow, so a
 * custom tool (01-29) cannot claim a write tier that has no approval yet.
 */
enum Tier: string
{
    case Read = 'read';
    case ReadSensitive = 'read_sensitive';
}
