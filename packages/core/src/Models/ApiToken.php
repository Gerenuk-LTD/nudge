<?php

namespace Gerenuk\Marketing\Models;

use Carbon\CarbonInterface;
use Gerenuk\Marketing\Enums\Permission;
use Gerenuk\Marketing\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * A workspace-scoped API token. Only a SHA-256 hash of the token is stored.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $created_by
 * @property string $name
 * @property string $token_hash
 * @property list<string> $permissions
 * @property CarbonInterface|null $last_used_at
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['workspace_id', 'created_by', 'name', 'token_hash', 'permissions', 'last_used_at', 'expires_at'])]
#[Hidden(['token_hash'])]
class ApiToken extends Model
{
    use BelongsToWorkspace;

    public const PREFIX = 'gmk_';

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function grants(Permission $permission): bool
    {
        return in_array($permission->value, $this->permissions, true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Find a token by its plain value, across all workspaces.
     */
    public static function findByPlainToken(string $plainToken): ?self
    {
        return static::withoutGlobalScopes()->where('token_hash', hash('sha256', $plainToken))->first();
    }

    /**
     * Cut a user's tokens in a workspace down to the given permissions, so a token never outlives its creator's access.
     *
     * @param  list<string>  $permissions
     */
    public static function narrowForUser(int $workspaceId, int $userId, array $permissions): void
    {
        static::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('created_by', $userId)
            ->get()
            ->each(fn (self $token) => $token->update(['permissions' => array_values(array_intersect($token->permissions, $permissions))]));
    }
}
