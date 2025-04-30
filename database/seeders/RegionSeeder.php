<?php

namespace Database\Seeders;

use App\Models\Region;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RegionSeeder extends Seeder
{
    public function run(): void
    {
        $regions = [
            'Service Barrie',
            'Ottawa West',
            'Interior Windsor',
            'Metro Scarborough',
            'Capital Region',
        ];

        foreach ($regions as $name) {
            Region::create([
                'id'   => Str::uuid()->toString(),
                'name' => $name,
            ]);
        }
    }
}
