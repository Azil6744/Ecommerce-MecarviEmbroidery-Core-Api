<?php

namespace App\Services;

use App\Models\EcommerceGiftCard;
use App\Models\EcommerceGiftCardNote;
use App\Models\EcommerceGiftCardOrder;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Builds the user / business panel "Gift Cards" page.
 *
 * A row is either a gift card order (ref "o{id}") — with its issued card
 * attached once approved — or a stand-alone card the user received or was
 * issued manually (ref "c{id}").
 */
class GiftCardPanelService
{
    public const STATUS_LABELS = [
        'active' => 'Active',
        'pending' => 'Pending',
        'partially_redeemed' => 'Partially Redeemed',
        'redeemed' => 'Redeemed',
        'expired' => 'Expired',
        'cancelled' => 'Cancelled',
        'voided' => 'Voided',
        'deactivated' => 'Deactivated',
    ];

    private const NEARING_EXPIRY_DAYS = 30;

    /** Buyer roles that make a row a "business" row (same list as the admin Businesses page). */
    private const BUSINESS_ROLES = ['business', 'business_owner', 'business_user', 'business-customer', 'company'];

    /**
     * Admin mode ("customer" or "business"): the admin panel Gift Cards page. Rows cover every
     * buyer of that audience (not just the signed-in user) and the admin actions are enabled.
     * Null = the signed-in user's own panel.
     */
    private static ?string $adminAudience = null;

    public static function forAdmin(string $audience, callable $fn): mixed
    {
        $previous = self::$adminAudience;
        self::$adminAudience = $audience === 'business' ? 'business' : 'customer';
        try {
            return $fn();
        } finally {
            self::$adminAudience = $previous;
        }
    }

    private static function isAdminMode(): bool
    {
        return self::$adminAudience !== null;
    }

    // ------------------------------------------------------------------
    //  Read side
    // ------------------------------------------------------------------

    public static function dashboard(User $user, ?string $search = null, ?string $status = null, ?string $from = null, ?string $to = null): array
    {
        $rows = self::rows($user);

        // Purchase / issue date range (tiles and list both follow it).
        if ($from || $to) {
            $start = $from ? Carbon::parse($from)->startOfDay() : null;
            $end = $to ? Carbon::parse($to)->endOfDay() : null;
            $rows = $rows->filter(function (array $row) use ($start, $end) {
                if (! $row['purchased_at']) {
                    return false;
                }
                $at = Carbon::parse($row['purchased_at']);

                return (! $start || $at->gte($start)) && (! $end || $at->lte($end));
            })->values();
        }

        $all = $rows;
        if ($search) {
            $needle = mb_strtolower(trim($search));
            $rows = $rows->filter(function (array $row) use ($needle) {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $row['code_raw'] ?? null, $row['order_number'],
                    $row['buyer']['name'], $row['buyer']['email'], $row['buyer']['business_name'],
                    $row['recipient']['name'], $row['recipient']['email'], $row['recipient']['phone'],
                ])));

