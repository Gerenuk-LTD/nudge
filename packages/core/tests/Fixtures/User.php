<?php

namespace Gerenuk\Marketing\Tests\Fixtures;

use Gerenuk\Marketing\Contracts\MarketingUser;
use Gerenuk\Marketing\Models\Concerns\HasWorkspaces;
use Gerenuk\Marketing\Tests\Fixtures\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Stands in for the host application's user model.
 */
#[UseFactory(UserFactory::class)]
class User extends Authenticatable implements MarketingUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasWorkspaces, Notifiable;

    protected $guarded = [];

    protected $table = 'users';
}
