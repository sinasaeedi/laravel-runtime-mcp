<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tools;

use RuntimeException;
use SinaSaeedi\RuntimeMcp\Audit\Reason;

/**
 * Spec 5.5 (01-21): a check inside a tool said no, for example the model's `view` Policy. RunTool turns it
 * into a denied outcome, so the audit records `decision: denied` with the reason, never a tool error. The
 * message is the agent's note: already redacted and cut by the thrower. It is never reported.
 */
final class AuthorizationDenied extends RuntimeException
{
    public function __construct(public readonly Reason $reason, string $note)
    {
        parent::__construct($note);
    }
}
