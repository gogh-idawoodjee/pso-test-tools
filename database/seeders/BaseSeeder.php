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
    public function run(): void
    {
        $users = [
            [
                'name' => 'ishy',
                'email' => 'idawoodjee@mac.com',
                'password' => Hash::make(env('MAIN_PASSWORD')),
            ],
            [
                'name' => 'kmoney',
                'email' => 'kmoney@thetechnodro.me',
                'password' => Hash::make(env('USER_PASSWORD')),
            ],
            [
                'name' => 'jbernardo',
                'email' => 'jbernardo@goghsolutions.com',
                'password' => Hash::make(env('USER_PASSWORD')),
            ],
        ];

        $templates = ['Repair', 'Install', 'Maintenance'];

        foreach ($users as $userData) {
            $user = User::create($userData);

            Environment::create([
                'name' => 'The Drome',
                'id' => Uuid::uuid4()->toString(),
                'base_url' => 'https://pso.thetechnodro.me',
                'description' => 'the drome',
                'account_id' => 'Default',
                'username' => 'admin',
                'password' => Crypt::encrypt('fakepass!'),
                'user_id' => $user->id,
            ]);

            Environment::create([
                'name' => 'EC test',
                'id' => Uuid::uuid4()->toString(),
                'base_url' => 'https://enercare-pso-tst.ifs.cloud',
                'description' => 'EC Test',
                'account_id' => 'encr',
                'username' => $user->name,
                'password' => Crypt::encrypt('fakepassword'),
                'user_id' => $user->id,
            ]);

            foreach ($templates as $templateName) {
                AppointmentTemplate::create([
                    'id' => Str::uuid(),
                    'template_id' => Str::upper($templateName),
                    'name' => $templateName,
                    'user_id' => $user->id,
                ]);

            }
        }
    }
}
