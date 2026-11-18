<?php

namespace Gerenuk\Nudge\Http;

/**
 * Stable, machine-readable error codes of the public API. Add new ones; never rename.
 */
enum ApiErrorCode: string
{
    case Unauthenticated = 'unauthenticated';
    case MissingPermission = 'missing_permission';
    case NotFound = 'not_found';
    case ContactNotFound = 'contact_not_found';
    case MethodNotAllowed = 'method_not_allowed';
    case ValidationFailed = 'validation_failed';
    case InvalidIdempotencyKey = 'invalid_idempotency_key';
    case RateLimited = 'rate_limited';
    case ServerError = 'server_error';
    case HttpError = 'http_error';
}
