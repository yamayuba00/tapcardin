<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AuthzSeeder::class,
            PlanSeeder::class,
            ReservedSlugSeeder::class,
            ContactTypeSeeder::class,
            PlatformOwnerSeeder::class,
        ]);
    }
}
