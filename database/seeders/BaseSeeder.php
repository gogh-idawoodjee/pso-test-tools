<?php

namespace Database\Seeders;

use App\Models\AppointmentTemplate;
use App\Models\Environment;
use App\Models\User;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

class BaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();
        User::create([
            'name' => 'ishy',
            'email' => 'idawoodjee@mac.com',
            'password' => Hash::make(env('MAIN_PASSWORD')),
        ]);

        Environment::create([
            'name' => 'The Drome',
            'id' => Uuid::uuid4()->toString(),
            'base_url' => 'https://pso.thetechnodro.me',
            'description' => 'the drome',
            'account_id' => 'Default',
            'username' => 'admin',
            'password' => Crypt::encrypt('fakepass!'),
            'user_id' => '1'
        ]);

        Environment::create([
            'name' => 'EC test',
            'id' => Uuid::uuid4()->toString(),
            'base_url' => 'https://enercare-pso-tst.ifs.cloud',
            'description' => 'EC Test',
            'account_id' => 'encr',
            'username' => 'idawoodjee',
            'password' => Crypt::encrypt('fakepassword'),
            'user_id' => '1'
        ]);

        $templates = [
            'Repair',
            'Install',
            'Maintenance',
        ];

        foreach ($templates as $name) {
            AppointmentTemplate::create([
                'id' => Str::upper($name),
                'name' => $name,
            ]);
        }

//        User::factory()->create([
//            'name' => 'Test User',
//            'email' => 'test@example.com',
//        ]);
    }
}
