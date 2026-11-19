<?php

namespace Gerenuk\Nudge\Database\Factories;

use Gerenuk\Nudge\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailTemplate>
 */
class EmailTemplateFactory extends Factory
{
    protected $model = EmailTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'subject' => fake()->sentence(4),
            'html' => '<p>Hi {{ first_name | there }},</p><p>'.fake()->paragraph().'</p>',
        ];
    }
}
