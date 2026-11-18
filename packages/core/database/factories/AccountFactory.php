<?php

namespace Gerenuk\Nudge\Database\Factories;

use Gerenuk\Nudge\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'owner_id' => config('nudge.user_model')::factory(),
        ];
    }
}
