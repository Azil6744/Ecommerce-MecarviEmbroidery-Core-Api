<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EcommerceGiftCard;
use App\Models\EcommerceGiftCardNote;
use App\Models\EcommerceGiftCardOrder;
use App\Models\EcommerceGiftCardTransaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Admin "Gift Card Transaction History" page (Customer / Business): every gift card
 * ledger row in a date range, totals for the four summary tiles, and the details
 * panel (card, payment, buyer, recipient, internal notes) for each row.
 */
class AdminGiftCardTransactionHistoryController extends Controller
{
    private const BUSINESS_ROLES = ['business', 'business_owner', 'business_user', 'business-customer', 'company'];

    private const TYPE_LABELS = [
        'issue' => 'Purchased',
        'redemption' => 'Redeemed',
        'expiration' => 'Expired',
        'void' => 'Voided',
        'reversal' => 'Reversed',
        'manual adjustment' => 'Adjusted',
        'refund' => 'Refunded',
        'transfer' => 'Transferred',
    ];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'audience' => 'nullable|in:customer,business',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'search' => 'nullable|string|max:120',
            'type' => 'nullable|string|max:40',
            // The four totals cards are filters: purchased, redeemed, voided (incl. reversals), expired.
            'kind' => 'nullable|in:all,purchased,redeemed,voided,expired',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $audience = ($data['audience'] ?? 'customer') === 'business' ? 'business' : 'customer';
        // No range = all time (the page has no date filter; from / to stay available to API callers).
        $from = ! empty($data['from']) ? Carbon::parse($data['from'])->startOfDay() : null;
        $to = ! empty($data['to']) ? Carbon::parse($data['to'])->endOfDay() : null;
        $perPage = (int) ($data['per_page'] ?? 8);
        $page = (int) ($data['page'] ?? 1);

        $txs = EcommerceGiftCardTransaction::query()
            ->with('giftCard')
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->latest('created_at')
            ->latest('id')
            ->get();

        // Cache buyer lookups and gift card orders for the whole page build.
        $orders = EcommerceGiftCardOrder::whereIn('id', $txs->pluck('giftCard.order_id')->filter()->unique())->get()->keyBy('id');
        $userIds = $orders->pluck('customer_id')->merge($txs->pluck('giftCard.buyer_user_id'))->merge($txs->pluck('giftCard.sender_user_id'))->filter()->unique();
        $users = User::whereIn('id', $userIds)->get()->keyBy('id');

        $rows = $txs->filter(fn ($tx) => $tx->giftCard !== null)
            ->map(fn (EcommerceGiftCardTransaction $tx) => $this->row($tx, $orders, $users))
            ->filter(fn (array $row) => $row['audience'] === $audience)
            ->values();

        $stats = [
            'purchased' => round($rows->where('type_key', 'issue')->sum('amount'), 2),
            'redeemed' => round($rows->where('type_key', 'redemption')->sum('amount'), 2),
            'voided' => round($rows->whereIn('type_key', ['void', 'reversal'])->sum('amount'), 2),
            'expired' => round($rows->where('type_key', 'expiration')->sum('amount'), 2),
        ];

        if (! empty($data['search'])) {
            $needle = mb_strtolower(trim($data['search']));
            $rows = $rows->filter(fn (array $row) => str_contains(mb_strtolower(implode(' ', array_filter([
                $row['transaction_id'], $row['order_number'], $row['card']['code'], $row['party']['name'], $row['party']['business_name'],
                $row['buyer']['email'], $row['recipient']['email'], $row['description'],
            ]))), $needle))->values();
        }
        $kinds = [
            'purchased' => ['issue'],
            'redeemed' => ['redemption'],
            'voided' => ['void', 'reversal'],
            'expired' => ['expiration'],
        ];
        if (! empty($data['kind']) && isset($kinds[$data['kind']])) {
            $rows = $rows->filter(fn (array $row) => in_array($row['type_key'], $kinds[$data['kind']], true))->values();
        }
        if (! empty($data['type']) && $data['type'] !== 'all') {
            $rows = $rows->filter(fn (array $row) => strtolower($row['type']) === strtolower($data['type']))->values();
        }

