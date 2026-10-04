<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Production installs must choose the admin password; "password" is
        // only acceptable on a local machine.
        $adminPassword = env('SEED_ADMIN_PASSWORD');
        if (blank($adminPassword)) {
            if (app()->isProduction()) {
                throw new RuntimeException('Set SEED_ADMIN_PASSWORD before seeding a production database.');
            }
            $adminPassword = 'password';
        }

        // Created directly (not via the factory) because Faker is a dev-only package.
        User::firstOrCreate(['username' => 'admin'], [
            'first_name'        => 'Albriane Jay',
            'last_name'         => 'Usman',
            'email'             => 'usman.albrianejay@gmail.com',
            'contact_number'    => '+639774244540',
            'address'           => 'P-7, ARCILLAS COMPOUND, ZAMBOANGA CITY',
            'password'          => $adminPassword,
            'role'              => 'admin',
            'email_verified_at' => now(),
        ]);

        // Reference data only — no demo riders, hazards or trips, so a fresh
        // database starts with real activity alone.
        $this->call([
            AddressSeeder::class,
        ]);
    }
}
