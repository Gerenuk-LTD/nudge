<?php

namespace Gerenuk\Nudge\Http\Middleware;

use Closure;
use Gerenuk\Nudge\Enums\Permission;
use Gerenuk\Nudge\Http\ApiError;
use Gerenuk\Nudge\Http\ApiErrorCode;
use Gerenuk\Nudge\Models\ApiToken;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTokenCan
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $token = $request->attributes->get('nudge_api_token');

        if (! $token instanceof ApiToken || ! $token->grants(Permission::from($permission))) {
            return ApiError::response(ApiErrorCode::MissingPermission, "This token does not have the {$permission} permission.", 403);
        }

        return $next($request);
    }
}
