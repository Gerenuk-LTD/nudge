<?php

namespace Gerenuk\Marketing\Database\Factories;

use Gerenuk\Marketing\Models\Account;
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
            'owner_id' => config('marketing.user_model')::factory(),
        ];
    }
}
