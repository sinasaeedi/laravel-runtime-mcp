<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Audit\Reason;

// Teams alert on these codes (spec 5.7). A later story may add a case; it never renames one.
it('has the twelve stable reason codes of v1.0.0', function () {
    expect(array_map(fn (Reason $reason): string => $reason->value, Reason::cases()))->toBe([
        'missing_credentials', 'blank_credentials', 'unsupported_scheme', 'unknown_key',
        'linked_user_missing', 'bad_host', 'bad_origin',
        'no_principal', 'no_gate', 'gate_denied', 'gate_error', 'rate_limited',
    ]);
});
