<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

/** The two MCP request shapes that laravel/mcp v1.0.2 answers (spec 3.1, 3.2). */
enum ProtocolEra
{
    /** No protocol keys in _meta: the initialize handshake of 2025-11-25 and 2025-06-18. */
    case Legacy;

    /** 2026-07-28: per-request _meta and the Mcp-Method / Mcp-Name headers. */
    case Modern;
}
