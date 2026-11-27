<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Demo accounts for the admin Customer / Business Management directories.
 * Local only: every email is @example.com (local mail is live SMTP).
 *
 *   php artisan db:seed --class=CustomerDirectoryDemoSeeder
 * Remove: User::where('email', 'like', '%.directory-demo@example.com')->delete()
 */
class CustomerDirectoryDemoSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            // [name, email-prefix, phone, role, business, job_title, street, city, state, zip, wallet, verified, phoneVerified, extra]
            ['Maria Scott', 'maria.scott', '+12045567890', 'customer', null, null, '205 Birch Ln', 'Charlotte', 'NC', '28202', 50, true, true, []],
            ['James Davis', 'james.davis', '+12046678901', 'customer', null, null, '118 Hillcrest Ave', 'Tampa', 'FL', '33602', 25, false, false, []],
            ['Elena Wright', 'elena.wright', '+12047789012', 'customer', null, null, '450 Riverway Dr', 'Raleigh', 'NC', '27601', 80, true, true, ['password_update_required_at' => now()]],
            ['Ifraim Adil', 'ifraim.adil', '+19230468107', 'customer', null, null, '789 Sunset Blvd', 'Miami', 'FL', '33101', 75.5, false, false, ['deactivated_at' => now(), 'risk_level' => 'high', 'risk_reason' => 'Repeated failed sign-ins']],
            ['Azu Adil', 'azu.riverview', '+19230690107', 'business', 'Riverview Printing Co.', 'Owner', '123 Main St', 'New York', 'NY', '10001', 390, false, true, []],
            ['Samia Adil', 'samia.sunrise', '+19230618107', 'business', 'Sunrise Supplies Ltd.', 'Manager', '456 Park Ave', 'Los Angeles', 'CA', '90001', 10, true, true, []],
            ['Ifraim Adil', 'ifraim.coastal', '+19230468108', 'business', 'Coastal Promotions', 'Director', '789 Sunset Blvd', 'Miami', 'FL', '33101', 75.5, false, false, ['deactivated_at' => now(), 'account_recovery_required_at' => now()]],
            ['Regular User', 'admin.globaltech', '+12345678900', 'business', 'Global Tech Solutions', 'Admin', '654 Maple St', 'Dallas', 'TX', '75001', 120, true, true, []],
            ['Test User', 'test.brightpath', '+12345678901', 'business', 'BrightPath Marketing', 'Coordinator', '987 Oakwood Ave', 'Atlanta', 'GA', '30310', 0, true, false, []],
        ];

        foreach ($accounts as $i => [$name, $prefix, $phone, $role, $business, $title, $street, $city, $state, $zip, $wallet, $verified, $phoneVerified, $extra]) {
            $user = User::updateOrCreate(
                ['email' => "{$prefix}.directory-demo@example.com"],
                array_merge([
                    'name' => $name,
                    'phone' => $phone,
                    'role' => $role,
                    'business_name' => $business,
                    'job_title' => $title,
                    'password' => Hash::make(Str::random(24)),
                    'wallet_balance' => $wallet,
                    'email_verified_at' => $verified ? now() : null,
                    'phone_verified_at' => $phoneVerified ? now() : null,
                    'last_login_at' => now()->subDays($i % 7)->subHours($i),
                ], $extra)
            );

            DB::table('ecommerce_addresses')->updateOrInsert(
                ['user_id' => $user->id, 'is_default' => true],
                [
                    'title' => 'Primary',
                    'first_name' => explode(' ', $name)[0],
                    'last_name' => explode(' ', $name)[1] ?? '',
                    'email' => $user->email,
                    'phone' => $phone,
                    'address_line_1' => $street,
                    'city' => $city,
                    'state' => $state,
                    'postal_code' => $zip,
                    'country' => 'US',
                    'type' => 'shipping',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // Failed sign-ins for the high-risk demo customer.
        $risky = User::where('email', 'ifraim.adil.directory-demo@example.com')->first();
        if ($risky && !DB::table('user_login_histories')->where('user_id', $risky->id)->exists()) {
            foreach (range(1, 4) as $n) {
                DB::table('user_login_histories')->insert([
                    'user_id' => $risky->id,
                    'ip_address' => "203.0.113.{$n}",
                    'device_type' => 'desktop',
                    'device_title' => 'Chrome on Windows',
                    'status' => 'Failed',
                    'created_at' => now()->subHours($n * 5),
                    'updated_at' => now()->subHours($n * 5),
                ]);
            }
        }
    }
}
