<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        /**
         * `DemoSeeder` calls `PlanSeeder` itself, because the terms it creates
         * need the plans to exist first and a seeder that depends on another
         * having been run is a seeder that breaks when someone runs it alone.
         */
        $this->call(DemoSeeder::class);
    }
}
