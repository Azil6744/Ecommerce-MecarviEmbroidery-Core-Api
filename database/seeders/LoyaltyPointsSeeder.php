<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\EcommerceLoyaltyTransaction;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class LoyaltyPointsSeeder extends Seeder
{
    public function run(): void
    {
        $customersData = [
            [
                'name' => 'Tasha James',
                'email' => 'tasha.james@email.com',
                'points' => 8540,
                'created_at' => Carbon::parse('2025-01-15 10:00:00'),
                'txs' => [
                    ['type' => 'earned', 'points' => 120, 'reason' => 'Order Purchase', 'reason_details' => 'Order #ME78452', 'date' => '2026-08-28 10:42:00'],
                    ['type' => 'redeemed', 'points' => -200, 'reason' => '$10 Discount Voucher', 'reason_details' => 'Voucher #RV-0015', 'date' => '2026-08-20 15:14:00'],
                    ['type' => 'earned', 'points' => 100, 'reason' => 'Order Purchase', 'reason_details' => 'Order #ME10376', 'date' => '2026-08-15 11:27:00'],
                    ['type' => 'bonus', 'points' => 50, 'reason' => 'Referral Bonus', 'reason_details' => 'New Member signup referral', 'date' => '2026-08-10 14:45:00'],
                    ['type' => 'bonus', 'points' => 30, 'reason' => 'Review Submission', 'reason_details' => 'Product Review #PR-4456', 'date' => '2026-08-05 09:18:00'],
                ]
            ],
            [
                'name' => 'Sarah Walker',
                'email' => 'sarah.h@email.com',
                'points' => 12430,
                'created_at' => Carbon::parse('2025-02-10 11:30:00'),
                'txs' => [
                    ['type' => 'earned', 'points' => 250, 'reason' => 'Order Purchase', 'reason_details' => 'Order #ME78410', 'date' => '2026-08-27 14:20:00'],
                    ['type' => 'bonus', 'points' => 150, 'reason' => 'VIP Tier Promotion Bonus', 'reason_details' => 'Platinum Member Award', 'date' => '2026-08-18 16:30:00'],
                ]
            ],
            [
                'name' => 'Michael Brown',
                'email' => 'michael.b@email.com',
                'points' => 2320,
                'created_at' => Carbon::parse('2025-03-02 09:15:00'),
                'txs' => [
                    ['type' => 'redeemed', 'points' => -200, 'reason' => '$10 Discount Voucher', 'reason_details' => 'Redeemed during checkout', 'date' => '2026-08-28 14:15:00'],
                    ['type' => 'earned', 'points' => 80, 'reason' => 'Order Purchase', 'reason_details' => 'Order #ME78320', 'date' => '2026-08-21 11:00:00'],
                ]
            ],
            [
                'name' => 'Danielle Roberts',
                'email' => 'danielle.r@email.com',
                'points' => 540,
                'created_at' => Carbon::parse('2025-01-28 16:40:00'),
                'txs' => [
                    ['type' => 'redeemed', 'points' => -150, 'reason' => 'Free Shipping Reward', 'reason_details' => 'Voucher #FS-0268', 'date' => '2026-08-20 13:45:00'],
                    ['type' => 'earned', 'points' => 100, 'reason' => 'Order Purchase', 'reason_details' => 'Order #ME76540', 'date' => '2026-08-14 10:30:00'],
                ]
            ],
            [
                'name' => 'Kerron Lewis',
                'email' => 'kerron.l@email.com',
                'points' => 6780,
                'created_at' => Carbon::parse('2025-08-25 12:00:00'),
                'txs' => [
                    ['type' => 'bonus', 'points' => 50, 'reason' => 'Referral Sign Up', 'reason_details' => 'New Member: Sarah Walker', 'date' => '2026-08-26 11:20:00'],
                    ['type' => 'earned', 'points' => 120, 'reason' => 'Order Purchase', 'reason_details' => 'Order #ME77120', 'date' => '2026-08-19 15:40:00'],
                ]
            ],
            [
                'name' => 'Andre Mitchell',
                'email' => 'andre.m@email.com',
                'points' => 1690,
                'created_at' => Carbon::parse('2025-05-02 14:10:00'),
                'txs' => [
                    ['type' => 'bonus', 'points' => 30, 'reason' => 'Review Submission', 'reason_details' => 'Product Review #PR-4456', 'date' => '2026-08-18 16:32:00'],
                    ['type' => 'earned', 'points' => 95, 'reason' => 'Order Purchase', 'reason_details' => 'Order #ME75990', 'date' => '2026-08-11 12:15:00'],
                ]
            ],
            [
                'name' => 'Ricardo Peters',
                'email' => 'ricardo.p@email.com',
                'points' => 1905,
                'created_at' => Carbon::parse('2025-04-25 15:20:00'),
                'txs' => [
                    ['type' => 'expired', 'points' => -75, 'reason' => 'Points Expired', 'reason_details' => 'Inactivity (12 months)', 'date' => '2026-08-12 12:28:00'],
                    ['type' => 'earned', 'points' => 110, 'reason' => 'Order Purchase', 'reason_details' => 'Order #ME75110', 'date' => '2026-08-01 09:30:00'],
                ]
            ],
            [
                'name' => 'Natacha George',
                'email' => 'natacha.g@email.com',
                'points' => 4210,
                'created_at' => Carbon::parse('2025-03-18 10:45:00'),
                'txs' => [
                    ['type' => 'earned', 'points' => 100, 'reason' => 'Order Purchase', 'reason_details' => 'Order #ME10376', 'date' => '2026-08-15 09:20:00'],
                ]
            ],
            [
                'name' => 'Sabrina Charles',
                'email' => 'sabrina.c@email.com',
                'points' => 3120,
                'created_at' => Carbon::parse('2025-06-11 08:30:00'),
                'txs' => [
                    ['type' => 'earned', 'points' => 80, 'reason' => 'Order Purchase', 'reason_details' => 'Order #ME10472', 'date' => '2026-08-24 09:18:00'],
                ]
            ],
            [
                'name' => 'Latoya Brathwaite',
                'email' => 'latoya.b@email.com',
                'points' => 950,
                'created_at' => Carbon::parse('2025-07-04 13:50:00'),
                'txs' => [
                    ['type' => 'earned', 'points' => 60, 'reason' => 'Order Purchase', 'reason_details' => 'Order #ME10354', 'date' => '2026-08-08 11:05:00'],
                ]
            ]
        ];

        foreach ($customersData as $c) {
            $user = User::updateOrCreate(
                ['email' => $c['email']],
                [
                    'name' => $c['name'],
                    'password' => Hash::make('password123'),
                    'role' => 'customer',
                    'loyalty_points' => $c['points'],
                    'created_at' => $c['created_at'],
                    'updated_at' => now(),
                    'email_verified_at' => now(),
                ]
            );

            try {
                $user->assignRole('customer');
            } catch (\Throwable $e) {}

            // Clear old transactions to prevent duplicates
            EcommerceLoyaltyTransaction::where('user_id', $user->id)->delete();

            foreach ($c['txs'] as $tx) {
                EcommerceLoyaltyTransaction::create([
                    'user_id' => $user->id,
                    'transaction_type' => $tx['type'],
                    'points' => $tx['points'],
                    'dollar_value' => number_format(abs($tx['points']) * 0.01, 2, '.', ''),
                    'status' => 'completed',
                    'reason' => $tx['reason'],
                    'reason_details' => $tx['reason_details'],
                    'created_at' => Carbon::parse($tx['date']),
                    'updated_at' => Carbon::parse($tx['date']),
                ]);
            }
        }
    }
}
