<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'first_name'     => 'Albriane Jay',
            'last_name'      => 'Usman',
            'username'       => 'admin',
            'email'          => 'usman.albrianejay@gmail.com',
            'contact_number' => '+639774244540',
            'address'        => 'P-7, ARCILLAS COMPOUND, ZAMBOANGA CITY',
            'password'       => bcrypt('password'),
            'role'           => 'admin',
        ]);

        // Reference data only — no demo riders, hazards or trips, so a fresh
        // database starts with real activity alone.
        $this->call([
            AddressSeeder::class,
        ]);
    }
}
