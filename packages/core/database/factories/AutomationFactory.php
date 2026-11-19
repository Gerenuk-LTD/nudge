<?php

namespace Gerenuk\Nudge\Database\Factories;

use Gerenuk\Nudge\Enums\AutomationTrigger;
use Gerenuk\Nudge\Models\Automation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Automation>
 */
class AutomationFactory extends Factory
{
    protected $model = Automation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'trigger_type' => AutomationTrigger::ApiEvent,
            'trigger_config' => ['event' => 'signed_up'],
            'definition' => ['steps' => []],
        ];
    }
}
