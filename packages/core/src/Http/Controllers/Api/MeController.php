<?php

namespace Gerenuk\Marketing\Http\Controllers\Api;

use Gerenuk\Marketing\Http\Controllers\Controller;
use Gerenuk\Marketing\Models\ApiToken;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request, CurrentWorkspace $currentWorkspace): JsonResponse
    {
        /** @var ApiToken $token */
        $token = $request->attributes->get('marketing_api_token');
        $workspace = $currentWorkspace->getOrFail();

        return response()->json(['data' => [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'token' => ['name' => $token->name, 'permissions' => $token->permissions],
        ]]);
    }
}
