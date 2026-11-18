<?php

namespace Gerenuk\Nudge\Actions\Workspaces;

use Gerenuk\Nudge\Contracts\NudgeUser;
use Gerenuk\Nudge\Enums\Permission;
use Gerenuk\Nudge\Events\WorkspaceCreated;
use Gerenuk\Nudge\Models\Account;
use Gerenuk\Nudge\Models\Membership;
use Gerenuk\Nudge\Models\Role;
use Gerenuk\Nudge\Models\Workspace;
use Illuminate\Support\Facades\DB;

class CreateWorkspace
{
    /**
     * Create a workspace with the default roles and make the user its owner.
     * A new account is created when none is given.
     */
    public function handle(NudgeUser $owner, string $name, ?Account $account = null): Workspace
    {
        $workspace = DB::transaction(function () use ($owner, $name, $account): Workspace {
            $account ??= Account::create(['name' => $name, 'owner_id' => $owner->getAuthIdentifier()]);

            $workspace = $account->workspaces()->create(['name' => $name]);

            $ownerRole = null;

            foreach (self::defaultRoles() as $key => $definition) {
                $role = Role::withoutGlobalScopes()->create([
                    'workspace_id' => $workspace->id,
                    'name' => $definition['name'],
                    'key' => $key,
                    'description' => $definition['description'],
                    'permissions' => $definition['permissions'],
                    'is_owner' => $definition['is_owner'],
                ]);

                $ownerRole ??= $definition['is_owner'] ? $role : null;
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
     * @return array<string, array{name: string, description: string, permissions: list<string>, is_owner: bool}>
     */
    public static function defaultRoles(): array
    {
        $everything = Permission::values();

        return [
            'owner' => ['name' => 'Owner', 'description' => 'Full control of the workspace, including making other people owners.', 'permissions' => $everything, 'is_owner' => true],
            'admin' => ['name' => 'Admin', 'description' => 'Runs the workspace day to day: members, roles, sending, campaigns and contacts.', 'permissions' => $everything, 'is_owner' => false],
            'editor' => ['name' => 'Editor', 'description' => 'Builds and sends campaigns and automations, and looks after contacts.', 'permissions' => [
                Permission::ContactsView->value,
                Permission::ContactsManage->value,
                Permission::ContactsImport->value,
                Permission::SegmentsManage->value,
                Permission::CampaignsView->value,
                Permission::CampaignsManage->value,
                Permission::CampaignsSend->value,
                Permission::AutomationsView->value,
                Permission::AutomationsManage->value,
            ], 'is_owner' => false],
            'viewer' => ['name' => 'Viewer', 'description' => 'Sees contacts, campaigns and automations without changing anything.', 'permissions' => [Permission::ContactsView->value, Permission::CampaignsView->value, Permission::AutomationsView->value], 'is_owner' => false],
        ];
    }
}
