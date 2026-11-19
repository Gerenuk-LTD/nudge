<?php

namespace Gerenuk\Nudge\Database\Factories;

use Gerenuk\Nudge\Enums\AudienceType;
use Gerenuk\Nudge\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->sentence(3),
            'subject' => 'Hello {{ first_name | there }}',
            'from_name' => 'Acme',
            'from_email' => 'news@acme.test',
            'html' => '<html><body><p>Hi {{ first_name | there }}</p><a href="https://acme.test/offer?a=1&amp;b=2">Offer</a> <a href="{{ unsubscribe_url }}">Unsubscribe</a> {{ postal_address }}</body></html>',
            'audience_type' => AudienceType::AllContacts,
        ];
    }
}
