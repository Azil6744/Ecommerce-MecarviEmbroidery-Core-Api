<?php

namespace Database\Seeders;

use App\Models\EcommerceGiftCard;
use App\Models\EcommerceGiftCardNote;
use App\Models\EcommerceGiftCardOrder;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Two demo gift cards per customer / business user for the panel Gift Cards page:
 *   1. a paid order awaiting approval (test Approve / Decline)
 *   2. an issued digital card, partially redeemed and flagged (test View Details / Void / Deactivate)
 *
 * Recipients use @example.com so approving never emails a real inbox.
 * Users are created locally on first panel login, so re-run after logging in:
 *   php artisan db:seed --class=GiftCardPanelSeeder
 * Limit to specific accounts with GIFT_CARD_SEED_EMAILS=a@x.com,b@y.com
 */
class GiftCardPanelSeeder extends Seeder
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.7444.58 Safari/537.36';

    public function run(): void
    {
        $emails = array_filter(array_map('trim', explode(',', (string) env('GIFT_CARD_SEED_EMAILS', ''))));

        $users = User::query()
            ->when($emails, fn ($q) => $q->whereIn('email', $emails))
            ->when(! $emails, fn ($q) => $q->whereIn('role', ['customer', 'business', 'seller']))
            ->get();

        foreach ($users as $user) {
            // Demo rows must not fire affiliate-commission hooks on "paid" orders.
            EcommerceGiftCardOrder::withoutEvents(fn () => $this->seedFor($user));
        }

        $this->command?->info('Gift card panel demo data seeded for ' . $users->count() . ' user(s).');
    }

    private function seedFor(User $user): void
    {
        $isBusiness = in_array($user->role, ['business', 'seller'], true) || filled($user->business_name);
        $business = $user->business_name ?: ($isBusiness ? 'Orlando Hospitality LLC' : null);
        $base = [
            'customer_id' => $user->id,
            'buyer_name' => $user->name ?: 'Jessica Miller',
            'buyer_email' => $user->email,
            'buyer_phone' => $user->phone ?: '(321) 555-0187',
            'buyer_address' => is_string($user->address) && $user->address !== '' ? $user->address : '987 Cedar Ln, Winter Park, FL 32789',
            'buyer_business_name' => $business,
            'payment_status' => 'paid',
            'payment_method' => 'stripe',
            'card_brand' => 'Visa',
            'card_last4' => '4242',
            'ip_address' => '192.168.1.45',
            'user_agent' => self::UA,
        ];

        // 1) Pending approval — physical card
        $pendingNo = "GC-S{$user->id}-1";
        $paidAt = now()->subDays(2)->setTime(10, 24);
        $pending = EcommerceGiftCardOrder::firstOrNew(['order_number' => $pendingNo]);
        if (! $pending->exists) {
            $pending->fill($base + [
                'recipient_name' => 'Aisha Grant',
                'recipient_email' => 'aisha.grant@example.com',
                'recipient_phone' => '(321) 555-0187',
                'personal_message' => 'Congratulations on the new office!',
                'giftcard_amount' => 50.00,
                'order_status' => 'Pending Gift Card Issue',
                'delivery_method' => 'physical',
                'address_line1' => '987 Cedar Ln',
                'city' => 'Winter Park',
                'state' => 'FL',
                'zip_code' => '32789',
                'country' => 'United States',
                'card_purpose' => 'Thank You',
                'transaction_id' => 'TXN-' . random_int(1000000000, 9999999999),
                'paid_at' => $paidAt,
            ]);
            $pending->created_at = $paidAt;
            $pending->save();
        }

        // 2) Issued digital card, partially redeemed, flagged
        $activeNo = "GC-S{$user->id}-2";
        $boughtAt = now()->subDays(7)->setTime(10, 24);
        $order = EcommerceGiftCardOrder::firstOrNew(['order_number' => $activeNo]);
        if ($order->exists) {
            return;
        }
        $order->fill($base + [
            'recipient_name' => 'Marcus Lewis',
            'recipient_email' => 'marcus.lewis@example.com',
            'recipient_phone' => '(321) 555-0187',
            'personal_message' => 'Hope you enjoy this gift!',
            'giftcard_amount' => 200.00,
            'order_status' => 'Gift Card Delivered',
            'delivery_method' => 'digital',
            'card_purpose' => 'Birthday',
            'transaction_id' => 'TXN-' . random_int(1000000000, 9999999999),
            'paid_at' => $boughtAt,
            'approved_at' => $boughtAt->copy()->addMinute(),
            'processed_by_name' => 'James Carter',
            'flagged_reason' => 'Multiple attempts from different IP addresses',
        ]);
        $order->created_at = $boughtAt;
        $order->save();

        $card = EcommerceGiftCard::create([
            'order_id' => $order->id,
            'code' => 'GC-' . strtoupper(Str::random(12)),
            'recipient_name' => 'Marcus Lewis',
            'recipient_email' => 'marcus.lewis@example.com',
            'recipient_phone' => '(321) 555-0187',
            'sender_name' => $order->buyer_name,
            'initial_balance' => 200.00,
            'current_balance' => 150.00,
            'status' => 'delivered',
            'expires_at' => $boughtAt->copy()->addYear()->toDateString(),
            'delivery_type' => 'Email',
            'message' => 'Hope you enjoy this gift!',
            'purchased_at' => $boughtAt,
            'buyer_user_id' => $user->id,
            'buyer_name' => $order->buyer_name,
            'buyer_email' => $user->email,
            'owner_email' => 'marcus.lewis@example.com',
            'issue_type' => 'Purchased',
            'last_used_at' => $boughtAt->copy()->addDay()->setTime(14, 36),
            'flagged_reason' => 'Multiple attempts from different IP addresses',
        ]);

        $card->transactions()->create([
            'transaction_type' => 'Issue',
            'amount' => 200.00,
            'notes' => 'Gift card issued for order ' . $order->order_number,
        ])->forceFill(['created_at' => $boughtAt->copy()->addMinute()])->save();

        $card->transactions()->create([
            'transaction_type' => 'Redemption',
            'amount' => -50.00,
            'notes' => 'Redeemed at checkout',
        ])->forceFill(['created_at' => $boughtAt->copy()->addDay()->setTime(14, 36)])->save();

        EcommerceGiftCardNote::create([
            'gift_card_order_id' => $order->id,
            'giftcard_id' => $card->id,
            'author_name' => 'James Carter',
            'note' => 'Customer requested delivery to be resent due to not receiving the initial email. Resent on ' . $boughtAt->copy()->addMinute()->format('M j, Y, g:i A') . '.',
        ]);
    }
}
