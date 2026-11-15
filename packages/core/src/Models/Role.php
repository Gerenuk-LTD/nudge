<?php

namespace Gerenuk\Marketing\Models;

use Gerenuk\Marketing\Enums\Permission;
use Gerenuk\Marketing\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named bundle of permissions within a workspace.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string|null $key
 * @property string|null $description
 * @property list<string> $permissions
 * @property bool $is_owner
 */
#[Fillable(['workspace_id', 'name', 'key', 'description', 'permissions', 'is_owner'])]
class Role extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_owner' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Whether someone holding $actor could hand out this role: owners can hand out anything, everyone else
     * only roles whose permissions they hold themselves, and never the owner role.
     */
    public function isWithin(Role $actor): bool
    {
        if ($actor->is_owner) {
            return true;
        }

        return ! $this->is_owner && array_diff($this->permissions, $actor->permissions) === [];
    }

    public function isBuiltIn(): bool
    {
        return $this->key !== null;
    }

    public function grants(Permission $permission): bool
    {
        return $this->is_owner || in_array($permission->value, $this->permissions, true);
    }
}
