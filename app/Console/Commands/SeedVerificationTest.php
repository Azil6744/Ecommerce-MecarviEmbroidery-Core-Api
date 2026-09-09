<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\EcommerceOrder;
use App\Models\EcommerceOrderItem;
use App\Models\EcommerceOrderVerification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;

class SeedVerificationTest extends Command
{
    protected $signature = 'test:verification {action=create : Action: create, approve, decline, reset} {--email= : User email}';
    protected $description = 'Seed or update test order verifications for testing the verification flow';

    public function handle(): int
    {
        $action = $this->argument('action');
        $email = strtolower($this->option('email') ?: 'developmentwithazil@gmail.com');

        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        if (!$user) {
            $user = User::create([
                'name' => 'Azil Adil',
                'email' => $email,
                'password' => Hash::make('password123'),
                'role' => 'customer',
                'phone' => '+1 (555) 234-5678',
                'email_verified_at' => Carbon::now(),
            ]);
            $this->info("Created user account for {$user->name} ({$user->email})");
        }

        $orderNumbers = ['OR-2024-1456', 'OR-2024-1458', 'OR-2024-1457', 'OR-2024-1454', 'ORD-2026-784512'];

        if ($action === 'create') {
            // Delete existing test orders / verifications for clean setup
            EcommerceOrderVerification::whereIn('order_number', $orderNumbers)->orWhere('user_id', $user->id)->delete();
            EcommerceOrder::whereIn('order_number', $orderNumbers)->orWhere('user_id', $user->id)->delete();

            // Order 1: OR-2024-1456 (ACTION REQUIRED)
            $order1 = EcommerceOrder::create([
                'order_number' => 'OR-2024-1456',
                'user_id' => $user->id,
                'customer_name' => $user->name,
                'customer_email' => $user->email,
                'customer_phone' => '+1 (555) 456-7890',
                'status' => 'pending',
                'payment_status' => 'pending_verification',
                'payment_method' => 'Mastercard ending 7890',
                'currency' => 'USD',
                'subtotal' => 68.75,
                'total_amount' => 68.75,
                'order_date' => Carbon::parse('2024-05-22 08:40:00'),
            ]);

            EcommerceOrderItem::create([
                'order_id' => $order1->id,
                'product_name' => 'Custom Stickers',
                'quantity' => 3,
                'unit_price' => 22.91,
                'total_price' => 68.75,
            ]);

            EcommerceOrderVerification::create([
                'order_id' => $order1->id,
                'order_number' => 'OR-2024-1456',
                'user_id' => $user->id,
                'site_slug' => 'embroidery',
                'risk_level' => 'high',
                'flag_reason' => 'Our system detected an issue with your payment. To protect your account and ensure order security, we need a few documents from you.',
                'reason_title' => 'Why do I need to verify my order?',
                'reason_text' => 'Our system detected an issue with your payment. To protect your account and ensure order security, we need a few documents from you.',
                'status' => 'action_required',
                'deadline_at' => Carbon::parse('2024-05-25 23:59:59'),
                'total_amount' => 68.75,
                'payment_method' => 'Mastercard ending 7890',
                'product_name' => 'Custom Stickers',
                'product_specs' => 'Vinyl • Waterproof',
                'item_count' => 3,
                'product_image' => '/assets/images/order-verification/stickers.jpg',
                'required_documents' => [
                    'Payment Card (Front & Back)',
                    'Photo ID'
                ],
                'submitted_documents' => [
                    ['id' => 'd1', 'name' => 'Payment Card (Front & Back)', 'type' => 'card', 'status' => 'pending'],
                    ['id' => 'd2', 'name' => 'Photo ID', 'type' => 'id', 'status' => 'pending'],
                ],
                'timeline' => [
                    ['title' => 'Verification Request Sent', 'date' => 'May 22, 2024 • 08:40 AM', 'completed' => true],
                    ['title' => 'Your Response Received', 'date' => null, 'completed' => false],
                    ['title' => 'Decision Made', 'date' => null, 'completed' => false],
                ],
            ]);

            // Order 2: OR-2024-1458 (PENDING DOCUMENTS)
            $order2 = EcommerceOrder::create([
                'order_number' => 'OR-2024-1458',
                'user_id' => $user->id,
                'customer_name' => $user->name,
                'customer_email' => $user->email,
                'customer_phone' => '+1 (555) 123-4567',
                'status' => 'pending',
                'payment_status' => 'pending_verification',
                'payment_method' => 'Visa ending 4242',
                'currency' => 'USD',
                'subtotal' => 129.50,
                'total_amount' => 129.50,
                'order_date' => Carbon::parse('2024-05-22 10:24:00'),
            ]);

            EcommerceOrderItem::create([
                'order_id' => $order2->id,
                'product_name' => 'Premium Business Cards',
                'quantity' => 2,
                'unit_price' => 64.75,
                'total_price' => 129.50,
            ]);

            EcommerceOrderVerification::create([
                'order_id' => $order2->id,
                'order_number' => 'OR-2024-1458',
                'user_id' => $user->id,
                'site_slug' => 'embroidery',
                'risk_level' => 'medium',
                'flag_reason' => 'Multiple declined payments before success.',
                'reason_title' => 'REASON FOR VERIFICATION',
                'reason_text' => 'Multiple declined payments before success.',
                'status' => 'pending_documents',
                'deadline_at' => Carbon::parse('2024-05-27 23:59:59'),
                'total_amount' => 129.50,
                'payment_method' => 'Visa ending 4242',
                'product_name' => 'Premium Business Cards',
                'product_specs' => 'Matte Finish • 350gsm',
                'item_count' => 2,
                'product_image' => '/assets/images/order-verification/cards.jpg',
                'required_documents' => [
                    'Payment Card (Front)',
                    'Payment Card (Back)',
                    'Photo ID'
                ],
                'submitted_documents' => [
                    ['id' => 'd1', 'name' => 'Payment Card (Front)', 'type' => 'card', 'status' => 'submitted', 'submitted_at' => 'May 22, 2024 at 10:30 AM'],
                    ['id' => 'd2', 'name' => 'Payment Card (Back)', 'type' => 'card', 'status' => 'pending'],
                    ['id' => 'd3', 'name' => 'Photo ID', 'type' => 'id', 'status' => 'pending'],
                    ['id' => 'd4', 'name' => 'Proof of Payment', 'type' => 'document', 'status' => 'not_required'],
                ],
                'timeline' => [
                    ['title' => 'Verification Request Sent', 'date' => 'May 22, 2024 • 10:24 AM', 'completed' => true],
                    ['title' => 'Your Response Received', 'date' => 'May 22, 2024 • 10:30 AM', 'completed' => true],
                    ['title' => 'Admin Review', 'date' => null, 'completed' => false],
                ],
            ]);

            // Order 3: OR-2024-1457 (COMPLETED GREEN)
            $order3 = EcommerceOrder::create([
                'order_number' => 'OR-2024-1457',
                'user_id' => $user->id,
                'customer_name' => $user->name,
                'customer_email' => $user->email,
                'customer_phone' => '+1 (555) 987-6543',
                'status' => 'processing',
                'payment_status' => 'paid',
                'payment_method' => 'PayPal',
                'currency' => 'USD',
                'subtotal' => 85.00,
                'total_amount' => 85.00,
                'order_date' => Carbon::parse('2024-05-22 09:15:00'),
            ]);

            EcommerceOrderItem::create([
                'order_id' => $order3->id,
                'product_name' => 'Flyer A5',
                'quantity' => 1,
                'unit_price' => 85.00,
                'total_price' => 85.00,
            ]);

            EcommerceOrderVerification::create([
                'order_id' => $order3->id,
                'order_number' => 'OR-2024-1457',
                'user_id' => $user->id,
                'site_slug' => 'embroidery',
                'risk_level' => 'low',
                'flag_reason' => 'Standard compliance verification cleared.',
                'reason_title' => 'VERIFICATION COMPLETED',
                'reason_text' => 'All requested documents verified successfully.',
                'status' => 'verified',
                'verified_at' => Carbon::parse('2024-05-23 11:00:00'),
                'total_amount' => 85.00,
                'payment_method' => 'PayPal',
                'product_name' => 'Flyer A5',
                'product_specs' => 'Glossy • 300gsm',
                'item_count' => 1,
                'product_image' => '/assets/images/order-verification/flyer.jpg',
                'required_documents' => [
                    'Payment Card',
                    'Photo ID',
                    'Proof of Payment'
                ],
                'submitted_documents' => [
                    ['id' => 'd1', 'name' => 'Payment Card', 'type' => 'card', 'status' => 'verified'],
                    ['id' => 'd2', 'name' => 'Photo ID', 'type' => 'id', 'status' => 'verified'],
                    ['id' => 'd3', 'name' => 'Proof of Payment', 'type' => 'document', 'status' => 'verified'],
                ],
                'timeline' => [
                    ['title' => 'Verification Request Sent', 'date' => 'May 22, 2024 • 09:15 AM', 'completed' => true],
                    ['title' => 'Your Response Received', 'date' => 'May 22, 2024 • 11:30 AM', 'completed' => true],
                    ['title' => 'Verification Approved', 'date' => 'May 23, 2024 • 11:00 AM', 'completed' => true],
                ],
            ]);

            // Order 4: OR-2024-1454 (COMPLETED PURPLE)
            $order4 = EcommerceOrder::create([
                'order_number' => 'OR-2024-1454',
                'user_id' => $user->id,
                'customer_name' => $user->name,
                'customer_email' => $user->email,
                'customer_phone' => '+1 (555) 789-0123',
                'status' => 'processing',
                'payment_status' => 'paid',
                'payment_method' => 'Visa ending 0123',
                'currency' => 'USD',
                'subtotal' => 210.40,
                'total_amount' => 210.40,
                'order_date' => Carbon::parse('2024-05-21 16:12:00'),
            ]);

            EcommerceOrderItem::create([
                'order_id' => $order4->id,
                'product_name' => 'T-Shirt Printing',
                'quantity' => 4,
                'unit_price' => 52.60,
                'total_price' => 210.40,
            ]);

            EcommerceOrderVerification::create([
                'order_id' => $order4->id,
                'order_number' => 'OR-2024-1454',
                'user_id' => $user->id,
                'site_slug' => 'embroidery',
                'risk_level' => 'low',
                'flag_reason' => 'Security review completed.',
                'reason_title' => 'VERIFICATION COMPLETED',
                'reason_text' => 'This verification has been completed. No further action is required.',
                'status' => 'verified',
                'verified_at' => Carbon::parse('2024-05-22 14:00:00'),
                'total_amount' => 210.40,
                'payment_method' => 'Visa ending 0123',
                'product_name' => 'T-Shirt Printing',
                'product_specs' => 'DTG • Front Print',
                'item_count' => 4,
                'product_image' => '/assets/images/order-verification/tshirt.jpg',
                'required_documents' => [
                    'Payment Card',
                    'Photo ID',
                    'Proof of Payment'
                ],
                'submitted_documents' => [
                    ['id' => 'd1', 'name' => 'Payment Card', 'type' => 'card', 'status' => 'verified'],
                    ['id' => 'd2', 'name' => 'Photo ID', 'type' => 'id', 'status' => 'verified'],
                    ['id' => 'd3', 'name' => 'Proof of Payment', 'type' => 'document', 'status' => 'verified'],
                ],
                'timeline' => [
                    ['title' => 'Verification Request Sent', 'date' => 'May 21, 2024 • 04:12 PM', 'completed' => true],
                    ['title' => 'Your Response Received', 'date' => 'May 21, 2024 • 05:45 PM', 'completed' => true],
                    ['title' => 'Verification Approved', 'date' => 'May 22, 2024 • 02:00 PM', 'completed' => true],
                ],
            ]);

            $this->info("=================================================");
            $this->info("Successfully seeded 4 order verifications for:");
            $this->info("User: {$user->name} ({$user->email})");
            $this->info("User ID: {$user->id}");
            $this->info("Orders Created:");
            $this->info("1. OR-2024-1456 - ACTION REQUIRED (Custom Stickers)");
            $this->info("2. OR-2024-1458 - PENDING DOCUMENTS (Premium Business Cards)");
            $this->info("3. OR-2024-1457 - COMPLETED (Flyer A5)");
            $this->info("4. OR-2024-1454 - COMPLETED (T-Shirt Printing)");
            $this->info("=================================================");
            return 0;
        }

        if ($action === 'approve') {
            $verifications = EcommerceOrderVerification::where('user_id', $user->id)->get();
            foreach ($verifications as $v) {
                $v->update([
                    'status' => 'verified',
                    'verified_at' => Carbon::now(),
                ]);
            }
            $this->info("All verifications for {$user->email} marked as APPROVED.");
            return 0;
        }

        if ($action === 'reset') {
            EcommerceOrderVerification::where('user_id', $user->id)->delete();
            EcommerceOrder::where('user_id', $user->id)->delete();
            $this->info("All test verifications for {$user->email} removed.");
            return 0;
        }

        $this->error("Unknown action: {$action}");
        return 1;
    }
}

