<?php

namespace Gerenuk\Marketing\Models;

use Carbon\CarbonInterface;
use Gerenuk\Marketing\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $role_id
 * @property int|null $invited_by
 * @property string $email
 * @property string $token_hash
 * @property CarbonInterface $expires_at
 */
#[Fillable(['workspace_id', 'role_id', 'invited_by', 'email', 'token_hash', 'expires_at'])]
#[Hidden(['token_hash'])]
class Invitation extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class)->withoutGlobalScopes();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Find an invitation by its plain token, across all workspaces.
     */
    public static function findByToken(string $token): ?self
    {
        return static::withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->first();
    }

    /**
     * Whether any workspace has an unexpired invitation for this email address. Hosts use it to allow sign-up by invitation.
     */
    public static function isPendingFor(string $email): bool
    {
        return static::withoutGlobalScopes()
            ->where('email', Contact::normalizeEmail($email))
            ->where('expires_at', '>', now())
            ->exists();
    }
}
