<?php

namespace Gerenuk\Marketing\Contracts;

use Gerenuk\Marketing\Enums\Permission;
use Gerenuk\Marketing\Models\Membership;
use Gerenuk\Marketing\Models\Workspace;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * What the core needs from the host application's user model.
 * Implement it by using the HasWorkspaces trait.
 */
interface MarketingUser extends Authenticatable
{
    public function membershipFor(Workspace $workspace): ?Membership;

    public function belongsToWorkspace(Workspace $workspace): bool;

    public function hasWorkspacePermission(Workspace $workspace, Permission $permission): bool;

    public function resolveCurrentWorkspace(): ?Workspace;

    public function switchWorkspace(Workspace $workspace): bool;

    public function currentWorkspaceId(): ?int;

    public function forgetResolvedMemberships(): void;

    public function displayName(): string;

    public function emailAddress(): string;
}