        $total = $rows->count();

        return response()->json([
            'success' => true,
            'data' => [
                'audience' => $audience,
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
                'stats' => $stats,
                'rows' => $rows->forPage($page, $perPage)->values(),
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => max(1, (int) ceil($total / $perPage)),
                ],
            ],
        ]);
    }

    private function row(EcommerceGiftCardTransaction $tx, $orders, $users): array
    {
        $card = $tx->giftCard;
        $order = $card->issue_type === 'Purchased' && $card->order_id ? $orders->get($card->order_id) : null;
        $buyerUser = $users->get($order?->customer_id ?? $card->buyer_user_id ?? $card->sender_user_id);
        $isBusiness = filled($order?->buyer_business_name)
            || filled($card->sender_business_name)
            || strtolower((string) $card->sender_type) === 'business'
            || ($buyerUser && (in_array(strtolower((string) $buyerUser->role), self::BUSINESS_ROLES, true) || filled($buyerUser->business_name)));

        $typeKey = strtolower(trim((string) $tx->transaction_type));
        $type = self::TYPE_LABELS[$typeKey] ?? ucfirst($typeKey);
        if ($typeKey === 'issue' && $card->issue_type !== 'Purchased') {
            $type = 'Issued';
        }

        $businessName = $order?->buyer_business_name ?: $card->sender_business_name ?: ($isBusiness ? $buyerUser?->business_name : null);
        $buyer = [
            'name' => $order?->buyer_name ?? $card->buyer_name ?? $card->sender_name ?? $buyerUser?->name,
            'business_name' => $businessName,
            'contact_person' => $isBusiness ? ($card->sender_contact_person ?: $order?->buyer_name ?: $buyerUser?->name) : null,
            'email' => $order?->buyer_email ?? $card->buyer_email ?? $card->sender_email ?? $buyerUser?->email,
            'phone' => $order?->buyer_phone ?? $card->sender_phone ?? $buyerUser?->phone,
            'address' => $order?->buyer_address ?? (is_string($buyerUser?->address) ? $buyerUser->address : null),
        ];
        $recipientAddress = $order
            ? trim(implode(', ', array_filter([$order->address_line1, $order->address_line2, $order->city, trim(($order->state ?? '').' '.($order->zip_code ?? ''))])), ', ')
            : trim(implode(', ', array_filter([$card->shipping_address_line1, $card->shipping_address_line2, $card->shipping_city, trim(($card->shipping_state ?? '').' '.($card->shipping_zip ?? ''))])), ', ');
        $recipient = [
            'name' => $card->recipient_name ?? $order?->recipient_name,
            'email' => $card->recipient_email ?? $order?->recipient_email,
            'phone' => $card->recipient_phone ?? $order?->recipient_phone,
            'address' => $recipientAddress ?: null,
        ];

        // Redemptions are made by whoever holds the card; everything else is the buyer's.
        $partyRole = $typeKey === 'redemption' ? 'Recipient' : 'Buyer';
        $party = $partyRole === 'Recipient'
            ? ['name' => $recipient['name'], 'business_name' => $isBusiness ? $businessName : null, 'role' => 'Recipient']
            : ['name' => $isBusiness ? ($buyer['contact_person'] ?: $buyer['name']) : $buyer['name'], 'business_name' => $isBusiness ? $businessName : null, 'role' => 'Buyer'];

        $physical = strtolower((string) ($card->giftcard_type ?? '')) === 'physical' || strtolower((string) $card->delivery_type) === 'physical'
            || strtolower((string) $order?->delivery_method) === 'physical';

        return [
            'id' => $tx->id,
            'audience' => $isBusiness ? 'business' : 'customer',
            'transaction_id' => 'GC-'.str_pad((string) $tx->id, 6, '0', STR_PAD_LEFT),
            'order_number' => $order?->order_number ?? ($tx->order_id ? 'ORD-'.$tx->order_id : 'GC-'.str_pad((string) $card->id, 6, '0', STR_PAD_LEFT)),
            'created_at' => optional($tx->created_at)->toIso8601String(),
            'type_key' => $typeKey,
            'type' => $type,
            'description' => $tx->notes,
            'amount' => round(abs((float) $tx->amount), 2),
            'signed_amount' => round((float) $tx->amount, 2),
            'reversed' => (bool) $tx->reversed_at,
            'party' => $party,
            'buyer' => $buyer,
            'recipient' => $recipient,
            'card' => [
                'ref' => 'c'.$card->id,
                'id' => $card->id,
                'code' => $card->code,
                'type' => $physical ? 'Physical' : 'Digital',
                'purchase_amount' => round((float) $card->initial_balance, 2),
                'balance' => round((float) $card->current_balance, 2),
                'status' => $this->statusLabel($card),
                'issue_date' => optional($card->purchased_at ?? $card->created_at)->toDateString(),
                'expiry_date' => optional($card->expires_at)->toDateString(),
            ],
            'payment' => [
                'method' => $this->paymentLabel($order, $card),
                'status' => $order ? ucfirst(str_replace('_', ' ', (string) $order->payment_status)) : ($card->issue_type === 'Manual' ? ($card->issuance_type ?: 'Complimentary') : 'Paid'),
            ],
        ];
    }

    private function statusLabel(EcommerceGiftCard $card): string
    {
        $s = strtolower(trim((string) $card->status));

        return match (true) {
            in_array($s, ['active', 'delivered'], true) => 'Active',
            in_array($s, ['partially used', 'partially redeemed', 'partially_redeemed'], true) => 'Partially Redeemed',
            in_array($s, ['fully used', 'redeemed'], true) => 'Redeemed',
            in_array($s, ['disabled', 'deactivated'], true) => 'Deactivated',
            default => ucfirst($s ?: 'active'),
        };
    }

    private function paymentLabel(?EcommerceGiftCardOrder $order, EcommerceGiftCard $card): string
    {
        if (! $order) {
            return $card->issue_type === 'Manual' ? 'Issued by admin' : 'N/A';
        }
        $method = strtolower((string) $order->payment_method);
        if ($method === 'wallet') {
            return 'Mecarvi Wallet';
        }
        if ($order->card_last4) {
            return trim(($order->card_brand ?: 'Card').' ending in '.$order->card_last4);
        }

        return $method ? ucfirst($method) : 'Card';
    }

    /** Internal notes for one card (admin only). */
    public function notes(int $cardId): JsonResponse
    {
        $card = EcommerceGiftCard::findOrFail($cardId);
        $notes = EcommerceGiftCardNote::where('giftcard_id', $card->id)
            ->orWhere(fn ($q) => $card->order_id && $card->issue_type === 'Purchased' ? $q->where('gift_card_order_id', $card->order_id) : $q->whereRaw('1 = 0'))
            ->latest('id')
            ->get(['id', 'author_name', 'note', 'created_at']);

        return response()->json(['success' => true, 'data' => $notes]);
    }

    public function addNote(Request $request, int $cardId): JsonResponse
    {
        $data = $request->validate(['note' => 'required|string|max:1000']);
        $card = EcommerceGiftCard::findOrFail($cardId);
        $admin = $request->user();
        $note = EcommerceGiftCardNote::create([
            'giftcard_id' => $card->id,
            'user_id' => $admin?->id,
            'author_name' => $admin?->name,
            'note' => trim($data['note']),
        ]);

        return response()->json(['success' => true, 'message' => 'Internal note added.', 'data' => $note->only(['id', 'author_name', 'note', 'created_at'])], 201);
    }
}
