<?php

namespace Gerenuk\Marketing\Models;

use Gerenuk\Marketing\Contracts\MarketingUser;
use Gerenuk\Marketing\Marketing;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a user to a workspace with a role. Not workspace-scoped, because a
 * user's memberships are read across workspaces to build the switcher.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property int $role_id
 */
#[Fillable(['workspace_id', 'user_id', 'role_id'])]
class Membership extends Model
{
    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<Model&MarketingUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Marketing::userModel());
    }

    /**
     * The role is loaded without the workspace scope, since memberships are read outside a current workspace.
     *
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class)->withoutGlobalScopes();
    }
}