                return str_contains($haystack, $needle);
            });
        }
        if ($status && $status !== 'all') {
            $rows = $rows->filter(fn (array $row) => $row['status'] === $status
                || ($status === 'cancelled' && in_array($row['status'], ['cancelled', 'voided', 'deactivated'], true)));
        }

        return [
            'stats' => self::stats($all),
            'earning_type' => self::earningType($all),
            'financial' => self::financial($all),
            'pending_actions' => self::pendingActions($all),
            'rows' => $rows->values()->map(fn (array $row) => self::publicRow($row))->all(),
        ];
    }

    public static function details(User $user, string $ref): array
    {
        [$order, $card] = self::resolve($user, $ref);
        $row = self::buildRow($user, $order, $card);
        $card = $card ?? $row['card_model'];

        $notes =EcommerceGiftCardNote::query()
            ->where(function ($q) use ($order, $card) {
                if ($order) {
                    $q->orWhere('gift_card_order_id', $order->id);
                }
                if ($card) {
                    $q->orWhere('giftcard_id', $card->id);
                }
            })
            ->latest()
            ->get()
            ->map(fn (EcommerceGiftCardNote $n) => [
                'id' => $n->id,
                'note' => $n->note,
                'author_name' => $n->author_name,
                'created_at' => optional($n->created_at)->toIso8601String(),
            ])->all();

        $legacyNote = $card?->internal_notes;
        if ($legacyNote && empty($notes)) {
            $notes[] = ['id' => null, 'note' => $legacyNote, 'author_name' => null, 'created_at' => optional($card->updated_at)->toIso8601String()];
        }

        $ua = $order?->user_agent;
        $ip = $order?->ip_address;

        return array_merge(self::publicRow($row), [
            'order' => [
                'order_number' => $row['order_number'],
                'purchase_date' => $row['purchased_at'],
                'expiry_date' => $row['expires_at'],
                'delivery_method' => $row['type'] === 'physical' ? 'Physical (Mail)' : 'Digital (Email)',
                'payment_method' => self::paymentLabel($order),
                'payment_status' => $order ? ucfirst((string) $order->payment_status) : ($card?->issue_type === 'Manual' ? 'Complimentary' : 'Paid'),
                'gift_card_code' => $row['code'],
                'transaction_id' => $order?->transaction_id,
                'message' => $order?->personal_message ?? $card?->message,
                'processed_by' => $order?->processed_by_name ?? ($card?->issued_by_admin_id ? User::find($card->issued_by_admin_id)?->name : null),
                'order_status' => $order?->order_status,
                'declined_reason' => $order?->declined_reason,
                'disabled_reason' => $card?->disabled_reason,
            ],
            'last_action' => self::lastAction($row['status'], $order, $card),
            'device' => [
                'ip_address' => $ip,
                'device_name' => self::deviceName($ua, $ip),
                'operating_system' => self::operatingSystem($ua),
                'browser' => self::browser($ua),
            ],
            'timeline' => self::timeline($order, $card),
            // Internal notes are for authorized admins only (admin mode), never the buyer or holder.
            'notes' => self::isAdminMode() ? $notes : [],
            'internal_note' => self::isAdminMode() ? ($notes[0]['note'] ?? null) : null,
        ]);
    }

    // ------------------------------------------------------------------
    //  Write side
    //
    //  Every action takes the popup's input:
    //    reason, description, internal_note, and per action
    //    approve: code (from generateCode), expiry_mode, expires_at
    //    void:    action_type = void | reverse
    //  Reason / description / action type are kept on the activity log row
    //  ("meta"); the internal note becomes a regular gift card note.
    // ------------------------------------------------------------------

    private const CODE_CACHE_MINUTES = 30;

    /** Reserve a fresh unique code for the Approve popup's "Generate Code" button. */
    public static function generateCode(User $user, string $ref): array
    {
        [$order] = self::resolve($user, $ref);
        $row = self::buildRow($user, $order, null);
        if (! $order || ! $row['can']['approve']) {
            throw new \RuntimeException('Gift cards are approved and issued by an admin. Your order will be issued once it is reviewed.');
        }

        do {
            $code = '';
            for ($i = 0; $i < 15; $i++) {
                $code .= random_int(0, 9);
            }
        } while (EcommerceGiftCard::where('code', $code)->exists());

        Cache::put(self::codeCacheKey($user, $ref), $code, now()->addMinutes(self::CODE_CACHE_MINUTES));

        return ['code' => $code];
    }

    public static function approve(User $user, string $ref, ?string $ip, array $input = []): array
    {
        [$order] = self::resolve($user, $ref);
        $row = self::buildRow($user, $order, null);
        if (! $order || ! $row['can']['approve']) {
            throw new \RuntimeException('Gift cards are approved and issued by an admin. Your order will be issued once it is reviewed.');
        }

        // The code must be the one this user generated for this order: it cannot be chosen freely.
        $code = $input['code'] ?? null;
        if ($code !== null && $code !== Cache::get(self::codeCacheKey($user, $ref))) {
            throw new \RuntimeException('The generated code has expired. Generate a new code and try again.');
        }

        $expiresAt = null;
        if (($input['expiry_mode'] ?? 'standard') === 'custom') {
            if (empty($input['expires_at'])) {
                throw new \RuntimeException('Select a custom expiration date.');
            }
            $expiresAt = Carbon::parse($input['expires_at'])->endOfDay();
            if ($expiresAt->lte(now()) || $expiresAt->gt(now()->addYears(5))) {
                throw new \RuntimeException('The expiration date must be in the future and within 5 years.');
            }
        }

        DB::transaction(function () use ($order, $user, $ip, $code, $expiresAt, $input) {
            $card = GiftCardIssueService::issue($order, $user, $ip, self::isAdminMode(), $code, $expiresAt, [
                'expiry_mode' => $input['expiry_mode'] ?? 'standard',
            ]);
            self::storeNote($user, $order->id, $card->id, $input['internal_note'] ?? null);
        });

        Cache::forget(self::codeCacheKey($user, $ref));

        return self::details($user, $ref);
    }

    public static function decline(User $user, string $ref, array $input, ?string $ip): array
    {
        [$order] = self::resolve($user, $ref);
        $row = self::buildRow($user, $order, null);
        if (! $order || ! $row['can']['decline']) {
            throw new \RuntimeException('Gift card orders are approved or declined by an admin. Contact support if you need to cancel this order.');
        }

        DB::transaction(function () use ($order, $user, $input) {
            $order = EcommerceGiftCardOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->declined_at || $order->giftCards()->exists()) {
                throw new \RuntimeException('This gift card order has already been processed.');
            }

            $order->update([
                'order_status' => 'Cancelled',
                'declined_at' => now(),
                'declined_reason' => ($input['reason'] ?? null) ?: 'Declined from the gift card panel.',
                'declined_description' => $input['description'] ?? null,
                'processed_by' => $user->id,
                'processed_by_name' => $user->name,
            ]);

            self::storeNote($user, $order->id, null, $input['internal_note'] ?? null);

            LoyaltyService::reversePendingReferencePoints('gift_card_order', (string) $order->id, 'Gift card order declined');

            // Last step: refund the payment the way it came. If it fails, nothing above is kept.
            self::refundOrder($order, "Refund for declined Gift Card Order #{$order->order_number}", (float) $order->giftcard_amount, 'The order was not declined.');
        });

        return self::details($user, $ref);
    }

    /**
     * Void: cancel the card and its remaining balance (no refund).
     * Reverse: undo an unused purchase, cancelling the card and refunding the buyer.
     */
    public static function void(User $user, string $ref, array $input, ?string $ip): array
    {
        [$order, $card] = self::resolve($user, $ref);
        $row = self::buildRow($user, $order, $card);
        $card = $card ?? $row['card_model'];
        if (! $card || ! $row['can']['void']) {
            throw new \RuntimeException('Gift cards can only be voided or reversed by an admin. Contact support for help.');
        }

        $reverse = ($input['action_type'] ?? 'void') === 'reverse';
        if ($reverse) {
            $order = $order ?? ($card->order_id ? EcommerceGiftCardOrder::find($card->order_id) : null);
            if (! $order || ! $row['can']['reverse']) {
                throw new \RuntimeException('Gift cards can only be voided or reversed by an admin. Contact support for help.');
            }
        }

        DB::transaction(function () use ($card, $user, $input, $ip, $order, $reverse) {
            // Lock the card so a void / reverse can only ever run once (no duplicate credits).
            $card = EcommerceGiftCard::whereKey($card->id)->lockForUpdate()->firstOrFail();
            if (in_array(strtolower((string) $card->status), ['voided', 'cancelled'], true)) {
                throw new \RuntimeException('This gift card has already been voided.');
            }
            $remaining = (float) $card->current_balance;
            if ($reverse && abs($remaining - (float) $card->initial_balance) > 0.001) {
                throw new \RuntimeException('This gift card has been used, so it can no longer be reversed. Use Void instead.');
            }
            $old = $card->status;
            $reason = $input['reason'] ?? null;

            if ($reverse) {
                $order = EcommerceGiftCardOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($order->payment_status === 'refunded') {
                    throw new \RuntimeException('This purchase has already been refunded.');
                }
                $order->update(['order_status' => 'Refunded']);
            }

            $card->update([
                'status' => 'voided',
                'current_balance' => 0,
                'voided_at' => now(),
                'disabled_reason' => $reason ?: ($reverse ? 'Reversed from the gift card panel.' : 'Voided from the gift card panel.'),
            ]);

            $card->transactions()->create([
                'transaction_type' => $reverse ? 'Reversal' : 'Void',
                'amount' => -$remaining,
                'notes' => ($reverse ? 'Purchase reversed and refunded: ' : 'Unused balance cancelled: ') . ($reason ?: 'from the gift card panel'),
                'created_by' => $user->id,
            ]);

            $card->activityLogs()->create([
                'action' => $reverse ? 'Reversed' : 'Voided',
                'user_id' => $user->id,
                'old_value' => $old,
                'new_value' => 'voided',
                'ip_address' => $ip,
                'meta' => self::actionMeta($input, $reverse ? 'reverse' : 'void'),
            ]);

            $order?->update(['processed_by' => $user->id, 'processed_by_name' => $user->name]);
            self::storeNote($user, $order?->id, $card->id, $input['internal_note'] ?? null);

            // Last step for a reverse: refund the purchase. If it fails, nothing above is kept.
            if ($reverse) {
                self::refundOrder($order, "Refund for reversed Gift Card Order #{$order->order_number}", $remaining, 'The gift card was not reversed.');
            }
        });

        return self::details($user, $ref);
    }

    public static function toggleActive(User $user, string $ref, array $input, ?string $ip): array
    {
        [$order, $card] = self::resolve($user, $ref);
        $row = self::buildRow($user, $order, $card);
        $card = $card ?? $row['card_model'];
        if (! $card) {
            throw new \RuntimeException('This gift card has not been issued yet.');
        }

        $reactivate = $row['status'] === 'deactivated';
        if ($reactivate ? ! $row['can']['reactivate'] : ! $row['can']['deactivate']) {
            throw new \RuntimeException($reactivate ? 'This gift card cannot be reactivated.' : 'Only active gift cards can be deactivated.');
        }

        DB::transaction(function () use ($card, $user, $input, $ip, $order, $reactivate) {
            $card = EcommerceGiftCard::whereKey($card->id)->lockForUpdate()->firstOrFail();
            $current = strtolower((string) $card->status);
            if (in_array($current, ['voided', 'cancelled'], true) || $reactivate !== in_array($current, ['disabled', 'deactivated'], true)) {
                throw new \RuntimeException('This gift card changed status. Refresh and try again.');
            }

            $old = $card->status;
            $card->update($reactivate
                ? ['status' => 'active', 'deactivated_at' => null, 'disabled_reason' => null]
                : ['status' => 'disabled', 'deactivated_at' => now(), 'disabled_reason' => ($input['reason'] ?? null) ?: 'Deactivated from the gift card panel.']);

            $card->activityLogs()->create([
                'action' => $reactivate ? 'Reactivated' : 'Deactivated',
                'user_id' => $user->id,
                'old_value' => $old,
                'new_value' => $card->status,
                'ip_address' => $ip,
                'meta' => self::actionMeta($input),
            ]);

            self::storeNote($user, $order?->id, $card->id, $input['internal_note'] ?? null);
        });

        return self::details($user, $ref);
    }

    private static function codeCacheKey(User $user, string $ref): string
    {
        return "gift-card-panel:code:{$user->id}:{$ref}";
    }

    private static function actionMeta(array $input, ?string $actionType = null): ?array
    {
        $meta = array_filter([
            'reason' => $input['reason'] ?? null,
            'description' => $input['description'] ?? null,
            'action_type' => $actionType,
        ], fn ($v) => $v !== null && $v !== '');

        return $meta ?: null;
    }

    private static function storeNote(User $user, ?int $orderId, ?int $cardId, ?string $note): void
    {
        $note = trim((string) $note);
        if ($note === '') {
            return;
        }

        EcommerceGiftCardNote::create([
            'gift_card_order_id' => $orderId,
            'giftcard_id' => $cardId,
            'user_id' => $user->id,
            'author_name' => $user->name,
            'note' => $note,
        ]);
    }

    /**
     * Refund a gift card purchase the way it was paid (wallet, Stripe or PayPal).
     * See GiftCardPaymentRefundService; it throws if the refund was refused.
     */
    private static function refundOrder(EcommerceGiftCardOrder $order, string $description, float $amount, string $failSuffix): void
    {
        try {
            GiftCardPaymentRefundService::refund($order, $amount, $description);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException($e->getMessage() . ' ' . $failSuffix);
        }
    }

    public static function addNote(User $user, string $ref, string $note): array
    {
        if (! self::isAdminMode()) {
            throw new \RuntimeException('Internal notes can only be added by an admin.');
        }
        [$order, $card] = self::resolve($user, $ref);
        $row = self::buildRow($user, $order, $card);
        $card = $card ?? $row['card_model'];

        EcommerceGiftCardNote::create([
            'gift_card_order_id' => $order?->id,
            'giftcard_id' => $card?->id,
            'user_id' => $user->id,
            'author_name' => $user->name,
            'note' => $note,
        ]);

        return self::details($user, $ref);
    }

    /** Rule-based fraud signal recorded when an order is placed. */
    public static function detectFlag(EcommerceGiftCardOrder $order): ?string
    {
        $email = strtolower((string) $order->buyer_email);
        $recent = EcommerceGiftCardOrder::query()
            ->whereRaw('LOWER(buyer_email) = ?', [$email])
            ->where('created_at', '>=', now()->subHour())
            ->get(['id', 'ip_address']);

        if ($recent->count() >= 3 && $recent->pluck('ip_address')->filter()->unique()->count() >= 2) {
            return 'Multiple attempts from different IP addresses';
        }
        if ((float) $order->giftcard_amount >= 500) {
            return 'High-value gift card with unusual activity';
        }
        if (EcommerceGiftCardOrder::whereRaw('LOWER(buyer_email) = ?', [$email])->where('created_at', '>=', now()->subDay())->count() >= 5) {
            return 'Repeated purchase attempts';
        }

        return null;
    }

    // ------------------------------------------------------------------
    //  Row assembly
    // ------------------------------------------------------------------

    private static function rows(User $user): Collection
    {
        $email = strtolower(trim((string) $user->email));
        $admin = self::isAdminMode();

        $orders = EcommerceGiftCardOrder::query()
            ->when(! $admin, fn ($query) => $query->where(function ($q) use ($user, $email) {
                $q->where('customer_id', $user->id);
                if ($email) {
                    $q->orWhereRaw('LOWER(buyer_email) = ?', [$email]);
                }
            }))
            ->whereIn('payment_status', ['paid', 'refunded', 'refund_pending'])
            ->latest()
            ->get();

        $orderCards = $orders->isEmpty() ? collect() : EcommerceGiftCard::query()
            ->whereIn('order_id', $orders->pluck('id'))
            ->where('issue_type', 'Purchased')
            ->get()
            ->keyBy('order_id');

        $rows = collect();
        $usedCardIds = [];
        foreach ($orders as $order) {
            $card = $orderCards->get($order->id);
            if ($card && strtolower((string) $card->buyer_email) !== strtolower((string) $order->buyer_email)) {
                $card = null; // order_id collision with a storefront order id
            }
            if ($card) {
                $usedCardIds[] = $card->id;
            }
            $rows->push(self::buildRow($user, $order, $card, false));
        }

        $cards = EcommerceGiftCard::query()
            ->when(! $admin, fn ($query) => $query->where(function ($q) use ($user, $email) {
                $q->where('user_id', $user->id)->orWhere('buyer_user_id', $user->id);
                if ($email) {
                    $q->orWhereRaw('LOWER(owner_email) = ?', [$email])
                        ->orWhereRaw('LOWER(recipient_email) = ?', [$email])
                        ->orWhereRaw('LOWER(buyer_email) = ?', [$email]);
                }
            }))
            ->when($usedCardIds, fn ($q) => $q->whereNotIn('id', $usedCardIds))
            ->latest()
            ->get();

        foreach ($cards as $card) {
            $rows->push(self::buildRow($user, null, $card, false));
        }

        if ($admin) {
            $rows = $rows->filter(fn (array $row) => $row['audience'] === self::$adminAudience);
        }

        return $rows->sortByDesc('sort_at')->values();
    }

    /**
     * @return array{0: ?EcommerceGiftCardOrder, 1: ?EcommerceGiftCard}
     */
    private static function resolve(User $user, string $ref): array
    {
        $email = strtolower(trim((string) $user->email));

        if (self::isAdminMode()) {
            if (preg_match('/^o(\d+)$/', $ref, $m)) {
                return [EcommerceGiftCardOrder::findOrFail((int) $m[1]), null];
            }
            if (preg_match('/^c(\d+)$/', $ref, $m)) {
                return [null, EcommerceGiftCard::findOrFail((int) $m[1])];
            }
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Gift card not found.');
        }

        if (preg_match('/^o(\d+)$/', $ref, $m)) {
            $order = EcommerceGiftCardOrder::query()
                ->where('id', (int) $m[1])
                ->where(function ($q) use ($user, $email) {
                    $q->where('customer_id', $user->id);
                    if ($email) {
                        $q->orWhereRaw('LOWER(buyer_email) = ?', [$email]);
                    }
                })
                ->first();
            if (! $order) {
                throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Gift card order not found.');
            }

            return [$order, null];
        }

        if (preg_match('/^c(\d+)$/', $ref, $m)) {
            $card = EcommerceGiftCard::query()
                ->where('id', (int) $m[1])
                ->where(function ($q) use ($user, $email) {
                    $q->where('user_id', $user->id)->orWhere('buyer_user_id', $user->id);
                    if ($email) {
                        $q->orWhereRaw('LOWER(owner_email) = ?', [$email])
                            ->orWhereRaw('LOWER(recipient_email) = ?', [$email])
                            ->orWhereRaw('LOWER(buyer_email) = ?', [$email]);
                    }
                })
                ->first();
            if (! $card) {
                throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Gift card not found.');
            }

            return [null, $card];
        }

        throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Gift card not found.');
    }

    private static function buildRow(User $user, ?EcommerceGiftCardOrder $order, ?EcommerceGiftCard $card, bool $lookupCard = true): array
    {
        if ($order && ! $card && $lookupCard) {
            $card = EcommerceGiftCard::query()
                ->where('order_id', $order->id)
                ->where('issue_type', 'Purchased')
                ->whereRaw('LOWER(buyer_email) = ?', [strtolower((string) $order->buyer_email)])
                ->first();
        }

        $email = strtolower(trim((string) $user->email));
        $status = self::rowStatus($order, $card);

        $isBuyer = ($order && ((int) $order->customer_id === (int) $user->id || strtolower((string) $order->buyer_email) === $email))
            || ($card && ((int) $card->buyer_user_id === (int) $user->id || ($email && strtolower((string) $card->buyer_email) === $email)));
        $isOwner = $card && (((int) $card->user_id === (int) $user->id && $card->user_id)
            || ($email && in_array($email, [strtolower((string) $card->owner_email), strtolower((string) $card->recipient_email)], true)));
        $admin = self::isAdminMode();
        if ($admin) {
            // Admins see full codes and act on every row.
            $isBuyer = true;
            $isOwner = true;
        }

        $typeSource = strtolower((string) ($order?->delivery_method ?? $card?->delivery_type));
        $type = str_contains($typeSource, 'physical') ? 'physical' : 'digital';

        $amount = (float) ($order?->giftcard_amount ?? $card?->initial_balance ?? 0);
        $balance = $card ? (float) $card->current_balance : ($status === 'pending' ? $amount : 0.0);

        $buyerUser = $order?->customer_id ? User::find($order->customer_id) : ($card?->buyer_user_id ? User::find($card->buyer_user_id) : null);
        $buyer = [
            'name' => $order?->buyer_name ?? $card?->buyer_name ?? $card?->sender_name ?? $buyerUser?->name,
            'email' => $order?->buyer_email ?? $card?->buyer_email ?? $buyerUser?->email,
            'phone' => $order?->buyer_phone ?? $buyerUser?->phone,
            'address' => $order?->buyer_address ?? (is_string($buyerUser?->address) ? $buyerUser->address : null),
            'business_name' => $order?->buyer_business_name ?? $buyerUser?->business_name,
        ];

        $recipientAddress = $order ? trim(implode(', ', array_filter([
            $order->address_line1, $order->address_line2, $order->city,
            trim(($order->state ?? '') . ' ' . ($order->zip_code ?? '')),
        ])), ', ') : null;

        $recipient = [
            'name' => $card?->recipient_name ?? $order?->recipient_name,
            'email' => $card?->recipient_email ?? $order?->recipient_email,
            'phone' => $card?->recipient_phone ?? $order?->recipient_phone,
            'address' => $recipientAddress ?: null,
        ];

        $rawCode = $card?->code;
        $code = null;
        if ($rawCode) {
            $code = ($isOwner || $isBuyer) ? $rawCode : self::maskCode($rawCode);
        }

        $expiresAt = $card?->expires_at
            ? $card->expires_at->copy()->endOfDay()
            : ($order && $status === 'pending' ? ($order->paid_at ?? $order->created_at)?->copy()->addYear() : null);
        $purchasedAt = $order?->paid_at ?? $order?->created_at ?? $card?->purchased_at ?? $card?->created_at;

        $issued = $card !== null;
        $liveCard = in_array($status, ['active', 'partially_redeemed'], true);

        $isBusiness = filled($order?->buyer_business_name)
            || strtolower((string) $card?->sender_type) === 'business'
            || ($buyerUser && (in_array(strtolower((string) $buyerUser->role), self::BUSINESS_ROLES, true) || filled($buyerUser->business_name)));
        $unusedPurchase = $issued && $status === 'active' && $card->order_id && $card->issue_type === 'Purchased'
            && abs((float) $card->current_balance - (float) $card->initial_balance) < 0.001;

        return [
            'ref' => $order ? 'o' . $order->id : 'c' . $card->id,
            'order_id' => $order?->id,
            'card_id' => $card?->id,
            'order_number' => $order?->order_number ?? ($card ? 'GC-' . str_pad((string) $card->id, 6, '0', STR_PAD_LEFT) : null),
            'type' => $type,
            'amount' => round($amount, 2),
            'balance' => round($balance, 2),
            'redeemed_amount' => $issued ? round(self::redeemedAmount($card), 2) : 0.0,
            'currency' => $card?->currency ?? 'USD',
            'status' => $status,
            'status_label' => self::STATUS_LABELS[$status] ?? ucfirst($status),
            'purchased_at' => optional($purchasedAt)->toIso8601String(),
            'expires_at' => optional($expiresAt)->toDateString(),
            'code' => $code,
            'code_raw' => ($isOwner || $isBuyer) ? $rawCode : null,
            'buyer' => $buyer,
            'recipient' => $recipient,
            'flagged_reason' => $card?->flagged_reason ?? $order?->flagged_reason,
            'flagged_at' => optional($order?->created_at ?? $card?->created_at)->toIso8601String(),
            'audience' => $isBusiness ? 'business' : 'customer',
            'is_buyer' => (bool) $isBuyer,
            'is_owner' => (bool) $isOwner,
            'can' => [
                // Issuing a card is admin-only (admin panel "issue"); panel users can never self-approve.
                // Developer plan: only authorized administrators approve, decline, issue,
                // void or reverse gift cards. Those live in the admin panel
                // (/admin/gift-card-orders/{id}/decline, /admin/gift-cards/{id}/void-reverse),
                // so a panel user can never decline, void or reverse, even their own purchase.
                // In the admin panel (admin mode) these are the admin's actions.
                'approve' => $admin && $order !== null && $status === 'pending' && $order->payment_status === 'paid',
                'decline' => $admin && $order !== null && $status === 'pending',
                'void' => $admin && $issued && $liveCard,
                'reverse' => $admin && $unusedPurchase,
                'deactivate' => $issued && $liveCard && ($isBuyer || $isOwner),
                'reactivate' => $issued && $status === 'deactivated' && ($isBuyer || $isOwner)
                    && ! ($card->expires_at && $card->expires_at->copy()->endOfDay()->isPast()) && (float) $card->current_balance > 0,
            ],
            'sort_at' => optional($purchasedAt)->getTimestamp() ?? 0,
            'card_model' => $card,
        ];
    }

    private static function publicRow(array $row): array
    {
        unset($row['card_model'], $row['sort_at'], $row['code_raw']);

        return $row;
    }

    private static function rowStatus(?EcommerceGiftCardOrder $order, ?EcommerceGiftCard $card): string
    {
        if (! $card) {
            if (! $order) {
                return 'cancelled';
            }
            $os = strtolower((string) $order->order_status);
            if (in_array($os, ['cancelled', 'refunded', 'declined'], true) || in_array($order->payment_status, ['refunded', 'failed'], true)) {
                return 'cancelled';
            }

            return 'pending';
        }

        $s = strtolower(trim((string) $card->status));
        if (in_array($s, ['disabled', 'deactivated'], true)) {
            return 'deactivated';
        }
        if ($s === 'voided') {
            return 'voided';
        }
        if ($s === 'cancelled') {
            return 'cancelled';
        }
        if (in_array($s, ['pending', 'scheduled'], true)) {
            return 'pending';
        }
        if ($s === 'expired' || ($card->expires_at && $card->expires_at->copy()->endOfDay()->isPast())) {
            return (float) $card->current_balance <= 0 ? 'redeemed' : 'expired';
        }
        if ((float) $card->current_balance <= 0 || in_array($s, ['redeemed', 'fully used'], true)) {
            return 'redeemed';
        }
        if ((float) $card->current_balance < (float) $card->initial_balance) {
            return 'partially_redeemed';
        }

        return 'active';
    }

    private static function redeemedAmount(EcommerceGiftCard $card): float
    {
        // Void and Reversal both zero the balance without it being spent, so neither counts as redeemed.
        $voided = (float) $card->transactions()->whereIn('transaction_type', ['Void', 'Reversal'])->sum(DB::raw('ABS(amount)'));

        return max((float) $card->initial_balance - (float) $card->current_balance - $voided, 0);
    }

    // ------------------------------------------------------------------
    //  Aggregates
    // ------------------------------------------------------------------

    private static function stats(Collection $rows): array
    {
        $count = fn (array $statuses) => $rows->whereIn('status', $statuses)->count();

        return [
            'total' => $rows->count(),
            'active' => $count(['active']),
            'pending' => $count(['pending']),
            'partially_redeemed' => $count(['partially_redeemed']),
            'redeemed' => $count(['redeemed']),
            'expired' => $count(['expired']),
            'cancelled' => $count(['cancelled', 'voided', 'deactivated']),
        ];
    }

    private static function earningType(Collection $rows): array
    {
        $digital = $rows->where('type', 'digital');
        $physical = $rows->where('type', 'physical');

        return [
            'total' => $rows->count(),
            'digital_count' => $digital->count(),
            'physical_count' => $physical->count(),
            'digital_amount' => round($digital->sum('amount'), 2),
            'physical_amount' => round($physical->sum('amount'), 2),
        ];
    }

    private static function financial(Collection $rows): array
    {
        $sumWhere = fn (array $statuses, string $field) => round($rows->whereIn('status', $statuses)->sum($field), 2);

        return [
            'total_purchased' => round($rows->sum('amount'), 2),
            'total_redeemed' => round($rows->sum('redeemed_amount'), 2),
            'active_not_redeemed' => $sumWhere(['active', 'partially_redeemed'], 'balance'),
            'total_expired' => $sumWhere(['expired'], 'balance'),
            'total_voided_cancelled' => round(
                $rows->whereIn('status', ['cancelled', 'voided', 'deactivated'])
                    ->sum(fn (array $r) => $r['status'] === 'deactivated' ? $r['balance'] : max($r['amount'] - $r['redeemed_amount'], 0)),
                2
            ),
            'pending_amount' => $sumWhere(['pending'], 'amount'),
        ];
    }

    private static function pendingActions(Collection $rows): array
    {
        $brief = fn (array $r) => [
            'ref' => $r['ref'],
            'code' => $r['code'],
            'order_number' => $r['order_number'],
            'amount' => $r['amount'],
            'recipient_name' => $r['recipient']['name'],
            'buyer_name' => $r['buyer']['business_name'] ?: $r['buyer']['name'],
            'purchased_at' => $r['purchased_at'],
            'expires_at' => $r['expires_at'],
            'flagged_reason' => $r['flagged_reason'],
            'flagged_at' => $r['flagged_at'],
        ];

        $soon = now()->addDays(self::NEARING_EXPIRY_DAYS);
        $pending = $rows->where('status', 'pending')->values();
        $nearing = $rows->filter(fn (array $r) => in_array($r['status'], ['active', 'partially_redeemed'], true)
            && $r['expires_at'] && Carbon::parse($r['expires_at'])->lte($soon))
            ->sortBy('expires_at')->values();
        $flagged = $rows->filter(fn (array $r) => ! empty($r['flagged_reason'])
            && ! in_array($r['status'], ['cancelled', 'voided'], true))->values();

        return [
            'pending_orders' => ['count' => $pending->count(), 'items' => $pending->map($brief)->all()],
            'nearing_expiration' => ['count' => $nearing->count(), 'items' => $nearing->map($brief)->all()],
            'flagged' => ['count' => $flagged->count(), 'items' => $flagged->map($brief)->all()],
        ];
    }

    // ------------------------------------------------------------------
    //  Details helpers
    // ------------------------------------------------------------------

    private static function timeline(?EcommerceGiftCardOrder $order, ?EcommerceGiftCard $card): array
    {
        $events = [];
        $push = function (string $kind, string $title, string $text, $at) use (&$events) {
            if ($at) {
                $events[] = ['kind' => $kind, 'title' => $title, 'description' => $text, 'at' => Carbon::parse($at)->toIso8601String()];
            }
        };

        if ($order) {
            $push('purchased', 'Purchased', 'Gift card order was created and paid.', $order->paid_at ?? $order->created_at);
        } elseif ($card) {
            $push('purchased', $card->issue_type === 'Manual' ? 'Issued' : 'Purchased', $card->issue_type === 'Manual' ? 'Gift card was issued to the recipient.' : 'Gift card order was created and paid.', $card->purchased_at ?? $card->created_at);
        }

        if ($order?->declined_at) {
            $push('cancelled', 'Declined', $order->declined_reason ?: 'Gift card order was declined.', $order->declined_at);
        }

        if ($card) {
            $issuedAt = $order?->approved_at ?? $card->created_at;
            $isPhysical = str_contains(strtolower((string) $card->delivery_type), 'physical');
            $push(
                'delivered',
                $isPhysical ? 'Shipped' : 'Delivered',
                $isPhysical ? 'Physical gift card was prepared for shipping.' : 'Gift card was sent to ' . ($card->recipient_email ?: 'the recipient') . '.',
                $issuedAt
            );

            $initial = (float) $card->initial_balance;
            $redeemedSoFar = 0.0;
            foreach ($card->transactions()->oldest()->get() as $tx) {
                $type = (string) $tx->transaction_type;
                if (in_array($type, ['Redemption', 'Debit'], true)) {
                    $redeemedSoFar += abs((float) $tx->amount);
                    $full = $redeemedSoFar >= $initial - 0.001;
                    $push($full ? 'redeemed' : 'partial', $full ? 'Redeemed' : 'Partially Redeemed', '$' . number_format(abs((float) $tx->amount), 2) . ' was redeemed from the gift card.', $tx->created_at);
                } elseif ($type === 'Refund') {
                    $push('refund', 'Refunded', '$' . number_format(abs((float) $tx->amount), 2) . ' was returned to the gift card.', $tx->created_at);
                } elseif ($type === 'Void') {
                    $push('voided', 'Voided', '$' . number_format(abs((float) $tx->amount), 2) . ' unused balance was cancelled.', $tx->created_at);
                } elseif ($type === 'Reversal') {
                    $push('voided', 'Reversed', '$' . number_format(abs((float) $tx->amount), 2) . ' purchase was reversed and refunded.', $tx->created_at);
                }
            }

            foreach ($card->activityLogs()->whereIn('action', ['Deactivated', 'Reactivated', 'Disabled', 'Enabled', 'Transferred'])->oldest()->get() as $log) {
                $label = ['Disabled' => 'Deactivated', 'Enabled' => 'Reactivated'][$log->action] ?? $log->action;
                $text = match ($label) {
                    'Deactivated' => 'Gift card was deactivated.',
                    'Reactivated' => 'Gift card was reactivated.',
                    'Transferred' => 'Gift card was transferred to ' . $log->new_value . '.',
                    default => $label,
                };
                $push(strtolower($label), $label, $text, $log->created_at);
            }

            if ($card->expires_at) {
                $expiry = $card->expires_at->copy()->endOfDay();
                $push('expired', $expiry->isPast() ? 'Expired' : 'Expires', $expiry->isPast() ? 'Gift card expired.' : 'Gift card will expire.', $card->expires_at->copy()->startOfDay());
            }
        } elseif ($order && ! $order->declined_at) {
            $push('pending', 'Awaiting Approval', 'Gift card order is waiting to be approved and issued.', $order->paid_at ?? $order->created_at);
        }

        usort($events, fn ($a, $b) => strcmp($a['at'], $b['at']));

        return $events;
    }

    /**
     * The most recent decision on this gift card, for the popup "result" views
     * (Approved / Declined / Deactivated / Reactivated / Voided / Reversed).
     */
    private static function lastAction(string $status, ?EcommerceGiftCardOrder $order, ?EcommerceGiftCard $card): ?array
    {
        if ($status === 'pending') {
            return null;
        }

        if (! $card) {
            if ($order?->declined_at) {
                return [
                    'kind' => 'declined',
                    'by' => $order->processed_by_name ?: ($order->processed_by ? User::find($order->processed_by)?->name : null),
                    'at' => $order->declined_at->toIso8601String(),
                    'reason' => $order->declined_reason,
                    'description' => $order->declined_description,
                    'action_type' => null,
                ];
            }

            return null;
        }

        $kinds = [
            'Approved' => 'approved', 'Issued' => 'approved',
            'Deactivated' => 'deactivated', 'Disabled' => 'deactivated',
            'Reactivated' => 'reactivated', 'Enabled' => 'reactivated',
            'Voided' => 'voided', 'Reversed' => 'reversed',
        ];
        $logs = $card->activityLogs()->whereIn('action', array_keys($kinds))->orderByDesc('created_at')->orderByDesc('id')->get();
        $log = $logs->first();
        if (! $log) {
            return null;
        }

        $kind = $kinds[$log->action];
        $meta = is_array($log->meta) ? $log->meta : [];
        $actorId = $log->admin_id ?: $log->user_id;
        if ($kind === 'approved' && $log->action === 'Issued') {
            $actorId = $log->admin_id; // admin issue logs carry the buyer in user_id
        }
        $by = $kind === 'approved'
            ? ($order?->processed_by_name ?: ($actorId ? User::find($actorId)?->name : null))
            : ($actorId ? User::find($actorId)?->name : null);

        $result = [
            'kind' => $kind,
            'by' => $by,
            'at' => optional($log->created_at)->toIso8601String(),
            'reason' => $meta['reason'] ?? ($kind === 'deactivated' || $kind === 'voided' ? $card->disabled_reason : null),
            'description' => $meta['description'] ?? null,
            'action_type' => $meta['action_type'] ?? null,
        ];

        if ($kind === 'reactivated') {
            $previous = $logs->first(fn ($l) => in_array($l->action, ['Deactivated', 'Disabled'], true));
            $result['deactivated_at'] = optional($previous?->created_at)->toIso8601String();
        }

        return $result;
    }

    private static function paymentLabel(?EcommerceGiftCardOrder $order): string
    {
        if (! $order) {
            return 'N/A';
        }
        $method = strtolower((string) $order->payment_method);
        if ($method === 'wallet') {
            return 'Mecarvi Wallet';
        }
        if ($order->card_last4) {
            return trim(($order->card_brand ?: 'Card') . ' ending in ' . $order->card_last4);
        }

        return $method ? ucfirst($method) : 'Card';
    }

    private static function maskCode(string $code): string
    {
        $tail = substr($code, -4);
        $prefix = str_starts_with(strtoupper($code), 'GC-') ? 'GC-' : '';

        return $prefix . '••••••••' . $tail;
    }

    private static function deviceName(?string $ua, ?string $ip): ?string
    {
        if (! $ua) {
            return null;
        }
        $prefix = preg_match('/iPad|Tablet/i', $ua) ? 'TAB' : (preg_match('/Mobi|Android|iPhone/i', $ua) ? 'MOB' : 'DESK');

        return $prefix . '-' . strtoupper(substr(md5($ua . '|' . $ip), 0, 12));
    }

    private static function operatingSystem(?string $ua): ?string
    {
        if (! $ua) {
            return null;
        }

        return match (true) {
            (bool) preg_match('/Windows NT 10/i', $ua) => 'Windows 10/11 (Desktop)',
            (bool) preg_match('/Windows/i', $ua) => 'Windows (Desktop)',
            (bool) preg_match('/iPad/i', $ua) => 'iPadOS (Tablet)',
            (bool) preg_match('/iPhone|iOS/i', $ua) => 'iOS (Mobile)',
            (bool) preg_match('/Android/i', $ua) => 'Android (Mobile)',
            (bool) preg_match('/Mac OS X|Macintosh/i', $ua) => 'macOS (Desktop)',
            (bool) preg_match('/Linux/i', $ua) => 'Linux (Desktop)',
            default => 'Unknown',
        };
    }

    private static function browser(?string $ua): ?string
    {
        if (! $ua) {
            return null;
        }
        foreach ([
            '/Edg\/([\d.]+)/' => 'Microsoft Edge',
            '/OPR\/([\d.]+)/' => 'Opera',
            '/Firefox\/([\d.]+)/' => 'Mozilla Firefox',
            '/Chrome\/([\d.]+)/' => 'Google Chrome',
            '/Version\/([\d.]+).*Safari/' => 'Safari',
        ] as $pattern => $name) {
            if (preg_match($pattern, $ua, $m)) {
                return "{$name} (v{$m[1]})";
            }
        }

        return 'Unknown';
    }
}
