<?php

namespace Database\Seeders;

use App\Models\Region;
use Illuminate\Database\Seeder;

class RegionSeeder extends Seeder
{
    public function run(): void
    {
        $regions = [
            ['id' => 'SVBARR', 'name' => 'Service Barrie'],
            ['id' => 'OTWEST', 'name' => 'Ottawa West'],
            ['id' => 'INWIND', 'name' => 'Interior Windsor'],
            ['id' => 'METRSC', 'name' => 'Metro Scarborough'],
            ['id' => 'CAP01',  'name' => 'Capital Region'],
        ];

        foreach ($regions as $region) {
            Region::create($region);
        }
    }
}
