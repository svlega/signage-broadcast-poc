<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Deliberately not `User::factory()->create()`: the factory's
     * `definition()` calls the `fake()` helper, which needs
     * fakerphp/faker — a require-dev package, absent from the `--no-dev`
     * production image (see backend/Dockerfile). This seeder has to run
     * there too (it's what creates the one demo login the admin panel
     * needs), so it builds the row directly instead of going through a
     * factory built for randomized test data.
     */
    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => Hash::make('password')],
        );

        $this->call(MediaItemSeeder::class);
    }
}
