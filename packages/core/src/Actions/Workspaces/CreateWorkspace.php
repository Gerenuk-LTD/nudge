<?php

namespace Gerenuk\Marketing\Actions\Workspaces;

use Gerenuk\Marketing\Contracts\MarketingUser;
use Gerenuk\Marketing\Enums\Permission;
use Gerenuk\Marketing\Events\WorkspaceCreated;
use Gerenuk\Marketing\Models\Account;
use Gerenuk\Marketing\Models\Membership;
use Gerenuk\Marketing\Models\Role;
use Gerenuk\Marketing\Models\Workspace;
use Illuminate\Support\Facades\DB;

class CreateWorkspace
{
    /**
     * Create a workspace with the default roles and make the user its owner.
     * A new account is created when none is given.
     */
    public function handle(MarketingUser $owner, string $name, ?Account $account = null): Workspace
    {
        $workspace = DB::transaction(function () use ($owner, $name, $account): Workspace {
            $account ??= Account::create(['name' => $name, 'owner_id' => $owner->getAuthIdentifier()]);

            $workspace = $account->workspaces()->create(['name' => $name]);

            $ownerRole = null;

            foreach (self::defaultRoles() as $roleName => [$permissions, $isOwner]) {
                $role = Role::withoutGlobalScopes()->create([
                    'workspace_id' => $workspace->id,
                    'name' => $roleName,
                    'permissions' => $permissions,
                    'is_owner' => $isOwner,
                ]);

                $ownerRole ??= $isOwner ? $role : null;
            }

            Membership::create([
                'workspace_id' => $workspace->id,
                'user_id' => $owner->getAuthIdentifier(),
                'role_id' => $ownerRole->id,
            ]);

            if ($owner->currentWorkspaceId() === null) {
                $owner->switchWorkspace($workspace);
            }

            return $workspace;
        });

        $owner->forgetResolvedMemberships();

        WorkspaceCreated::dispatch($workspace, $owner);

        return $workspace;
    }

    /**
     * @return array<string, array{0: list<string>, 1: bool}>
     */
    public static function defaultRoles(): array
    {
        $everything = Permission::values();

        return [
            'Owner' => [$everything, true],
            'Admin' => [$everything, false],
            'Editor' => [[
                Permission::ContactsView->value,
                Permission::ContactsManage->value,
                Permission::ContactsImport->value,
                Permission::SegmentsManage->value,
            ], false],
            'Viewer' => [[Permission::ContactsView->value], false],
        ];
    }
}
