<?php

namespace Gerenuk\Marketing\Database\Factories;

use Gerenuk\Marketing\Models\ContactList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactList>
 */
class ContactListFactory extends Factory
{
    protected $model = ContactList::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
        ];
    }
}
