<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Redaction;

/**
 * Spec 5.6: the built-in secret patterns. Always on; no setting removes one (rule 12).
 *
 * Applied in this order, more specific first: a JWT or a known key after "Bearer" gets its own type,
 * and only an opaque token gets "bearer". Each regex replaces its whole match with the marker; `\K`
 * and a lookahead keep "Bearer ", the URL scheme, the user and the "@" outside the match.
 * No `/u` modifier: a log line with invalid UTF-8 must not make a pattern fail.
 */
final class Patterns
{
    /** Marker type => regex. */
    public const BUILT_IN = [
        // A PEM block, multi-line; also a block cut off before its END line.
        'private_key' => '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----.*?(?:-----END [A-Z0-9 ]*PRIVATE KEY-----|\z)/s',
        // Three base64url segments, the first two starting with eyJ ('{"').
        'jwt' => '/\beyJ[A-Za-z0-9_-]{5,}\.eyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]*/',
        // AKIA (long-term) and ASIA (temporary) access key IDs.
        'aws_access_key' => '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
        'stripe_key' => '/\b(?:sk|rk)_live_[0-9A-Za-z]{10,}\b/',
        'github_token' => '/\b(?:gh[pos]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{20,})\b/',
        'slack_token' => '/\bxox[a-z]-[A-Za-z0-9-]{10,}/',
        // Before openai_key, which would also match sk-ant-.
        'anthropic_key' => '/\bsk-ant-[A-Za-z0-9_-]{20,}/',
        'openai_key' => '/\bsk-[A-Za-z0-9_-]{20,}/',
        // This package's own keys: rmcp_ + base64url (spec 5.3, 01-06).
        'runtime_mcp_key' => '/\brmcp_[A-Za-z0-9_-]{32,}/',
        // Any other token after "Bearer". "|" is in the class: a Sanctum token is "<id>|<token>".
        'bearer' => '/\bbearer\s+\K[A-Za-z0-9._~+\/=|-]{8,}/i',
        // The password in scheme://user:pass@host. The user may be empty (redis://:pass@host), and the
        // user and the password may hold "@": the greedy class ends at the last "@" before the host.
        // ":\/\/" is the "://" of a URL inside JSON text with escaped slashes.
        'password' => '/\b[a-z][a-z0-9+.-]*:(?:\\\\?\/){2}[^\s:\/?#]*:\K[^\s\/?#]+(?=@)/i',
    ];
}
