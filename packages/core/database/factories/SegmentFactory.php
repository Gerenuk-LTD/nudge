<?php

namespace Gerenuk\Marketing\Database\Factories;

use Gerenuk\Marketing\Models\Segment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Segment>
 */
class SegmentFactory extends Factory
{
    protected $model = Segment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'definition' => ['match' => 'all', 'conditions' => []],
        ];
    }
}
