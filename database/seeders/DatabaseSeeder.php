<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Model events stay on (no WithoutModelEvents), because the demo data relies on them.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        if (! app()->isProduction()) {
            $this->command->call('app:demo-data', ['--email' => 'test@example.com']);
        }
    }
}
