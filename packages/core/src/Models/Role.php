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
 * @property list<string> $permissions
 * @property bool $is_owner
 */
#[Fillable(['workspace_id', 'name', 'permissions', 'is_owner'])]
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

    public function grants(Permission $permission): bool
    {
        return $this->is_owner || in_array($permission->value, $this->permissions, true);
    }
}
