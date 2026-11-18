<?php

namespace Gerenuk\Nudge\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Gerenuk\Nudge\Http\Controllers\Controller;
use Gerenuk\Nudge\Models\ApiToken;
use Gerenuk\Nudge\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Account')]
class MeController extends Controller
{
    /**
     * Identify the token
     *
     * The workspace this token belongs to, and the token's name and permissions. Works with any valid token, so it is a cheap way to check a token.
     */
    #[Response(200, 'The token\'s workspace and permissions.', type: 'array{data: array{workspace: array{id: int, name: string}, token: array{name: string, permissions: list<string>}}}')]
    public function __invoke(Request $request, CurrentWorkspace $currentWorkspace): JsonResponse
    {
        /** @var ApiToken $token */
        $token = $request->attributes->get('nudge_api_token');
        $workspace = $currentWorkspace->getOrFail();

        return response()->json(['data' => [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'token' => ['name' => $token->name, 'permissions' => $token->permissions],
        ]]);
    }
}
