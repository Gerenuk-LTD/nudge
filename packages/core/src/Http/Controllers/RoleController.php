<?php

namespace Gerenuk\Marketing\Http\Controllers;

use Gerenuk\Marketing\Enums\Permission;
use Gerenuk\Marketing\Models\Role;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    public function index(): Response
    {
        return Inertia::render('marketing/workspace/roles', [
            'roles' => Role::query()->withCount('memberships')->orderBy('id')->get()
                ->map(fn (Role $role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'permissions' => $role->is_owner ? Permission::values() : $role->permissions,
                    'is_owner' => $role->is_owner,
                    'members_count' => $role->memberships_count,
                ]),
            'permissions' => collect(Permission::cases())
                ->map(fn (Permission $permission) => ['value' => $permission->value, 'label' => $permission->label()]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Role::create($this->validated($request));

        return to_route('marketing.roles.index');
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_if($role->is_owner, 403, __('The owner role always has every permission.'));

        $role->update($this->validated($request, $role));

        return to_route('marketing.roles.index');
    }

    public function destroy(Role $role): RedirectResponse
    {
        abort_if($role->is_owner, 403);

        if ($role->memberships()->exists()) {
            throw ValidationException::withMessages(['role' => __('Move members to another role before deleting this one.')]);
        }

        $role->delete();

        return to_route('marketing.roles.index');
    }

    /**
     * @return array{name: string, permissions: list<string>}
     */
    private function validated(Request $request, ?Role $role = null): array
    {
        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:50',
                Rule::unique('roles', 'name')->where('workspace_id', $this->currentWorkspace->id())->ignore($role?->id),
            ],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::enum(Permission::class)],
        ]);

        return ['name' => $validated['name'], 'permissions' => array_values(array_unique($validated['permissions']))];
    }
}
