<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class RealStaffUsersSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Update/Create Alex Morgan (Super Admin)
        $alex = User::updateOrCreate(
            ['email' => 'admin@mecarvi.com'],
            [
                'name' => 'Alex Morgan',
                'username' => 'alex.morgan',
                'role' => 'super_admin',
                'password' => Hash::make('MecaRviAdmin@2021$'),
                'department' => 'Administration',
                'job_title' => 'Super Administrator',
                'staff_id' => 'STF-0000',
                'phone' => '(212) 555-0199',
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );
        try {
            Role::firstOrCreate(['name' => 'super_admin']);
            $alex->assignRole('super_admin');
        } catch (\Throwable $e) {}

        // 2. Real Staff Members
        $staffMembers = [
            [
                'name' => 'Subrina Vazquez',
                'email' => 'subrina@mecarvi.com',
                'username' => 'subrina.vazquez',
                'role' => 'super_admin',
                'department' => 'Administration',
                'job_title' => 'Chief Executive Officer',
                'staff_id' => 'STF-0001',
                'phone' => '(347) 555-0100',
            ],
            [
                'name' => 'John Doe',
                'email' => 'john@mecarvi.com',
                'username' => 'john.doe',
                'role' => 'manager',
                'department' => 'Operations',
                'job_title' => 'Operations Manager',
                'staff_id' => 'STF-0002',
                'phone' => '(718) 555-0123',
            ],
            [
                'name' => 'Sarah Mitchell',
                'email' => 'sarah@mecarvi.com',
                'username' => 'sarah.mitchell',
                'role' => 'editor',
                'department' => 'Sales & Marketing',
                'job_title' => 'Marketing Specialist',
                'staff_id' => 'STF-0003',
                'phone' => '(646) 555-0187',
            ],
            [
                'name' => 'Michael Chen',
                'email' => 'michael@mecarvi.com',
                'username' => 'michael.chen',
                'role' => 'staff',
                'department' => 'Finance',
                'job_title' => 'Senior Accountant',
                'staff_id' => 'STF-0004',
                'phone' => '(212) 555-0145',
            ],
            [
                'name' => 'Emily Davis',
                'email' => 'emily@mecarvi.com',
                'username' => 'emily.davis',
                'role' => 'staff',
                'department' => 'Human Resources',
                'job_title' => 'Human Resources Manager',
                'staff_id' => 'STF-0005',
                'phone' => '(917) 555-0162',
            ],
            [
                'name' => 'James Wilson',
                'email' => 'james@mecarvi.com',
                'username' => 'james.wilson',
                'role' => 'staff',
                'department' => 'Information Technology',
                'job_title' => 'IT Support Specialist',
                'staff_id' => 'STF-0006',
                'phone' => '(347) 555-0178',
            ],
            [
                'name' => 'David Taylor',
                'email' => 'david@mecarvi.com',
                'username' => 'david.taylor',
                'role' => 'staff',
                'department' => 'Production',
                'job_title' => 'Production Manager',
                'staff_id' => 'STF-0007',
                'phone' => '(718) 555-0190',
            ],
            [
                'name' => 'Lisa Anderson',
                'email' => 'lisa@mecarvi.com',
                'username' => 'lisa.anderson',
                'role' => 'staff',
                'department' => 'Customer Service',
                'job_title' => 'Customer Service Representative',
                'staff_id' => 'STF-0008',
                'phone' => '(646) 555-0134',
            ],
            [
                'name' => 'Robert Martinez',
                'email' => 'robert@mecarvi.com',
                'username' => 'robert.martinez',
                'role' => 'staff',
                'department' => 'Procurement',
                'job_title' => 'Procurement Specialist',
                'staff_id' => 'STF-0009',
                'phone' => '(212) 555-0156',
            ],
            [
                'name' => 'Jennifer Thomas',
                'email' => 'jennifer@mecarvi.com',
                'username' => 'jennifer.thomas',
                'role' => 'staff',
                'department' => 'Logistics',
                'job_title' => 'Logistics Supervisor',
                'staff_id' => 'STF-0010',
                'phone' => '(917) 555-0112',
            ],
            [
                'name' => 'William Jackson',
                'email' => 'william@mecarvi.com',
                'username' => 'william.jackson',
                'role' => 'staff',
                'department' => 'Warehouse',
                'job_title' => 'Warehouse Associate',
                'staff_id' => 'STF-0011',
                'phone' => '(347) 555-0143',
            ],
            [
                'name' => 'Ashley White',
                'email' => 'ashley@mecarvi.com',
                'username' => 'ashley.white',
                'role' => 'staff',
                'department' => 'Sales & Marketing',
                'job_title' => 'Graphic Designer',
                'staff_id' => 'STF-0012',
                'phone' => '(718) 555-0167',
            ],
        ];

        foreach ($staffMembers as $member) {
            $user = User::updateOrCreate(
                ['email' => $member['email']],
                array_merge($member, [
                    'password' => Hash::make('MecaRviStaff@2024'),
                    'status' => 'active',
                    'email_verified_at' => now(),
                ])
            );

            try {
                Role::firstOrCreate(['name' => $member['role']]);
                $user->assignRole($member['role']);
            } catch (\Throwable $e) {}
        }

        // 3. Update existing departments in variable_settings to reference real staff members
        $deptHeads = [
            'Administration' => 'Subrina Vazquez',
            'Finance' => 'Michael Chen',
            'Human Resources' => 'Emily Davis',
            'Sales & Marketing' => 'Sarah Mitchell',
            'Production' => 'David Taylor',
            'Information Technology' => 'James Wilson',
            'Operations' => 'John Doe',
            'Procurement' => 'Robert Martinez',
            'Logistics' => 'Jennifer Thomas',
            'Customer Service' => 'Lisa Anderson',
        ];

        foreach ($deptHeads as $deptName => $headName) {
            $initials = '';
            foreach (explode(' ', $headName) as $part) {
                if ($part) $initials .= strtoupper($part[0]);
                if (strlen($initials) >= 2) break;
            }

            \App\Models\VariableSetting::where('type', 'departments')
                ->where('name', $deptName)
                ->update([
                    'head_name' => $headName,
                    'head_initials' => $initials,
                ]);
        }
    }
}
