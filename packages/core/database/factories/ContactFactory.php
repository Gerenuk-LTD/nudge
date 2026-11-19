<?php

namespace Gerenuk\Nudge\Database\Factories;

use Gerenuk\Nudge\Enums\ContactSource;
use Gerenuk\Nudge\Enums\ContactStatus;
use Gerenuk\Nudge\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'status' => ContactStatus::Subscribed,
            'source' => ContactSource::Manual,
        ];
    }

    public function unsubscribed(): static
    {
        return $this->state(fn () => ['status' => ContactStatus::Unsubscribed, 'unsubscribed_at' => now()]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['archived_at' => now()]);
    }
}
