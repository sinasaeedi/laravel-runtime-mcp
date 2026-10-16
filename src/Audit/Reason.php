<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Audit;

/**
 * Spec 5.7: why a request or a call was denied. The values are what teams alert on, so they are
 * stable: a later story adds a case, and no case is ever renamed or removed (spec 10.1).
 */
enum Reason: string
{
    // HTTP layer (01-06, 01-07).
    case MissingCredentials = 'missing_credentials';
    case BlankCredentials = 'blank_credentials';
    case UnsupportedScheme = 'unsupported_scheme';
    case UnknownKey = 'unknown_key';
    case LinkedUserMissing = 'linked_user_missing';
    case BadHost = 'bad_host';
    case BadOrigin = 'bad_origin';

    // Pipeline (01-08, 01-09, 01-17).
    case NoPrincipal = 'no_principal';
    case NoGate = 'no_gate';
    case GateDenied = 'gate_denied';
    case GateError = 'gate_error';
    case RateLimited = 'rate_limited';
}
