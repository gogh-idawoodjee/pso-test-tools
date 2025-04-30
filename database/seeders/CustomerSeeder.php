<?php

namespace Database\Seeders;

use App\Enums\Status;
use App\Models\Customer;
use App\Models\Region;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Faker\Factory as Faker;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create();

        $regionIds = Region::pluck('id')->toArray();

        for ($i = 0; $i < 10; $i++) {
            Customer::create([
                'id'        => Str::uuid()->toString(),
                'name'      => $faker->company,
                'address'   => $faker->streetAddress,
                'city'      => $faker->city,
                'country'   => $faker->country,
                'postcode'  => $faker->postcode,
                'lat'       => $faker->latitude,
                'long'      => $faker->longitude,
                'region_id' => $faker->optional()->randomElement($regionIds),
                'status'    => collect(Status::cases())->random()->value,
            ]);
        }
    }
}
