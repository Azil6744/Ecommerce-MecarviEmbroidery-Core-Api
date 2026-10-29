<?php

namespace Database\Seeders;

use App\Models\CustomerPointsBalance;
use App\Models\EcommerceLoyaltyTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Test data for the Loyalty admin pages.
 *
 * - Creates 10 business accounts (role "business") with points, balances, and transactions.
 * - Adds extra transaction types (membership, gift card, refund, cancellation,
 *   manual adjustment, expiring-soon) to customers created by LoyaltyPointsSeeder.
 *
 * Idempotent: only touches the users listed here (matched by e-mail).
 * Run: php artisan db:seed --class=LoyaltyPointsSeeder && php artisan db:seed --class=LoyaltyTestDataSeeder
 */
class LoyaltyTestDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedBusinesses();
        $this->seedExtraCustomerTransactions();
    }

    private function mapStatus(string $type, int $pts): string
    {
        return match ($type) {
            'pending' => 'pending',
            'redeemed' => 'redeemed',
            'reversed' => 'reversed',
            'expired' => 'expired',
            'manual_added' => 'manual_added',
            'manual_removed' => 'manual_removed',
            default => $pts >= 0 ? 'available' : 'redeemed',
        };
    }

    private function seedBusinesses(): void
    {
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
                    'reason_details' => 'Expiring soon (test data)',
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

    private function seedExtraCustomerTransactions(): void
    {
        $now = Carbon::now();
        $extra = [
            'tasha.james@email.com' => [
                ['membership_bonus', 'available', 500, 'Membership Purchase', 'Premium Membership', '-10 days'],
                ['reversed', 'reversed', -120, 'Order Refund', 'Refund - Order #ME78452', '-6 days'],
                ['earned', 'available', 150, 'Order Purchase', 'Expiring soon (test data)', '-353 days', 12],
            ],
            'sarah.h@email.com' => [
                ['earned', 'available', 200, 'Gift Card Purchase', '$200 Gift Card', '-4 days'],
                ['birthday_bonus', 'available', 100, 'Birthday Bonus', 'Annual gift', '-45 days'],
                ['earned', 'available', 250, 'Order Purchase', 'Expiring soon (test data)', '-355 days', 10],
            ],
            'michael.b@email.com' => [
                ['reversed', 'reversed', -60, 'Order Cancellation', 'Points reversed - Order #ME10211', '-8 days'],
                ['manual_added', 'manual_added', 100, 'Manual Adjustment', 'Admin adjustment', '-2 days'],
            ],
            'kerron.l@email.com' => [
                ['membership_bonus', 'available', 200, 'Membership Renewal', 'Annual renewal', '-16 days'],
                ['earned', 'available', 90, 'Order Purchase', 'Expiring soon (test data)', '-350 days', 15],
            ],
            'natacha.g@email.com' => [
                ['earned', 'available', 70, 'Order Purchase', 'Order #ME10287', '-3 days'],
                ['expired', 'expired', -80, 'Expired Points', 'Points expired', '-19 days'],
            ],
        ];

        foreach ($extra as $email => $txs) {
            $user = User::where('email', $email)->first();
            if (!$user) {
                continue;
            }

            // remove previous extra test rows via DB query builder so re-running does not duplicate
            DB::table('ecommerce_loyalty_transactions')
                ->where('user_id', $user->id)
                ->where(function ($q) {
                    $q->whereIn('reason', ['Membership Purchase', 'Membership Renewal', 'Gift Card Purchase', 'Order Refund', 'Order Cancellation', 'Manual Adjustment', 'Birthday Bonus', 'Expired Points'])
                        ->orWhere('reason_details', 'Expiring soon (test data)');
                })->delete();

            foreach ($txs as $t) {
                [$type, $status, $pts, $reason, $details, $when] = $t;
                $expiresInDays = $t[6] ?? null;
                $date = Carbon::parse($when);
                EcommerceLoyaltyTransaction::create([
                    'user_id' => $user->id,
                    'transaction_type' => $type,
                    'points' => $pts,
                    'remaining_points' => ($pts > 0 && in_array($status, ['available', 'manual_added'], true)) ? $pts : 0,
                    'dollar_value' => number_format(abs($pts) * 0.01, 2, '.', ''),
                    'status' => $status,
                    'reason' => $reason,
                    'reason_details' => $details,
                    'expiration_date' => $expiresInDays ? $now->copy()->addDays($expiresInDays) : (($pts > 0 && $status === 'available') ? $date->copy()->addDays(365) : null),
                    'created_at' => $date,
                    'updated_at' => $date,
                ]);
            }
        }
    }
}
