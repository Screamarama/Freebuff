<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class InitialDataSeeder extends Seeder
{
    public function run(): void
    {
        $business = Business::create([
            'name' => 'My Rental Business',
            'address' => '123 Main Street',
            'phone' => '555-0100',
            'email' => 'info@myrental.com',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $users = [
            ['name' => 'Super Admin', 'email' => 'super@test.com', 'role' => 'super_admin'],
            ['name' => 'Admin', 'email' => 'admin@test.com', 'role' => 'admin'],
            ['name' => 'Manager', 'email' => 'manager@test.com', 'role' => 'manager'],
            ['name' => 'Clerk', 'email' => 'clerk@test.com', 'role' => 'clerk'],
            ['name' => 'Customer', 'email' => 'customer@test.com', 'role' => 'customer'],
        ];

        foreach ($users as $u) {
            User::create([
                'name' => $u['name'],
                'email' => $u['email'],
                'password' => Hash::make('password'),
                'role' => $u['role'],
                'business_id' => $business->id,
            ]);
        }

        $this->command->info('✅ Done! 5 users created (password: "password")');
    }
}
