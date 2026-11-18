<?php

namespace Gerenuk\Marketing\Database\Factories;

use Gerenuk\Marketing\Models\Account;
use Gerenuk\Marketing\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates a bare workspace. Use CreateWorkspace when the test needs roles and an owner.
 *
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    protected $model = Workspace::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => fake()->company(),
        ];
    }
}
