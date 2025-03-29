<?php

namespace Database\Seeders;

use App\Models\Environment;
use App\Models\User;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Ramsey\Uuid\Uuid;

class DatabaseSeeder extends Seeder
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
            'password' => Hash::make('manman'),
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
            'name' => 'The Drome',
            'id' => Uuid::uuid4()->toString(),
            'base_url' => 'https://enercare-pso-tst.ifs.cloud',
            'description' => 'EC Test',
            'account_id' => 'Default',
            'username' => 'idawoodjee',
            'password' => Crypt::encrypt('fakepassword'),
            'user_id' => '1'
        ]);

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
