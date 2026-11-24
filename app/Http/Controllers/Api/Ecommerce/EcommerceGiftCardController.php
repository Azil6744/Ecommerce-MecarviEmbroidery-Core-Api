<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\EcommerceGiftCard;
use App\Models\EcommerceGiftCardSetting;
use App\Models\EcommerceGiftCardTransaction;
use App\Models\EcommerceGiftCardTransfer;
use App\Models\User;
use App\Support\GiftCardMailer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class EcommerceGiftCardController extends Controller
{
    public function index(Request $request)
    {
        $query = EcommerceGiftCard::with(['order:id,order_number,total_amount,status,order_date', 'user:id,name,email']);

        if (! $this->isAdminGiftCardRequest($request)) {
            $user = $request->user();
            if ($user && Schema::hasColumn((new EcommerceGiftCard)->getTable(), 'user_id')) {
                $query->where(function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                    if (Schema::hasColumn((new EcommerceGiftCard)->getTable(), 'buyer_user_id')) {
                        $q->orWhere('buyer_user_id', $user->id);
                    }
                    if ($user->email) {
                        $email = strtolower(trim($user->email));
                        if (Schema::hasColumn((new EcommerceGiftCard)->getTable(), 'buyer_email')) {
                            $q->orWhereRaw('LOWER(buyer_email) = ?', [$email]);
                        }
                        if (Schema::hasColumn((new EcommerceGiftCard)->getTable(), 'owner_email')) {
                            $q->orWhereRaw('LOWER(owner_email) = ?', [$email]);
                        }
                        if (Schema::hasColumn((new EcommerceGiftCard)->getTable(), 'recipient_email')) {
                            $q->orWhereRaw('LOWER(recipient_email) = ?', [$email]);
                        }
                    }
                });
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if ($request->filled('status')) {
            $query->whereRaw('LOWER(status) = ?', [strtolower((string) $request->status)]);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('recipient_name', 'like', "%{$search}%")
                    ->orWhere('recipient_email', 'like', "%{$search}%")
                    ->orWhere('sender_name', 'like', "%{$search}%");
            });
        }

        $cards = $query->latest()->get()->map(fn (EcommerceGiftCard $giftCard) => $this->giftCardPayload($giftCard));

        return response()->json(['success' => true, 'data' => $cards]);
    }

    public function store(Request $request)
    {
        $settings = $this->currentSettings();

        $data = $request->validate([
            // Sender (who the card is from)
            'sender_type' => 'nullable|string|in:customer,business,admin',
            'sender_user_id' => 'nullable|integer',
            'sender_name' => 'nullable|string|max:255',
            'sender_email' => 'nullable|email|max:255',
            'sender_phone' => 'nullable|string|max:50',
            'sender_business_name' => 'nullable|string|max:255',
            'sender_contact_person' => 'nullable|string|max:255',
            // Recipient
            'recipient_name' => 'required|string|max:255',
            'recipient_email' => 'nullable|email|max:255|required_unless:giftcard_type,physical',
            'recipient_phone' => 'nullable|string|max:30',
            'message' => 'nullable|string|max:200',
            'delivery_type' => 'nullable|string|max:50',
            'scheduled_for' => 'nullable|date|after_or_equal:today',
            // Card
            'giftcard_type' => 'nullable|string|in:egift,physical',
            'amount' => 'required|numeric|min:0.01|max:100000',
            'currency' => 'nullable|string|max:10',
            'quantity' => 'nullable|integer|min:1|max:50',
            'expires_at' => 'nullable|date|after:today',
            'expiration_policy' => 'nullable|string|max:80',
            'allow_partial_redemption' => 'nullable|boolean',
            'design_theme' => 'nullable|string|max:50',
            // Shipping (physical only)
            'shipping_name' => 'required_if:giftcard_type,physical|nullable|string|max:255',
            'shipping_address_line1' => 'required_if:giftcard_type,physical|nullable|string|max:255',
            'shipping_address_line2' => 'nullable|string|max:255',
            'shipping_city' => 'required_if:giftcard_type,physical|nullable|string|max:120',
            'shipping_state' => 'required_if:giftcard_type,physical|nullable|string|max:120',
            'shipping_zip' => 'required_if:giftcard_type,physical|nullable|string|max:20',
            // Code & security
            'code_gen_method' => 'nullable|string|in:auto,custom',
            'custom_code' => ['nullable', 'required_if:code_gen_method,custom', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]{6,40}$/'],
            'fraud_monitoring' => 'nullable|boolean',
            'restrict_first_redemption' => 'nullable|boolean',
            'notify_on_redemption' => 'nullable|boolean',
            // Issuance
            'issue_type' => 'nullable|string|in:Purchased,Complimentary,Replacement',
            'internal_notes' => 'nullable|string|max:300',
        ]);

        $kind = $data['giftcard_type'] ?? 'egift';
        $typeCfg = $settings[$kind === 'physical' ? 'physical' : 'egift'];
        if (empty($typeCfg['enabled'])) {
            return response()->json(['success' => false, 'message' => ($kind === 'physical' ? 'Physical' : 'E-gift').' cards are disabled in Gift Card Settings.'], 422);
        }

        // Card value must be a preset, or a custom amount inside the configured range.
        $amount = round((float) $data['amount'], 2);
        $isPreset = collect($typeCfg['preset_values'] ?? [])->contains(fn ($v) => abs((float) $v - $amount) < 0.001);
        if (! $isPreset) {
            if (empty($typeCfg['allow_custom'])) {
                return response()->json(['success' => false, 'message' => 'Custom amounts are disabled for this card type.'], 422);
            }
            if ($amount < (float) $typeCfg['min_custom'] || $amount > (float) $typeCfg['max_custom']) {
                return response()->json([
                    'success' => false,
                    'message' => sprintf('Custom amount must be between $%.2f and $%.2f.', $typeCfg['min_custom'], $typeCfg['max_custom']),
                ], 422);
            }
        }

        $quantity = (int) ($data['quantity'] ?? 1);
        $customBase = ($data['code_gen_method'] ?? 'auto') === 'custom' ? strtoupper(trim((string) $data['custom_code'])) : null;
        if ($customBase !== null) {
            $wanted = $quantity > 1 ? array_map(fn ($i) => $customBase.'-'.$i, range(1, $quantity)) : [$customBase];
            $taken = EcommerceGiftCard::whereIn('code', $wanted)->pluck('code')->all();
            if ($taken) {
                return response()->json(['success' => false, 'message' => 'Gift card code already in use: '.implode(', ', $taken)], 422);
            }
        }

        $expiresAt = $data['expires_at'] ?? null;
        if (! $expiresAt && ! empty($settings['expiry_enabled'])) {
            $expiresAt = now()->addMonths((int) $settings['expiry_months'])->toDateString();
        }

        $scheduledFor = ! empty($data['scheduled_for']) ? Carbon::parse($data['scheduled_for'])->startOfDay() : null;
        $isScheduled = $scheduledFor && $scheduledFor->isFuture();

        $recipientUser = ! empty($data['recipient_email']) ? User::where('email', $data['recipient_email'])->first() : null;
        $admin = $request->user();

        return DB::transaction(function () use ($data, $kind, $amount, $quantity, $customBase, $expiresAt, $scheduledFor, $isScheduled, $recipientUser, $admin, $request) {
            $cards = [];
            for ($i = 0; $i < $quantity; $i++) {
                $code = $customBase !== null
                    ? ($quantity > 1 ? $customBase.'-'.($i + 1) : $customBase)
                    : $this->generateUniqueGiftCardCode();

                $giftCard = EcommerceGiftCard::create([
                    'code' => $code,
                    'recipient_name' => $data['recipient_name'],
                    'recipient_email' => $data['recipient_email'] ?? '',
                    'recipient_phone' => $data['recipient_phone'] ?? null,
                    'sender_name' => $data['sender_name'] ?? ($admin?->name ?? 'Admin'),
                    'sender_type' => $data['sender_type'] ?? 'admin',
                    'sender_user_id' => $data['sender_user_id'] ?? null,
                    'sender_email' => $data['sender_email'] ?? null,
                    'sender_phone' => $data['sender_phone'] ?? null,
                    'sender_business_name' => $data['sender_business_name'] ?? null,
                    'sender_contact_person' => $data['sender_contact_person'] ?? null,
                    'giftcard_type' => $kind,
                    'initial_balance' => $amount,
                    'current_balance' => $amount,
                    'status' => $isScheduled ? 'scheduled' : 'active',
                    'expires_at' => $expiresAt,
                    'expiration_policy' => $data['expiration_policy'] ?? null,
                    'delivery_type' => $kind === 'physical' ? 'Physical' : ($data['delivery_type'] ?? 'Email'),
                    'message' => $data['message'] ?? null,
                    'scheduled_for' => $scheduledFor,
                    'currency' => $data['currency'] ?? 'USD',
                    'purchased_at' => Carbon::now(),
                    'user_id' => $recipientUser?->id,
                    'owner_email' => $data['recipient_email'] ?? null,
                    'issue_type' => 'Manual',
                    'issuance_type' => $data['issue_type'] ?? 'Purchased',
                    'issued_by_admin_id' => $admin?->id,
                    'design_theme' => $data['design_theme'] ?? 'default',
                    'allow_partial_redemption' => $data['allow_partial_redemption'] ?? true,
                    'restrict_first_redemption' => $data['restrict_first_redemption'] ?? false,
                    'notify_on_redemption' => $data['notify_on_redemption'] ?? false,
                    'fraud_monitoring' => $data['fraud_monitoring'] ?? false,
                    'internal_notes' => $data['internal_notes'] ?? null,
                    'shipping_name' => $kind === 'physical' ? $data['shipping_name'] : null,
                    'shipping_address_line1' => $kind === 'physical' ? $data['shipping_address_line1'] : null,
                    'shipping_address_line2' => $kind === 'physical' ? ($data['shipping_address_line2'] ?? null) : null,
                    'shipping_city' => $kind === 'physical' ? $data['shipping_city'] : null,
                    'shipping_state' => $kind === 'physical' ? $data['shipping_state'] : null,
                    'shipping_zip' => $kind === 'physical' ? $data['shipping_zip'] : null,
                ]);

                $giftCard->transactions()->create([
                    'transaction_type' => 'Issue',
                    'amount' => $amount,
                    'notes' => sprintf('%s gift card issued by admin (%s).', $kind === 'physical' ? 'Physical' : 'E-gift', $data['issue_type'] ?? 'Purchased'),
                    'created_by' => $admin?->id,
                ]);

                $giftCard->activityLogs()->create([
                    'action' => 'Manual Gift Card Issued',
                    'admin_id' => $admin?->id,
                    'old_value' => null,
                    'new_value' => json_encode($giftCard->only(['id', 'code', 'initial_balance', 'recipient_email', 'giftcard_type', 'issuance_type'])),
                    'ip_address' => $request->ip(),
                ]);

                // E-gift cards are emailed now unless scheduled (SendScheduledGiftCards
                // delivers those). Physical cards are shipped, not emailed.
                if ($kind === 'egift' && ! $isScheduled && ! empty($data['recipient_email'])) {
                    $emailSent = GiftCardMailer::sendIssued($data['recipient_email'], [
                        'code' => $code,
                        'balance' => $amount,
                        'message' => $data['message'] ?? null,
                        'recipient_name' => $data['recipient_name'],
                        'sender_name' => $giftCard->sender_name,
                        'expires_at' => $giftCard->expires_at ? $giftCard->expires_at->toDateString() : '',
                        'design_theme' => $giftCard->design_theme,
                    ]);

                    if ($emailSent) {
                        $giftCard->update(['status' => 'delivered']);
                    }
                }

                $cards[] = $this->giftCardPayload($giftCard->fresh(['order', 'user']));
            }

            return response()->json([
                'success' => true,
                'message' => $quantity > 1 ? "{$quantity} gift cards issued." : 'Gift card issued.',
                'data' => $cards[0],
                'cards' => $cards,
            ], 201);
        });
    }

    public function show(Request $request, $id)
    {
        $item = $this->resolveGiftCard($request, $id)->load(['order:id,order_number,total_amount,status,order_date', 'user:id,name,email']);
        return response()->json(['success' => true, 'data' => $this->giftCardPayload($item)]);
    }

    public function update(Request $request, $id)
    {
        $item = $this->resolveGiftCard($request, $id);

        // Code, original value and balance are fixed once issued: balance only
        // moves through ledgered actions (redeem, adjust, void), never a raw edit.
        foreach (['code', 'initial_balance', 'current_balance'] as $locked) {
            if ($request->has($locked) && (string) $request->input($locked) !== (string) $item->{$locked}
                && ! (is_numeric($request->input($locked)) && abs((float) $request->input($locked) - (float) $item->{$locked}) < 0.001)) {
                return response()->json([
                    'success' => false,
                    'message' => $locked === 'code'
                        ? 'The gift card code cannot be changed.'
                        : 'The original value and balance cannot be edited. Use Adjust Balance instead.',
                ], 422);
            }
        }

        $data = $request->validate([
            'recipient_name' => 'sometimes|string|max:255',
            'recipient_email' => 'sometimes|nullable|email',
            'sender_name' => 'sometimes|nullable|string|max:255',
            'status' => 'sometimes|in:active,disabled',
            'expires_at' => 'sometimes|nullable|date',
            'delivery_type' => 'sometimes|nullable|string|max:50',
            'message' => 'sometimes|nullable|string',
            'scheduled_for' => 'sometimes|nullable|date',
            'currency' => 'sometimes|nullable|string|max:10',
        ]);

        $current = $this->normalizeStatus($item->status);
        if (in_array($current, ['voided', 'cancelled'], true)) {
            return response()->json(['success' => false, 'message' => 'Voided or cancelled gift cards cannot be edited or reactivated.'], 422);
        }
        // Status edits here only toggle active/disabled; keep redeemed/partial states as they are.
        if (isset($data['status']) && ! in_array($current, ['active', 'delivered', 'disabled'], true)) {
            unset($data['status']);
        }

        $before = $item->only(array_keys($data));
        $item->update($data);

        $changed = array_filter($data, fn ($v, $k) => (string) ($before[$k] ?? '') !== (string) $v, ARRAY_FILTER_USE_BOTH);
        if ($this->isAdminGiftCardRequest($request) && $changed) {
            $item->activityLogs()->create([
                'action' => 'Details Edited',
                'admin_id' => $request->user()?->id,
                'old_value' => json_encode(array_intersect_key($before, $changed)),
                'new_value' => json_encode($changed),
                'ip_address' => $request->ip(),
            ]);
        }

        return response()->json(['success' => true, 'data' => $this->giftCardPayload($item->fresh(['order', 'user']))]);
    }

    public function destroy(Request $request, $id)
    {
        $item = $this->resolveGiftCard($request, $id);
        $item->delete();
        return response()->json(['success' => true, 'message' => 'Deleted successfully']);
    }

    /**
     * Adjust balance of a gift card (Admin).
     */
    public function adjustBalance(Request $request, $id)
    {
        $request->validate([
            'amount' => 'required|numeric',
            'notes' => 'required|string|max:255',
        ]);

        $giftCard = EcommerceGiftCard::findOrFail($id);
        if (in_array($this->normalizeStatus($giftCard->status), ['voided', 'cancelled'], true)) {
            return response()->json(['success' => false, 'message' => 'Voided gift cards cannot be adjusted.'], 422);
        }
        $oldBalance = $giftCard->current_balance;
        $newBalance = $oldBalance + $request->amount;

        if ($newBalance < 0) {
            return response()->json([
                'success' => false,
                'message' => 'Adjusted balance cannot be negative.'
            ], 400);
        }

        return DB::transaction(function () use ($giftCard, $request, $oldBalance, $newBalance) {
            $giftCard->update([
                'current_balance' => $newBalance,
            ]);

            $giftCard->transactions()->create([
                'transaction_type' => 'Manual Adjustment',
                'amount' => $request->amount,
                'notes' => $request->notes,
                'created_by' => $request->user()?->id,
            ]);

            $giftCard->activityLogs()->create([
                'action' => 'Balance Adjusted',
                'admin_id' => $request->user()?->id,
                'old_value' => (string) $oldBalance,
                'new_value' => (string) $newBalance,
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Balance adjusted successfully.',
                'data' => $this->giftCardPayload($giftCard->fresh())
            ]);
        });
    }

    /**
     * Disable a gift card (Admin).
     */
    public function disable(Request $request, $id)
    {
        $request->validate([
            'disabled_reason' => 'required|string|max:255',
        ]);

        $giftCard = EcommerceGiftCard::findOrFail($id);
        $oldStatus = $giftCard->status;

        $giftCard->update([
            'status' => 'disabled',
            'disabled_reason' => $request->disabled_reason,
        ]);

        $giftCard->activityLogs()->create([
            'action' => 'Disabled',
            'admin_id' => $request->user()?->id,
            'old_value' => $oldStatus,
            'new_value' => 'disabled',
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Gift card disabled successfully.',
            'data' => $this->giftCardPayload($giftCard)
        ]);
    }

    /**
     * Enable a gift card (Admin).
     */
    public function enable(Request $request, $id)
    {
        $giftCard = EcommerceGiftCard::findOrFail($id);
        $oldStatus = $giftCard->status;

        if (in_array($this->normalizeStatus($oldStatus), ['voided', 'cancelled', 'expired'], true)) {
            return response()->json(['success' => false, 'message' => 'Voided, cancelled or expired gift cards cannot be reactivated.'], 422);
        }

        $giftCard->update([
            'status' => 'active',
            'disabled_reason' => null,
        ]);

        $giftCard->activityLogs()->create([
            'action' => 'Enabled',
            'admin_id' => $request->user()?->id,
            'old_value' => $oldStatus,
            'new_value' => 'active',
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Gift card enabled successfully.',
            'data' => $this->giftCardPayload($giftCard)
        ]);
    }

    /**
     * Transfer gift card ownership (Customer).
     */
    public function transfer(Request $request, $id)
    {
        $request->validate([
            'recipient_email' => 'required|email|max:255',
        ]);

        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $giftCard = EcommerceGiftCard::where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if (\App\Services\GiftCardRedemptionService::blockedReason($giftCard) !== null) { // same rules as checkout (also scheduled / pending)
            return response()->json([
                'success' => false,
                'message' => 'Only active gift cards can be transferred.'
            ], 400);
        }

        if ($giftCard->expires_at && $giftCard->expires_at->isPast()) {
            return response()->json([
                'success' => false,
                'message' => 'This gift card has expired.'
            ], 400);
        }

        if ($giftCard->current_balance <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'This gift card has no remaining balance.'
            ], 400);
        }

        if (strtolower($request->recipient_email) === strtolower($user->email)) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot transfer a gift card to yourself.'
            ], 400);
        }

        return DB::transaction(function () use ($giftCard, $request, $user) {
            $recipientUser = User::where('email', $request->recipient_email)->first();
            $newOwnerId = $recipientUser?->id;
            
            $oldOwnerId = $giftCard->user_id;
            $oldOwnerEmail = $user->email;
            $recipientEmail = $request->recipient_email;

            // Record transfer
            EcommerceGiftCardTransfer::create([
                'giftcard_id' => $giftCard->id,
                'old_owner_id' => $oldOwnerId,
                'new_owner_id' => $newOwnerId,
                'old_owner_email' => $oldOwnerEmail,
                'new_owner_email' => $recipientEmail,
                'transfer_reason' => 'Transferred by owner',
                'transferred_at' => now(),
            ]);

            // Record transaction ledger
            $giftCard->transactions()->create([
                'transaction_type' => 'Transfer',
                'amount' => $giftCard->current_balance,
                'notes' => 'Transferred from ' . $oldOwnerEmail . ' to ' . $recipientEmail,
                'created_by' => $user->id,
            ]);

            // Update gift card owner
            $giftCard->update([
                'user_id' => $newOwnerId,
                'owner_email' => $recipientEmail,
                'recipient_email' => $recipientEmail,
                'recipient_name' => $recipientUser?->name ?: $recipientEmail,
            ]);

            // Activity Log
            $giftCard->activityLogs()->create([
                'action' => 'Transferred',
                'user_id' => $user->id,
                'old_value' => $oldOwnerEmail,
                'new_value' => $recipientEmail,
                'ip_address' => $request->ip(),
            ]);

            // Send notification emails
            GiftCardMailer::sendTransferredToOldOwner($oldOwnerEmail, [
                'code' => $giftCard->code,
                'balance' => $giftCard->current_balance,
                'new_owner_email' => $recipientEmail,
            ]);

            GiftCardMailer::sendTransferredToNewOwner($recipientEmail, [
                'code' => $giftCard->code,
                'balance' => $giftCard->current_balance,
                'old_owner_email' => $oldOwnerEmail,
                'expires_at' => $giftCard->expires_at ? $giftCard->expires_at->toDateString() : '',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Gift card transferred successfully.',
                'data' => $this->giftCardPayload($giftCard->fresh())
            ]);
        });
    }

    /**
     * Validate a gift card code.
     */
    public function validateCode(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $giftCard = EcommerceGiftCard::whereRaw('UPPER(code) = ?', [strtoupper(trim((string) $request->code))])->first();

        if (!$giftCard) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid gift card code.'
            ], 404);
        }

        // Same rules checkout applies (status, expiry, balance, minimum balance to redeem).
        if ($reason = \App\Services\GiftCardRedemptionService::blockedReason($giftCard)) {
            return response()->json(['success' => false, 'message' => $reason], 400);
        }
        $minBalance = (float) ($this->currentSettings()['min_redeem_balance'] ?? 0);
        if ($minBalance > 0 && (float) $giftCard->current_balance < $minBalance) {
            return response()->json([
                'success' => false,
                'message' => sprintf('This gift card needs a balance of at least $%.2f to be used.', $minBalance),
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Gift card code is valid.',
            'data' => $this->giftCardPayload($giftCard),
        ]);
    }


    /** Public: what customers can buy (presets, custom range, enabled types). Read by the panels' purchase form. */
    public function publicSettings()
    {
        return response()->json([
            'success' => true,
            'data' => \App\Services\GiftCardPurchaseRules::publicSettings(),
        ]);
    }

    public function settings()
    {
        return response()->json([
            'success' => true,
            'data' => $this->currentSettings(),
        ]);
    }

    public function saveSettings(Request $request)
    {
        $money = 'numeric|min:0|max:100000';
        $typeRules = fn (string $k) => [
            $k => 'sometimes|array',
            "{$k}.enabled" => 'boolean',
            "{$k}.preset_values" => 'array|max:20',
            "{$k}.preset_values.*" => 'numeric|min:0.01|max:100000',
            "{$k}.allow_custom" => 'boolean',
            "{$k}.min_custom" => $money,
            "{$k}.max_custom" => $money,
        ];

        $validated = $request->validate(array_merge([
            'enable_module' => 'boolean',
            'require_manual_approval' => 'boolean',
            'prefix' => ['nullable', 'string', 'max:6', 'regex:/^[A-Za-z0-9]*$/'],
            'code_length' => 'integer|min:8|max:24',
            'code_format' => 'in:Alphanumeric,Numeric,Letters',
            'min_purchase_amount' => $money,
            'max_purchase_amount' => $money,
            'daily_purchase_limit' => 'integer|min:0|max:1000',
            'allow_partial_redemption' => 'boolean',
            'expiry_enabled' => 'boolean',
            'expiry_months' => 'integer|min:1|max:120',
            'expiry_type' => 'in:Date of Issue,Date of Purchase,Date of First Use',
            'send_expiry_reminder' => 'boolean',
            'min_redeem_balance' => $money,
            'max_single_redemption' => $money,
            'allow_multiple_cards' => 'boolean',
            'refund_policy' => 'in:Refund to Gift Card,Original Payment Method,Store Credit',
            'duplicate_code_check' => 'boolean',
            'email_verification' => 'boolean',
            'flag_suspicious' => 'boolean',
            'auto_deactivate_months' => 'integer|min:0|max:120',
            'notification_rules' => 'array|max:50',
            'notification_rules.*.id' => 'nullable',
            'notification_rules.*.name' => 'required|string|max:120',
            'notification_rules.*.trigger_condition' => 'required|string|max:255',
            'notification_rules.*.notify_when' => 'nullable|string|max:120',
            'notification_rules.*.notification_method' => 'required|string|max:120',
            'notification_rules.*.status' => 'in:Active,Inactive',
        ], $typeRules('physical'), $typeRules('egift')));

        $payload = $this->currentSettings();
        foreach ($validated as $key => $value) {
            if (in_array($key, ['physical', 'egift'], true)) {
                $payload[$key] = array_merge($payload[$key], $value);
            } else {
                // Lists (notification_rules) replace wholesale so deletions stick.
                $payload[$key] = $value;
            }
        }
        $payload = $this->normalizeSettings($payload);

        foreach (['physical', 'egift'] as $k) {
            if ($payload[$k]['allow_custom'] && $payload[$k]['min_custom'] > $payload[$k]['max_custom']) {
                return response()->json(['success' => false, 'message' => 'Minimum custom amount cannot exceed the maximum.'], 422);
            }
        }
        if ($payload['min_purchase_amount'] > $payload['max_purchase_amount']) {
            return response()->json(['success' => false, 'message' => 'Minimum purchase amount cannot exceed the maximum.'], 422);
        }
        if ($payload['code_length'] - strlen($payload['prefix']) < 4) {
            return response()->json(['success' => false, 'message' => 'Code length must leave at least 4 characters after the prefix.'], 422);
        }

        $settings = EcommerceGiftCardSetting::query()->first();
        if ($settings) {
            $settings->update(['settings' => $payload]);
        } else {
            EcommerceGiftCardSetting::create(['settings' => $payload]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Gift card settings saved successfully.',
            'data' => $payload,
        ]);
    }

    /** Stored settings merged over defaults, with legacy string values ("$10.00", "12 Months") converted. */
    public function currentSettings(): array
    {
        $stored = EcommerceGiftCardSetting::query()->first()?->settings;
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }

        return $this->normalizeSettings(is_array($stored) ? $stored : []);
    }

    private function normalizeSettings(array $raw): array
    {
        $d = $this->defaultSettings();
        $num = function ($v, $fallback) {
            if (is_string($v)) {
                $v = preg_replace('/[^0-9.\-]/', '', $v);
            }

            return is_numeric($v) ? (float) $v : (float) $fallback;
        };
        $bool = fn ($v, $fallback) => $v === null ? (bool) $fallback : filter_var($v, FILTER_VALIDATE_BOOLEAN);
        $type = function ($r, array $f) use ($num, $bool) {
            $r = is_array($r) ? $r : [];
            $presets = array_key_exists('preset_values', $r) && is_array($r['preset_values'])
                ? array_values(array_unique(array_filter(array_map(fn ($v) => round($num($v, 0), 2), $r['preset_values']), fn ($v) => $v > 0)))
                : $f['preset_values'];
            sort($presets);

            return [
                'enabled' => $bool($r['enabled'] ?? null, $f['enabled']),
                'preset_values' => $presets,
                'allow_custom' => $bool($r['allow_custom'] ?? null, $f['allow_custom']),
                'min_custom' => $num($r['min_custom'] ?? null, $f['min_custom']),
                'max_custom' => $num($r['max_custom'] ?? null, $f['max_custom']),
            ];
        };
        $pick = fn ($v, array $allowed, $fallback) => in_array($v, $allowed, true) ? $v : $fallback;
        $rules = array_key_exists('notification_rules', $raw) && is_array($raw['notification_rules'])
            ? array_values(array_map(fn ($r, $i) => [
                'id' => (string) ($r['id'] ?? 'rule-'.$i),
                'name' => (string) ($r['name'] ?? ''),
                'trigger_condition' => (string) ($r['trigger_condition'] ?? ''),
                'notify_when' => (string) ($r['notify_when'] ?? ''),
                'notification_method' => (string) ($r['notification_method'] ?? 'Email'),
                'status' => ($r['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active',
            ], $raw['notification_rules'], array_keys($raw['notification_rules'])))
            : $d['notification_rules'];

        return [
            'enable_module' => $bool($raw['enable_module'] ?? null, $d['enable_module']),
            'require_manual_approval' => $bool($raw['require_manual_approval'] ?? null, $d['require_manual_approval']),
            'prefix' => strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($raw['prefix'] ?? $d['prefix'])), 0, 6)),
            'code_length' => (int) max(8, min(24, $num($raw['code_length'] ?? null, $d['code_length']))),
            'code_format' => $pick($raw['code_format'] ?? null, ['Alphanumeric', 'Numeric', 'Letters'], $d['code_format']),
            'physical' => $type($raw['physical'] ?? null, $d['physical']),
            'egift' => $type($raw['egift'] ?? null, $d['egift']),
            'min_purchase_amount' => $num($raw['min_purchase_amount'] ?? $raw['min_order_amount'] ?? null, $d['min_purchase_amount']),
            'max_purchase_amount' => $num($raw['max_purchase_amount'] ?? $raw['max_balance'] ?? null, $d['max_purchase_amount']),
            'daily_purchase_limit' => (int) $num($raw['daily_purchase_limit'] ?? null, $d['daily_purchase_limit']),
            'allow_partial_redemption' => $bool($raw['allow_partial_redemption'] ?? null, $d['allow_partial_redemption']),
            'expiry_enabled' => $bool($raw['expiry_enabled'] ?? null, $d['expiry_enabled']),
            'expiry_months' => (int) max(1, $num($raw['expiry_months'] ?? $raw['expiry_duration'] ?? null, $d['expiry_months'])),
            'expiry_type' => $pick($raw['expiry_type'] ?? null, ['Date of Issue', 'Date of Purchase', 'Date of First Use'], $d['expiry_type']),
            'send_expiry_reminder' => $bool($raw['send_expiry_reminder'] ?? null, $d['send_expiry_reminder']),
            'min_redeem_balance' => $num($raw['min_redeem_balance'] ?? null, $d['min_redeem_balance']),
            'max_single_redemption' => $num($raw['max_single_redemption'] ?? null, $d['max_single_redemption']),
            'allow_multiple_cards' => $bool($raw['allow_multiple_cards'] ?? null, $d['allow_multiple_cards']),
            'refund_policy' => $pick($raw['refund_policy'] ?? null, ['Refund to Gift Card', 'Original Payment Method', 'Store Credit'], $d['refund_policy']),
            'duplicate_code_check' => $bool($raw['duplicate_code_check'] ?? null, $d['duplicate_code_check']),
            'email_verification' => $bool($raw['email_verification'] ?? null, $d['email_verification']),
            'flag_suspicious' => $bool($raw['flag_suspicious'] ?? null, $d['flag_suspicious']),
            'auto_deactivate_months' => (int) $num($raw['auto_deactivate_months'] ?? $raw['auto_deactivate_unused'] ?? null, $d['auto_deactivate_months']),
            'notification_rules' => $rules,
        ];
    }

    public function transactions(Request $request)
    {
        $query = EcommerceGiftCardTransaction::with('giftCard:id,code,recipient_name,recipient_email');

        if ($request->filled('type')) {
            $query->where('transaction_type', $request->type);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('transaction_type', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhereHas('giftCard', function ($cardQuery) use ($search) {
                        $cardQuery->where('code', 'like', "%{$search}%")
                            ->orWhere('recipient_name', 'like', "%{$search}%")
                            ->orWhere('recipient_email', 'like', "%{$search}%");
                    });
            });
        }

        $transactions = $query->latest()->paginate((int) $request->input('per_page', 25));

        $transactions->getCollection()->transform(function (EcommerceGiftCardTransaction $transaction) {
            return [
                'id' => $transaction->id,
                'giftcard_id' => $transaction->giftcard_id,
                'gift_card_code' => $transaction->giftCard?->code,
                'recipient_name' => $transaction->giftCard?->recipient_name,
                'recipient_email' => $transaction->giftCard?->recipient_email,
                'transaction_type' => $transaction->transaction_type,
                'amount' => (float) $transaction->amount,
                'order_id' => $transaction->order_id,
                'notes' => $transaction->notes,
                'created_by' => $transaction->created_by,
                'created_at' => optional($transaction->created_at)?->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $transactions,
        ]);
    }

    private function resolveGiftCard(Request $request, int|string $id): EcommerceGiftCard
    {
        $query = EcommerceGiftCard::query();

        if (! $this->isAdminGiftCardRequest($request)) {
            $user = $request->user();
            if (Schema::hasColumn((new EcommerceGiftCard)->getTable(), 'user_id')) {
                $query->where(function ($q) use ($user) {
                    $q->where('user_id', $user?->id);
                    if (Schema::hasColumn((new EcommerceGiftCard)->getTable(), 'buyer_user_id')) {
                        $q->orWhere('buyer_user_id', $user?->id);
                    }
                });
            }
        }

        return $query->findOrFail($id);
    }

    private function isAdminGiftCardRequest(Request $request): bool
    {
        return $request->is('*admin/gift-cards*') || $request->is('*admin/gift-card-*');
    }

    private function defaultSettings(): array
    {
        $presets = [10, 25, 50, 100, 250, 500];
        $rule = fn ($id, $name, $trigger, $when, $method) => ['id' => $id, 'name' => $name, 'trigger_condition' => $trigger, 'notify_when' => $when, 'notification_method' => $method, 'status' => 'Active'];

        return [
            'enable_module' => true,
            'require_manual_approval' => true,
            'prefix' => 'GFT',
            'code_length' => 15,
            'code_format' => 'Alphanumeric',
            'physical' => ['enabled' => true, 'preset_values' => $presets, 'allow_custom' => true, 'min_custom' => 5.0, 'max_custom' => 500.0],
            'egift' => ['enabled' => true, 'preset_values' => $presets, 'allow_custom' => true, 'min_custom' => 5.0, 'max_custom' => 500.0],
            'min_purchase_amount' => 10.0,
            'max_purchase_amount' => 500.0,
            'daily_purchase_limit' => 10,
            'allow_partial_redemption' => true,
            'expiry_enabled' => true,
            'expiry_months' => 12,
            'expiry_type' => 'Date of Issue',
            'send_expiry_reminder' => true,
            'min_redeem_balance' => 1.0,
            'max_single_redemption' => 250.0,
            'allow_multiple_cards' => true,
            'refund_policy' => 'Refund to Gift Card',
            'duplicate_code_check' => true,
            'email_verification' => true,
            'flag_suspicious' => true,
            'auto_deactivate_months' => 24,
            'notification_rules' => [
                $rule('low', 'Low Balance Alert', 'When balance is less than', '$10.00', 'Email, SMS'),
                $rule('used', 'Balance Used Alert', 'When gift card is used', 'Any amount', 'Email'),
                $rule('expiry', 'Balance Expiry Alert', 'Before card expires', '7 Days', 'Email, SMS'),
                $rule('voided', 'Gift Card Voided', 'When gift card is voided', 'Immediately', 'Email'),
                $rule('deactivated', 'Gift Card Deactivated', 'When gift card is deactivated', 'Immediately', 'Email'),
                $rule('issued', 'Gift Card Issued Manually', 'When a gift card is created manually by admin', 'Immediately', 'Email'),
                $rule('reversed', 'Gift Card Reversed', 'When a gift card is reversed', 'Immediately', 'Email'),
            ],
        ];
    }

    private function normalizeStatus(?string $status): string
    {
        $normalized = strtolower(trim((string) $status));
        $normalized = str_replace('_', ' ', $normalized);
        return in_array($normalized, ['active', 'delivered', 'redeemed', 'expired', 'scheduled', 'cancelled', 'pending', 'disabled', 'partially used', 'partially redeemed', 'fully used', 'voided'], true)
            ? $normalized
            : 'active';
    }

    private function giftCardPayload(EcommerceGiftCard $giftCard): array
    {
        $initial = (float) $giftCard->initial_balance;
        $current = (float) $giftCard->current_balance;
        $redeemed = max($initial - $current, 0);

        $request = request();
        $user = $request->user();
        
        $isOwner = false;
        if ($user) {
            $isOwner = ($giftCard->user_id && $giftCard->user_id === $user->id) ||
                       ($giftCard->recipient_email && strtolower($giftCard->recipient_email) === strtolower($user->email));
        }

        if ($request->filled('code') && $request->code === $giftCard->code) {
            $isOwner = true;
        }
        
        if ($this->isAdminGiftCardRequest($request)) {
            $isOwner = true;
        }

        $code = $isOwner ? $giftCard->code : '••••-••••-••••';

        return [
            'id' => $giftCard->id,
            'user_id' => $giftCard->user_id,
            'order_id' => $giftCard->order_id,
            'order_number' => $giftCard->order?->order_number,
            'code' => $code,
            'recipient_name' => $giftCard->recipient_name,
            'recipient_email' => $giftCard->recipient_email,
            'recipient_phone' => $giftCard->recipient_phone,
            'sender_name' => $giftCard->sender_name,
            'initial_balance' => $initial,
            'current_balance' => $current,
            'redeemed_amount' => round($redeemed, 2),
            'status' => $this->normalizeStatus($giftCard->status),
            'expires_at' => $giftCard->expires_at ? $giftCard->expires_at->toDateString() : null,
            'delivery_type' => $giftCard->delivery_type,
            'message' => $giftCard->message,
            'scheduled_for' => $giftCard->scheduled_for ? $giftCard->scheduled_for->toIso8601String() : null,
            'purchased_at' => ($giftCard->purchased_at ?? $giftCard->created_at) ? ($giftCard->purchased_at ?? $giftCard->created_at)->toIso8601String() : null,
            'redeemed_at' => $giftCard->redeemed_at ? $giftCard->redeemed_at->toIso8601String() : null,
            'currency' => $giftCard->currency ?? 'USD',
            'design_theme' => $giftCard->design_theme,
            'allow_partial_redemption' => (bool)$giftCard->allow_partial_redemption,
            'restrict_first_redemption' => (bool)$giftCard->restrict_first_redemption,
            'notify_on_redemption' => (bool)$giftCard->notify_on_redemption,
            // Internal notes are for authorized admins only, never the card holder.
            'internal_notes' => $this->isAdminGiftCardRequest($request) ? $giftCard->internal_notes : null,
            'giftcard_type' => $giftCard->giftcard_type ?? (strtolower((string) $giftCard->delivery_type) === 'physical' ? 'physical' : 'egift'),
            'issuance_type' => $giftCard->issuance_type,
            'issue_type' => $giftCard->issue_type,
            'expiration_policy' => $giftCard->expiration_policy,
            'fraud_monitoring' => (bool) $giftCard->fraud_monitoring,
            'sender_type' => $giftCard->sender_type,
            'sender_email' => $giftCard->sender_email,
            'sender_phone' => $giftCard->sender_phone,
            'sender_business_name' => $giftCard->sender_business_name,
            'sender_contact_person' => $giftCard->sender_contact_person,
            'shipping_address' => $giftCard->shipping_address_line1 ? [
                'name' => $giftCard->shipping_name,
                'line1' => $giftCard->shipping_address_line1,
                'line2' => $giftCard->shipping_address_line2,
                'city' => $giftCard->shipping_city,
                'state' => $giftCard->shipping_state,
                'zip' => $giftCard->shipping_zip,
            ] : null,
            'created_at' => optional($giftCard->created_at)?->toIso8601String(),
            'updated_at' => optional($giftCard->updated_at)?->toIso8601String(),
            'user' => $giftCard->relationLoaded('user') ? $giftCard->user : null,
            'order' => $giftCard->relationLoaded('order') ? $giftCard->order : null,
            'transactions' => $giftCard->transactions()->with('order:id,order_number')->latest()->get()->map(function ($tx) {
                return [
                    'id' => $tx->id,
                    'transaction_type' => $tx->transaction_type,
                    'amount' => (float) $tx->amount,
                    'notes' => $tx->notes,
                    'order_id' => $tx->order_id,
                    'order_number' => $tx->order?->order_number,
                    'created_at' => optional($tx->created_at)?->toIso8601String(),
                ];
            })->toArray(),
        ];
    }

    /**
     * Generates a code in the format configured in Gift Card Settings
     * (prefix + random part grouped in 4s, e.g. GFT-2X7L-M9K3-P8Q1). Every
     * candidate is checked against all existing cards, so no code is reused.
     */
    private function generateUniqueGiftCardCode(): string
    {
        $s = $this->currentSettings();
        $alphabet = match ($s['code_format']) {
            'Numeric' => '0123456789',
            'Letters' => 'ABCDEFGHJKLMNPQRSTUVWXYZ',
            default => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
        };
        $length = max(4, $s['code_length'] - strlen($s['prefix']));

        do {
            $raw = '';
            for ($i = 0; $i < $length; $i++) {
                $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $code = implode('-', array_filter(array_merge([$s['prefix']], str_split($raw, 4)), fn ($p) => $p !== ''));
        } while (EcommerceGiftCard::where('code', $code)->exists());

        return $code;
    }
}
