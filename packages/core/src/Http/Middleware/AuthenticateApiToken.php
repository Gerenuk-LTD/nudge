<?php

namespace Gerenuk\Nudge\Http\Middleware;

use Closure;
use Gerenuk\Nudge\Http\ApiError;
use Gerenuk\Nudge\Http\ApiErrorCode;
use Gerenuk\Nudge\Models\ApiToken;
use Gerenuk\Nudge\Models\Workspace;
use Gerenuk\Nudge\Tenancy\CurrentWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a bearer API token and makes its workspace current.
 */
class AuthenticateApiToken
{
    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    public function handle(Request $request, Closure $next): Response
    {
        $failureKey = 'nudge-api-auth:'.$request->ip();

        if (RateLimiter::tooManyAttempts($failureKey, 30)) {
            return ApiError::response(ApiErrorCode::RateLimited, 'Too many requests.', 429, ['Retry-After' => (string) RateLimiter::availableIn($failureKey)]);
        }

        $plainToken = $request->bearerToken();
        $token = $plainToken !== null ? ApiToken::findByPlainToken($plainToken) : null;

        if ($token === null || $token->isExpired()) {
            RateLimiter::hit($failureKey, 60);

            return ApiError::response(ApiErrorCode::Unauthenticated, 'Unauthenticated.', 401);
        }

        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $this->currentWorkspace->set(Workspace::findOrFail($token->workspace_id));
        $request->attributes->set('nudge_api_token', $token);

        return $next($request);
    }
}
