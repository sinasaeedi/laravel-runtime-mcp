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
    case UnsupportedAuthDriver = 'unsupported_auth_driver';
    case BadHost = 'bad_host';
    case BadOrigin = 'bad_origin';

    // HTTP layer, the sanctum driver (01-35).
    case UnknownToken = 'unknown_token';
    case TokenExpired = 'token_expired';
    case TokenMissingAbility = 'token_missing_ability';
    case TokenOwnerInvalid = 'token_owner_invalid';
    case TokenLookupFailed = 'token_lookup_failed';

    // Pipeline (01-08, 01-09, 01-17).
    case NoPrincipal = 'no_principal';
    case NoGate = 'no_gate';
    case GateDenied = 'gate_denied';
    case GateError = 'gate_error';
    case RateLimited = 'rate_limited';

    // The tools/call handler and model Policies (01-09).
    case UnknownTool = 'unknown_tool';
    case PolicyDenied = 'policy_denied';
}
