<?php

namespace Gerenuk\Marketing\Http\Controllers;

use Gerenuk\Marketing\Actions\Workspaces\CreateWorkspace;
use Gerenuk\Marketing\Enums\Permission;
use Gerenuk\Marketing\Models\ApiToken;
use Gerenuk\Marketing\Models\Invitation;
use Gerenuk\Marketing\Models\Role;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    public function index(Request $request): Response
    {
        $order = array_flip(array_keys(CreateWorkspace::defaultRoles()));
        $actor = $this->actingRole($request);

        return Inertia::render('marketing/workspace/roles', [
            'roles' => Role::query()->withCount('memberships')->orderBy('id')->get()
                ->sortBy(fn (Role $role) => [$order[$role->key] ?? count($order), $role->name])
                ->values()
                ->map(fn (Role $role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'key' => $role->key,
                    'description' => $role->description,
                    'permissions' => $role->is_owner ? Permission::values() : $role->permissions,
                    'is_owner' => $role->is_owner,
                    'is_builtin' => $role->isBuiltIn(),
                    'members_count' => $role->memberships_count,
                ]),
            'permissions' => collect(Permission::cases())
                ->map(fn (Permission $permission) => ['value' => $permission->value, 'label' => $permission->label(), 'description' => $permission->description()]),
            'grantable' => $actor->is_owner ? Permission::values() : $actor->permissions,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->actingRole($request);

        Role::create($this->validated($request, $actor));

        return to_route('marketing.roles.index');
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_if($role->is_owner, 403, __('The owner role always has every permission.'));

        $actor = $this->actingRole($request);

        abort_unless($role->isWithin($actor), 403);

        $validated = $this->validated($request, $actor, $role);

        if ($role->isBuiltIn() && $validated['name'] !== $role->name) {
            throw ValidationException::withMessages(['name' => __('Built-in roles keep their name.')]);
        }

        DB::transaction(function () use ($role, $validated): void {
            $role->update($validated);

            // Members' API tokens can't keep permissions the role no longer grants.
            foreach ($role->memberships()->pluck('user_id') as $userId) {
                ApiToken::narrowForUser($role->workspace_id, (int) $userId, $role->permissions);
            }

            // Invitations they sent for roles above the narrowed one would hand out access they no longer have.
            Invitation::withoutGlobalScopes()
                ->with('role')
                ->where('workspace_id', $role->workspace_id)
                ->whereIn('invited_by', $role->memberships()->pluck('user_id'))
                ->get()
                ->reject(fn (Invitation $invitation) => $invitation->role->isWithin($role))
                ->each(fn (Invitation $invitation) => $invitation->delete());
        });

        return to_route('marketing.roles.index');
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        abort_if($role->is_owner || $role->isBuiltIn(), 403);
        abort_unless($role->isWithin($this->actingRole($request)), 403);

        if ($role->memberships()->exists()) {
            throw ValidationException::withMessages(['role' => __('Move members to another role before deleting this one.')]);
        }

        $role->delete();

        return to_route('marketing.roles.index');
    }

    private function actingRole(Request $request): Role
    {
        $actor = $this->user($request)->membershipFor($this->currentWorkspace->getOrFail())?->role;

        abort_if($actor === null, 403);

        return $actor;
    }

    /**
     * @return array{name: string, description: string|null, permissions: list<string>}
     */
    private function validated(Request $request, Role $actor, ?Role $role = null): array
    {
        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:50',
                Rule::unique('roles', 'name')->where('workspace_id', $this->currentWorkspace->id())->ignore($role?->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::enum(Permission::class)],
        ]);

        $permissions = array_values(array_unique($validated['permissions']));

        if (! $actor->is_owner && array_diff($permissions, $actor->permissions) !== []) {
            throw ValidationException::withMessages(['permissions' => __('You can only give out permissions you have yourself.')]);
        }

        return [
            'name' => $validated['name'],
            'description' => $role?->isBuiltIn() ? $role->description : ($validated['description'] ?? null),
            'permissions' => $permissions,
        ];
    }
}
