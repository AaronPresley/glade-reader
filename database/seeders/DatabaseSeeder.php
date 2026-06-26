<?php

namespace Database\Seeders;

use App\Domain\User\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        Artisan::call('migrate:fresh --force');

        User::factory()->create([
            'name' => 'Example User',
            'username' => 'ExampleUser',
            'email' => 'example@person.com',
            'password' => Hash::make('IHeartDinosaurs'),
        ]);
    }
}
