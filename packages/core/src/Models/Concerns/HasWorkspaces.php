<?php

namespace Gerenuk\Marketing\Models\Concerns;

use Gerenuk\Marketing\Enums\Permission;
use Gerenuk\Marketing\Models\Membership;
use Gerenuk\Marketing\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Added to the host application's user model.
 *
 * @property int|null $current_workspace_id
 *
 * @mixin Model
 */
trait HasWorkspaces
{
    /** @var array<int, Membership|null> */
    private array $resolvedMemberships = [];

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return BelongsToMany<Workspace, $this>
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'memberships')
            ->withPivot('role_id')
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function currentWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }

    public function membershipFor(Workspace $workspace): ?Membership
    {
        if (! array_key_exists($workspace->id, $this->resolvedMemberships)) {
            $this->resolvedMemberships[$workspace->id] = $this->memberships()
                ->with('role')
                ->where('workspace_id', $workspace->id)
                ->first();
        }

        return $this->resolvedMemberships[$workspace->id];
    }

    public function belongsToWorkspace(Workspace $workspace): bool
    {
        return $this->membershipFor($workspace) !== null;
    }

    public function hasWorkspacePermission(Workspace $workspace, Permission $permission): bool
    {
        return $this->membershipFor($workspace)?->role->grants($permission) ?? false;
    }

    /**
     * The workspace the user is working in: their chosen one if they still belong to it, otherwise their first.
     */
    public function resolveCurrentWorkspace(): ?Workspace
    {
        $currentId = $this->currentWorkspaceId();
        $current = $currentId === null ? null : Workspace::find($currentId);

        if ($current !== null && $this->belongsToWorkspace($current)) {
            return $current;
        }

        $fallback = $this->workspaces()->orderBy('workspaces.id')->first();

        $this->forceFill(['current_workspace_id' => $fallback?->id])->save();
        $this->setRelation('currentWorkspace', $fallback);

        return $fallback;
    }

    public function switchWorkspace(Workspace $workspace): bool
    {
        if (! $this->belongsToWorkspace($workspace)) {
            return false;
        }

        $this->forceFill(['current_workspace_id' => $workspace->id])->save();
        $this->setRelation('currentWorkspace', $workspace);

        return true;
    }

    public function forgetResolvedMemberships(): void
    {
        $this->resolvedMemberships = [];
    }

    public function currentWorkspaceId(): ?int
    {
        return $this->getAttribute('current_workspace_id');
    }

    public function displayName(): string
    {
        return (string) $this->getAttribute('name');
    }

    public function emailAddress(): string
    {
        return (string) $this->getAttribute('email');
    }
}
