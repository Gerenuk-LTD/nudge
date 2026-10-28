<?php

namespace Gerenuk\Marketing\Http\Controllers;

use Gerenuk\Marketing\Actions\Workspaces\CreateApiToken;
use Gerenuk\Marketing\Enums\Permission;
use Gerenuk\Marketing\Models\ApiToken;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ApiTokenController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    public function index(Request $request): Response
    {
        return Inertia::render('marketing/workspace/api-tokens', [
            'tokens' => ApiToken::query()->latest('id')->get()->map(fn (ApiToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'permissions' => $token->permissions,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
                'created_at' => $token->created_at?->toIso8601String(),
            ]),
            'permissions' => collect($this->grantablePermissions($request))
                ->map(fn (Permission $permission) => ['value' => $permission->value, 'label' => $permission->label()])
                ->values(),
        ]);
    }

    public function store(Request $request, CreateApiToken $createApiToken): RedirectResponse
    {
        $grantable = array_map(fn (Permission $permission) => $permission->value, $this->grantablePermissions($request));

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::in($grantable)],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        ['plainToken' => $plainToken] = $createApiToken->handle(
            $this->currentWorkspace->getOrFail(),
            $this->user($request),
            $validated['name'],
            array_values(array_unique($validated['permissions'])),
            isset($validated['expires_in_days']) ? now()->addDays((int) $validated['expires_in_days']) : null,
        );

        Inertia::flash('plainToken', $plainToken);

        return to_route('marketing.api-tokens.index');
    }

    public function destroy(ApiToken $apiToken): RedirectResponse
    {
        $apiToken->delete();

        return to_route('marketing.api-tokens.index');
    }

    /**
     * A token can only carry permissions its creator has.
     *
     * @return list<Permission>
     */
    private function grantablePermissions(Request $request): array
    {
        $workspace = $this->currentWorkspace->getOrFail();

        return array_values(array_filter(
            Permission::cases(),
            fn (Permission $permission) => $this->user($request)->hasWorkspacePermission($workspace, $permission),
        ));
    }
}
