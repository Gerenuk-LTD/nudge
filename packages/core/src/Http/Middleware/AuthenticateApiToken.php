<?php

namespace Gerenuk\Marketing\Http\Middleware;

use Closure;
use Gerenuk\Marketing\Models\ApiToken;
use Gerenuk\Marketing\Models\Workspace;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a bearer API token and makes its workspace current.
 */
class AuthenticateApiToken
{
    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plainToken = $request->bearerToken();
        $token = $plainToken !== null ? ApiToken::findByPlainToken($plainToken) : null;

        if ($token === null || $token->isExpired()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $this->currentWorkspace->set(Workspace::findOrFail($token->workspace_id));
        $request->attributes->set('marketing_api_token', $token);

        return $next($request);
    }
}
