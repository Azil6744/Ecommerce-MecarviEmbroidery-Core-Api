<?php

namespace Database\Seeders;

use App\Models\Charity;
use App\Models\CharityPayout;
use App\Models\Donation;
use Illuminate\Database\Seeder;

class DonationAndPayoutSeeder extends Seeder
{
    public function run(): void
    {
        $sampleDonations = [
            [
                'order_id' => 'ORD-2024-8921',
                'txn_id' => 'TXN-90821-DON',
                'donor_name' => 'Sophia Martinez',
                'donor_email' => 'sophia.m@gmail.com',
                'charity_name' => 'Mecarvi Foundation',
                'charity_category' => 'Direct Assistance',
                'charity_logo_type' => 'mecarvi_foundation',
                'amount' => 50.00,
                'payment_method_brand' => 'Visa',
                'payment_method_details' => '•••• 4242',
                'payment_method_email' => null,
                'status' => 'Completed',
                'created_at' => now()->subHours(2),
            ],
            [
                'order_id' => 'ORD-2024-8919',
                'txn_id' => 'TXN-90819-DON',
                'donor_name' => 'Liam Johnson',
                'donor_email' => 'liam.j@yahoo.com',
                'charity_name' => "St. Jude Children's",
                'charity_category' => 'Health & Hospital',
                'charity_logo_type' => 'st_jude',
                'amount' => 25.00,
                'payment_method_brand' => 'Mastercard',
                'payment_method_details' => '•••• 8831',
                'payment_method_email' => null,
                'status' => 'Completed',
                'created_at' => now()->subHours(5),
            ],
            [
                'order_id' => 'ORD-2024-8915',
                'txn_id' => 'TXN-90815-DON',
                'donor_name' => 'Emma Watson',
                'donor_email' => 'emma.w@outlook.com',
                'charity_name' => 'American Red Cross',
                'charity_category' => 'Disaster Relief',
                'charity_logo_type' => 'red_cross',
                'amount' => 100.00,
                'payment_method_brand' => 'PayPal',
                'payment_method_details' => '•••• paypal',
                'payment_method_email' => 'emma.w@outlook.com',
                'status' => 'Completed',
                'created_at' => now()->subDay(),
            ],
            [
                'order_id' => 'ORD-2024-8902',
                'txn_id' => 'TXN-90802-DON',
                'donor_name' => 'Noah Davis',
                'donor_email' => 'noah.davis@gmail.com',
                'charity_name' => 'Feeding America',
                'charity_category' => 'Children & Food',
                'charity_logo_type' => 'feeding_america',
                'amount' => 15.00,
                'payment_method_brand' => 'Amex',
                'payment_method_details' => '•••• 1004',
                'payment_method_email' => null,
                'status' => 'Pending',
                'created_at' => now()->subDays(2),
            ],
            [
                'order_id' => 'ORD-2024-8890',
                'txn_id' => 'TXN-90790-DON',
                'donor_name' => 'Olivia Taylor',
                'donor_email' => 'olivia.t@gmail.com',
                'charity_name' => 'Green Tomorrow Initiative',
                'charity_category' => 'Environment',
                'charity_logo_type' => 'green_tomorrow',
                'amount' => 75.00,
                'payment_method_brand' => 'Discover',
                'payment_method_details' => '•••• 6011',
                'payment_method_email' => null,
                'status' => 'Completed',
                'created_at' => now()->subDays(3),
            ],
        ];

        foreach ($sampleDonations as $d) {
            Donation::firstOrCreate(
                ['txn_id' => $d['txn_id']],
                $d
            );
        }

        $samplePayouts = [
            [
                'payout_id' => 'PAY-2024-0041',
                'charity_name' => 'Mecarvi Foundation',
                'charity_tagline' => 'Supporting education, health and community development.',
                'charity_logo_type' => 'mecarvi_foundation',
                'amount' => 4500.00,
                'payment_method' => 'Bank Transfer',
                'reference_or_check' => 'ACH-982341-MF',
                'status' => 'Paid',
                'scheduled_date' => 'May 28, 2024',
                'date_paid_or_expected' => 'May 28, 2024 10:45 AM',
                'notes_to_charity' => 'Monthly donation disbursement for May 2024.',
                'admin_notes' => 'Batch settlement completed via Stripe ACH.',
                'completed_at' => now()->subDays(10),
            ],
            [
                'payout_id' => 'PAY-2024-0040',
                'charity_name' => "St. Jude Children's",
                'charity_tagline' => "Finding cures. Saving children's lives.",
                'charity_logo_type' => 'st_jude',
                'amount' => 3200.00,
                'payment_method' => 'Bank Transfer',
                'reference_or_check' => 'ACH-982340-SJ',
                'status' => 'Paid',
                'scheduled_date' => 'May 25, 2024',
                'date_paid_or_expected' => 'May 25, 2024 02:15 PM',
                'notes_to_charity' => 'Q2 pediatric research allocation.',
                'admin_notes' => 'Direct deposit verified.',
                'completed_at' => now()->subDays(15),
            ],
            [
                'payout_id' => 'PAY-2024-0039',
                'charity_name' => 'American Red Cross',
                'charity_tagline' => 'Give Help. Give Hope. Disaster relief nationwide.',
                'charity_logo_type' => 'red_cross',
                'amount' => 2800.00,
                'payment_method' => 'Check',
                'reference_or_check' => 'CHK-10492',
                'status' => 'Processing',
                'scheduled_date' => 'Jun 05, 2024',
                'date_paid_or_expected' => 'Jun 05, 2024',
                'notes_to_charity' => 'Disaster relief emergency funds payout.',
                'admin_notes' => 'Check printed and pending courier dispatch.',
                'completed_at' => null,
            ],
            [
                'payout_id' => 'PAY-2024-0038',
                'charity_name' => 'Feeding America',
                'charity_tagline' => 'Ending hunger nationwide with emergency food banks.',
                'charity_logo_type' => 'feeding_america',
                'amount' => 1950.00,
                'payment_method' => 'Bank Transfer',
                'reference_or_check' => 'ACH-982338-FA',
                'status' => 'Processing',
                'scheduled_date' => 'Jun 08, 2024',
                'date_paid_or_expected' => 'Jun 08, 2024',
                'notes_to_charity' => 'Food bank community grant payout.',
                'admin_notes' => 'Awaiting bank confirmation.',
                'completed_at' => null,
            ],
            [
                'payout_id' => 'PAY-2024-0037',
                'charity_name' => 'Paws & Hope',
                'charity_tagline' => 'Shelter, medical care and loving homes for animals.',
                'charity_logo_type' => 'paws_and_hope',
                'amount' => 850.00,
                'payment_method' => 'Check',
                'reference_or_check' => 'CHK-10488',
                'status' => 'Canceled',
                'scheduled_date' => 'May 10, 2024',
                'date_paid_or_expected' => 'May 10, 2024',
                'notes_to_charity' => null,
                'admin_notes' => 'Charity banking details were updated; re-issuing as bank transfer.',
                'cancellation_reason' => 'Incorrect Bank Details',
                'cancellation_notes' => 'Voided check #10488 upon charity request.',
                'completed_at' => null,
            ]
        ];

        foreach ($samplePayouts as $p) {
            CharityPayout::firstOrCreate(
                ['payout_id' => $p['payout_id']],
                $p
            );
        }
    }
}