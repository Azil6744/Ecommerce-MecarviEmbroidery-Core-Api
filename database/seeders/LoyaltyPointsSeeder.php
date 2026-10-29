<?php

namespace Database\Seeders;

use App\Models\CustomerPointsBalance;
use App\Models\EcommerceLoyaltyTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class LoyaltyPointsSeeder extends Seeder
{
    public function run(): void
    {
        $customersData = [
            [
                'name' => 'Tasha James',
                'email' => 'tasha.james@email.com',
                'points' => 8540,
                'pending' => 180,
                'created_at' => Carbon::parse('2025-01-15 10:00:00'),
                'txs' => [
                    ['type' => 'earned', 'status' => 'pending', 'points' => 180, 'reason' => 'Points pending for Order #ME78901', 'reason_details' => 'Order #ME78901 in production', 'date' => '2026-08-29 09:15:00'],
                    ['type' => 'earned', 'status' => 'available', 'points' => 120, 'reason' => 'Order Purchase', 'reason_details' => 'Order #ME78452', 'date' => '2026-08-28 10:42:00'],
                    ['type' => 'redeemed', 'status' => 'redeemed', 'points' => -200, 'reason' => '$2.00 Checkout Discount', 'reason_details' => 'Redeemed on Order #ME78400', 'date' => '2026-08-20 15:14:00'],
                    ['type' => 'earned', 'status' => 'available', 'points' => 100, 'reason' => 'Order Purchase', 'reason_details' => 'Order #ME10376', 'date' => '2026-08-15 11:27:00'],
                    ['type' => 'referral_bonus', 'status' => 'available', 'points' => 500, 'reason' => 'Referral Bonus', 'reason_details' => 'New Member signup referral', 'date' => '2026-08-10 14:45:00'],
                    ['type' => 'review_reward', 'status' => 'available', 'points' => 100, 'reason' => 'Approved Product Review', 'reason_details' => 'Product Review #PR-4456', 'date' => '2026-08-05 09:18:00'],
                ],
            ],
            [
                'name' => 'Sarah Walker',
                'email' => 'sarah.h@email.com',
                'points' => 12430,
                'pending' => 320,
                'created_at' => Carbon::parse('2025-02-10 11:30:00'),
                'txs' => [
                    ['type' => 'earned', 'status' => 'pending', 'points' => 320, 'reason' => 'Points pending for Order #ME78890', 'reason_details' => 'Order #ME78890 awaiting delivery', 'date' => '2026-08-29 11:10:00'],
                    ['type' => 'earned', 'status' => 'available', 'points' => 250, 'reason' => 'Order Purchase', 'reason_details' => 'Order #ME78410', 'date' => '2026-08-27 14:20:00'],
                    ['type' => 'membership_bonus', 'status' => 'available', 'points' => 150, 'reason' => 'VIP Tier Promotion Bonus', 'reason_details' => 'Platinum Member Award', 'date' => '2026-08-18 16:30:00'],
                ],
            ],
            [
                'name' => 'Michael Brown',
                'email' => 'michael.b@email.com',
                'points' => 2320,
                'pending' => 0,
                'created_at' => Carbon::parse('2025-03-02 09:15:00'),
                'txs' => [
                    ['type' => 'redeemed', 'status' => 'redeemed', 'points' => -200, 'reason' => 'Redeemed at Checkout', 'reason_details' => 'Redeemed during checkout on Order #ME78390', 'date' => '2026-08-28 14:15:00'],
                    ['type' => 'earned', 'status' => 'available', 'points' => 80, 'reason' => 'Order Purchase', 'reason_details' => 'Order #ME78320', 'date' => '2026-08-21 11:00:00'],
                ],
            ],
            [
                'name' => 'Danielle Roberts',
                'email' => 'danielle.r@email.com',
                'points' => 540,
                'pending' => 95,
                'created_at' => Carbon::parse('2025-01-28 16:40:00'),
                'txs' => [
                    ['type' => 'earned', 'status' => 'pending', 'points' => 95, 'reason' => 'Points pending for Order #ME78712', 'reason_details' => 'Order #ME78712 placed', 'date' => '2026-08-28 17:05:00'],
                    ['type' => 'redeemed', 'status' => 'redeemed', 'points' => -150, 'reason' => 'Redeemed at Checkout', 'reason_details' => 'Order #ME76900 discount', 'date' => '2026-08-20 13:45:00'],
                    ['type' => 'earned', 'status' => 'available', 'points' => 100, 'reason' => 'Order Purchase', 'reason_details' => 'Order #ME76540', 'date' => '2026-08-14 10:30:00'],
                ],
            ],
            [
                'name' => 'Kerron Lewis',
                'email' => 'kerron.l@email.com',
                'points' => 6780,
                'pending' => 0,
                'created_at' => Carbon::parse('2025-08-25 12:00:00'),
                'txs' => [
                    ['type' => 'referral_bonus', 'status' => 'available', 'points' => 500, 'reason' => 'Referral Order Completed', 'reason_details' => 'Referred Member: Sarah Walker', 'date' => '2026-08-26 11:20:00'],
                    ['type' => 'earned', 'status' => 'available', 'points' => 120, 'reason' => 'Order Purchase', 'reason_details' => 'Order #ME77120', 'date' => '2026-08-19 15:40:00'],
                ],
            ],
            [
                'name' => 'Andre Mitchell',
                'email' => 'andre.m@email.com',
                'points' => 1690,
                'pending' => 0,
                'created_at' => Carbon::parse('2025-05-02 14:10:00'),
                'txs' => [
                    ['type' => 'review_reward', 'status' => 'available', 'points' => 100, 'reason' => 'Review Submission', 'reason_details' => 'Product Review #PR-4456', 'date' => '2026-08-18 16:32:00'],
                    ['type' => 'earned', 'status' => 'available', 'points' => 95, 'reason' => 'Order Purchase', 'reason_details' => 'Order #ME75990', 'date' => '2026-08-11 12:15:00'],
                ],
            ],
            [
                'name' => 'Ricardo Peters',
                'email' => 'ricardo.p@email.com',
                'points' => 1905,
                'pending' => 0,
                'created_at' => Carbon::parse('2025-04-25 15:20:00'),
                'txs' => [
                    ['type' => 'expired', 'status' => 'expired', 'points' => -75, 'reason' => 'Points Expired', 'reason_details' => 'Inactivity (12 months)', 'date' => '2026-08-12 12:28:00'],
                    ['type' => 'earned', 'status' => 'available', 'points' => 110, 'reason' => 'Order Purchase', 'reason_details' => 'Order #ME75110', 'date' => '2026-08-01 09:30:00'],
                ],
            ],
            [
                'name' => 'Natacha George',
                'email' => 'natacha.g@email.com',
                'points' => 4210,
                'pending' => 0,
                'created_at' => Carbon::parse('2025-03-18 10:45:00'),
                'txs' => [
                    ['type' => 'earned', 'status' => 'available', 'points' => 100, 'reason' => 'Order Purchase', 'reason_details' => 'Order #ME10376', 'date' => '2026-08-15 09:20:00'],
                ],
            ],
            [
                'name' => 'Sabrina Charles',
                'email' => 'sabrina.c@email.com',
                'points' => 3120,
                'pending' => 0,
                'created_at' => Carbon::parse('2025-06-11 08:30:00'),
                'txs' => [
                    ['type' => 'earned', 'status' => 'available', 'points' => 80, 'reason' => 'Order Purchase', 'reason_details' => 'Order #ME10472', 'date' => '2026-08-24 09:18:00'],
                ],
            ],
            [
                'name' => 'Latoya Brathwaite',
                'email' => 'latoya.b@email.com',
                'points' => 950,
                'pending' => 0,
                'created_at' => Carbon::parse('2025-07-04 13:50:00'),
                'txs' => [
                    ['type' => 'earned', 'status' => 'available', 'points' => 60, 'reason' => 'Order Purchase', 'reason_details' => 'Order #ME10354', 'date' => '2026-08-08 11:05:00'],
                ],
            ],
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
            } catch (\Throwable $e) {
            }

            // Clear old transactions via DB query builder (bypasses model deleting guard)
            DB::table('ecommerce_loyalty_transactions')->where('user_id', $user->id)->delete();

            $redeemedSum = 0;
            $expiredSum = 0;

            foreach ($c['txs'] as $tx) {
                $date = Carbon::parse($tx['date']);
                $pts = (int) $tx['points'];
                $status = $tx['status'] ?? ($pts >= 0 ? 'available' : 'redeemed');
                if ($status === 'redeemed') {
                    $redeemedSum += abs($pts);
                } elseif ($status === 'expired') {
                    $expiredSum += abs($pts);
                }

                EcommerceLoyaltyTransaction::create([
                    'user_id' => $user->id,
                    'transaction_type' => $tx['type'],
                    'points' => $pts,
                    'remaining_points' => ($pts > 0 && in_array($status, ['available', 'manual_added'], true)) ? $pts : 0,
                    'dollar_value' => number_format(abs($pts) * 0.01, 2, '.', ''),
                    'status' => $status,
                    'reason' => $tx['reason'],
                    'reason_details' => $tx['reason_details'],
                    'expiration_date' => ($pts > 0 && $status === 'available') ? $date->copy()->addDays(365) : null,
                    'created_at' => $date,
                    'updated_at' => $date,
                ]);
            }

            CustomerPointsBalance::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'available_points' => $c['points'],
                    'pending_points' => $c['pending'] ?? 0,
                    'redeemed_points' => $redeemedSum,
                    'expired_points' => $expiredSum,
                    'lifetime_earned' => $c['points'] + $redeemedSum + $expiredSum,
                    'is_locked' => false,
                    'needs_review' => false,
                ]
            );
        }

        // -------------------------------------------------------------
        // 2. Business Loyalty Accounts
        // -------------------------------------------------------------
        $now = Carbon::now();
        $businesses = [
            ['Grenada Retail Ltd', 'Azu Adil', 'info@grenadaretail.com', 24850, 450, '2024-01-15', [
                ['earned', 'pending', 450, 'Points pending for Order #BE20045', 'Order #BE20045 in production', '-1 days'],
                ['earned', 'available', 1200, 'Order Purchase', 'Order #BE20011', '-3 days'],
                ['membership_bonus', 'available', 800, 'Membership Purchase', 'Premium Membership', '-12 days'],
                ['redeemed', 'redeemed', -500, 'Reward Redemption', 'Used 500 points on Order #BE19995', '-20 days'],
                ['earned', 'available', 350, 'Order Purchase', 'Order #BE19980', '-35 days'],
            ]],
            ['Caribbean Supplies Co.', 'Samia William', 'orders@caribbeansupplies.com', 18320, 0, '2024-03-02', [
                ['earned', 'available', 950, 'Order Purchase', 'Order #BE20004', '-5 days'],
                ['membership_bonus', 'available', 200, 'Membership Renewal', 'Annual renewal', '-18 days'],
                ['reversed', 'reversed', -120, 'Order Refund', 'Partial refund - Order #BE19950', '-25 days'],
            ]],
            ['Island Print Solutions', 'Test User', 'sales@islandprint.com', 15670, 220, '2024-04-10', [
                ['earned', 'pending', 220, 'Points pending for Order #BE20032', 'Order #BE20032 awaiting completion', '-2 days'],
                ['earned', 'available', 640, 'Order Purchase', 'Order #BE19990', '-7 days'],
                ['earned', 'available', 300, 'Gift Card Purchase', '$300 Gift Card', '-15 days'],
                ['redeemed', 'redeemed', -400, 'Reward Redemption', 'Used 400 points at checkout', '-30 days'],
            ]],
            ['Mecarvi Enterprises', 'Azil Adil', 'account@mecarvi.com', 9850, 0, '2024-02-05', [
                ['earned', 'available', 500, 'Order Purchase', 'Order #BE19970', '-9 days'],
                ['manual_added', 'manual_added', 250, 'Manual Adjustment', 'Admin VIP goodwill adjustment', '-22 days'],
            ]],
            ['Elite Events & More', 'Regular User', 'contact@eliteevents.com', 8920, 0, '2024-06-22', [
                ['earned', 'available', 420, 'Order Purchase', 'Order #BE19960', '-11 days'],
                ['expired', 'expired', -80, 'Expired Points', 'Points expired after 12 months', '-40 days'],
            ]],
            ['Caricom Attorneys', 'Ifraim Adil', 'reception@caricomattorneys.com', 4430, 0, '2024-06-28', [
                ['earned', 'available', 260, 'Order Purchase', 'Order #BE19940', '-14 days'],
                ['reversed', 'reversed', -60, 'Order Cancellation', 'Points reversed - Order #BE19930', '-27 days'],
                ['manual_removed', 'manual_removed', -50, 'Manual Deduction', 'Admin audit correction', '-32 days'],
            ]],
            ['Beacon Ministries', 'John Paul', 'admin@beaconministries.com', 4280, 0, '2024-03-18', [
                ['earned', 'available', 310, 'Order Purchase', 'Order #BE19920', '-6 days'],
                ['birthday_bonus', 'available', 100, 'Birthday Bonus', 'Organisation anniversary', '-33 days'],
            ]],
            ['Powabylt Tools', 'Malinda Martin', 'purchasing@powabylt.com', 3950, 0, '2024-05-12', [
                ['earned', 'available', 180, 'Order Purchase', 'Order #BE19910', '-8 days'],
                ['redeemed', 'redeemed', -250, 'Reward Redemption', 'Used 250 points at checkout', '-21 days'],
            ]],
            ['Sunset Bakery', 'Nadia Clarke', 'hello@sunsetbakery.com', 780, 0, '2025-01-09', [
                ['earned', 'available', 90, 'Order Purchase', 'Order #BE19900', '-4 days'],
            ]],
            ['Harbour Marine Supply', 'Devon Joseph', 'sales@harbourmarine.com', 120, 0, '2025-09-01', [
                ['earned', 'available', 120, 'Order Purchase', 'Order #BE19890', '-2 days'],
            ]],
        ];

        foreach ($businesses as [$bizName, $contact, $email, $points, $pending, $joined, $txs]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $contact,
                    'business_name' => $bizName,
                    'password' => Hash::make('password123'),
                    'role' => 'business',
                    'loyalty_points' => $points,
                    'created_at' => Carbon::parse($joined),
                    'updated_at' => $now,
                    'email_verified_at' => $now,
                ]
            );

            try {
                $user->assignRole('business');
            } catch (\Throwable $e) {
            }

            DB::table('ecommerce_loyalty_transactions')->where('user_id', $user->id)->delete();

            $redeemedSum = 0;
            $expiredSum = 0;

            foreach ($txs as [$type, $status, $pts, $reason, $details, $when]) {
                $date = Carbon::parse($when);
                if ($status === 'redeemed') {
                    $redeemedSum += abs($pts);
                } elseif ($status === 'expired') {
                    $expiredSum += abs($pts);
                }

                EcommerceLoyaltyTransaction::create([
                    'user_id' => $user->id,
                    'transaction_type' => $type,
                    'points' => $pts,
                    'remaining_points' => ($pts > 0 && in_array($status, ['available', 'manual_added'], true)) ? $pts : 0,
                    'dollar_value' => number_format(abs($pts) * 0.01, 2, '.', ''),
                    'status' => $status,
                    'reason' => $reason,
                    'reason_details' => $details,
                    'expiration_date' => ($pts > 0 && $status === 'available') ? $date->copy()->addDays(365) : null,
                    'created_at' => $date,
                    'updated_at' => $date,
                ]);
            }

            // One earned transaction that expires within 30 days (feeds "Upcoming Points Expiration")
            if ($points >= 4000) {
                EcommerceLoyaltyTransaction::create([
                    'user_id' => $user->id,
                    'transaction_type' => 'earned',
                    'points' => 150,
                    'remaining_points' => 150,
                    'dollar_value' => '1.50',
                    'status' => 'available',
                    'reason' => 'Order Purchase',
                    'reason_details' => 'Expiring soon',
                    'expiration_date' => $now->copy()->addDays(12),
                    'created_at' => $now->copy()->subDays(353),
                    'updated_at' => $now->copy()->subDays(353),
                ]);
            }

            CustomerPointsBalance::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'available_points' => $points,
                    'pending_points' => $pending,
                    'redeemed_points' => $redeemedSum,
                    'expired_points' => $expiredSum,
                    'lifetime_earned' => $points + $redeemedSum + $expiredSum,
                    'is_locked' => false,
                    'needs_review' => false,
                ]
            );
        }
    }
}
