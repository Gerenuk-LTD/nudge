<?php

namespace Gerenuk\Marketing\Models;

use Gerenuk\Marketing\Contracts\MarketingUser;
use Gerenuk\Marketing\Database\Factories\AccountFactory;
use Gerenuk\Marketing\Marketing;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The billing owner. An account owns one or more workspaces.
 *
 * @property int $id
 * @property string $name
 * @property int $owner_id
 */
#[Fillable(['name', 'owner_id'])]
#[UseFactory(AccountFactory::class)]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Model&MarketingUser, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Marketing::userModel(), 'owner_id');
    }

    /**
     * @return HasMany<Workspace, $this>
     */
    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }
}
