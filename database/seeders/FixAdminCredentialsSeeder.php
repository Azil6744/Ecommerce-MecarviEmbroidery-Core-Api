<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class FixAdminCredentialsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. admin@mecarvi.com
        $admin = User::updateOrCreate([
            'email' => 'admin@mecarvi.com',
        ], [
            'name' => 'Alex Morgan',
            'password' => Hash::make('MecaRviAdmin@2021$'),
            'email_verified_at' => now(),
            'role' => 'super_admin',
        ]);

        Role::firstOrCreate(['name' => 'super_admin']);
        $admin->assignRole('super_admin');

        // 2. Also ensure developer account has super_admin with password
        $dev = User::where('email', 'developmentwithazil@gmail.com')->first();
        if ($dev) {
            $dev->role = 'super_admin';
            $dev->password = Hash::make('MecaRviAdmin@2021$');
            $dev->save();
            $dev->assignRole('super_admin');
        }

        echo "Admin users updated successfully!\n";
    }
}
