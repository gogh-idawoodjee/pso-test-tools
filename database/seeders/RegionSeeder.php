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

        $userIds = [1, 2, 3]; // Your user IDs

        foreach ($userIds as $userId) {
            foreach ($regions as $name) {
                Region::create([
                    'id'      => Str::uuid()->toString(),
                    'name'    => $name,
                    'user_id' => $userId,
                ]);
            }
        }
    }
}
