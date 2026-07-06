<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RbacSeeder::class,
            DepartmentSeeder::class,
            DynamicListSeeder::class,
            WilayaCommuneSeeder::class,
            LocationSeeder::class,
            ClientSeeder::class,
            UnitSeeder::class,
        ]);
    }
}
