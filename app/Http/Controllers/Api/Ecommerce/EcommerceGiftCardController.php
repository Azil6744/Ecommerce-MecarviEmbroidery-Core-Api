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
        $request->validate([
            'recipient_name' => 'required|string',
            'recipient_email' => 'nullable|email',
            'amount' => 'required|numeric|min:0.01',
            'sender_name' => 'nullable|string',
            'expires_at' => 'nullable|date',
            'delivery_type' => 'nullable|string|max:50',
            'message' => 'nullable|string',
            'scheduled_for' => 'nullable|date',
            'currency' => 'nullable|string|max:10',
            // Extended Redesign UI fields
            'recipient_phone' => 'nullable|string|max:30',
            'design_theme' => 'nullable|string|max:50',
            'allow_partial_redemption' => 'nullable|boolean',
            'restrict_first_redemption' => 'nullable|boolean',
            'notify_on_redemption' => 'nullable|boolean',
            'internal_notes' => 'nullable|string',
            'quantity' => 'nullable|integer|min:1|max:50',
            'code_gen_method' => 'nullable|string|in:auto,custom,upload',
            'code_prefix' => 'nullable|string|max:10',
            'custom_code' => 'nullable|string|max:50',
        ]);

        $quantity = $request->input('quantity', 1);
        $recipientUser = null;
        if ($request->filled('recipient_email')) {
            $recipientUser = User::where('email', $request->recipient_email)->first();
        }

        return DB::transaction(function () use ($request, $quantity, $recipientUser) {
            $cards = [];
            for ($i = 0; $i < $quantity; $i++) {
                if ($request->input('code_gen_method') === 'custom') {
                    $code = $request->input('custom_code');
                    if ($quantity > 1) {
                        $code .= '-' . ($i + 1);
                    }
                } else {
                    $prefix = $request->input('code_prefix');
                    $code = $this->generateUniqueGiftCardCode($prefix);
                }

                $giftCard = EcommerceGiftCard::create([
                    'code' => $code,
                    'recipient_name' => $request->recipient_name,
                    'recipient_email' => $request->recipient_email ?? '',
                    'sender_name' => $request->sender_name ?? 'Admin',
                    'initial_balance' => $request->amount,
                    'current_balance' => $request->amount,
                    'status' => 'active',
                    'expires_at' => $request->expires_at ?? null,
                    'delivery_type' => $request->delivery_type ?? 'Manual',
                    'message' => $request->message,
                    'scheduled_for' => $request->scheduled_for,
                    'currency' => $request->currency ?? 'USD',
                    'purchased_at' => Carbon::now(),
                    'user_id' => $recipientUser?->id,
                    'owner_email' => $request->recipient_email ?? null,
                    'issue_type' => 'Manual',
                    'issued_by_admin_id' => $request->user()?->id,
                    // Extended Redesign UI fields
                    'recipient_phone' => $request->recipient_phone,
                    'design_theme' => $request->design_theme ?? 'default',
                    'allow_partial_redemption' => $request->input('allow_partial_redemption', true),
                    'restrict_first_redemption' => $request->input('restrict_first_redemption', false),
                    'notify_on_redemption' => $request->input('notify_on_redemption', false),
                    'internal_notes' => $request->internal_notes,
                ]);

                // Create ledger entry
                $giftCard->transactions()->create([
                    'transaction_type' => 'Issue',
                    'amount' => $request->amount,
                    'notes' => 'Manual gift card issued by admin.',
                    'created_by' => $request->user()?->id,
                ]);

                // Create activity log
                $giftCard->activityLogs()->create([
                    'action' => 'Manual Gift Card Issued',
                    'admin_id' => $request->user()?->id,
                    'old_value' => null,
                    'new_value' => json_encode($giftCard->only(['id', 'code', 'initial_balance', 'recipient_email'])),
                    'ip_address' => $request->ip(),
                ]);

                // Try sending email if recipient email is present
                if ($request->filled('recipient_email')) {
                    $emailSent = GiftCardMailer::sendIssued($request->recipient_email, [
                        'code' => $code,
                        'balance' => $request->amount,
                        'message' => $request->message,
                        'recipient_name' => $request->recipient_name,
                        'sender_name' => $request->sender_name ?? 'Admin',
                        'expires_at' => $giftCard->expires_at ? $giftCard->expires_at->toDateString() : '',
                        'design_theme' => $giftCard->design_theme,
                    ]);

                    if ($emailSent) {
                        $giftCard->update(['status' => 'delivered']);
                    }
                }

                $cards[] = $this->giftCardPayload($giftCard->fresh(['order', 'user']));
            }

            return response()->json(['success' => true, 'data' => $cards[0]], 201);
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

        $data = $request->validate([
            'recipient_name' => 'sometimes|string',
            'recipient_email' => 'sometimes|nullable|email',
            'sender_name' => 'sometimes|nullable|string',
            'initial_balance' => 'sometimes|numeric|min:0',
            'current_balance' => 'sometimes|numeric|min:0',
            'status' => 'sometimes|in:active,delivered,redeemed,expired,scheduled,cancelled,pending,disabled,partially redeemed,partially_redeemed,partially used,voided',
            'expires_at' => 'sometimes|nullable|date',
            'delivery_type' => 'sometimes|nullable|string|max:50',
            'message' => 'sometimes|nullable|string',
            'scheduled_for' => 'sometimes|nullable|date',
            'redeemed_at' => 'sometimes|nullable|date',
            'currency' => 'sometimes|nullable|string|max:10',
        ]);

        if (isset($data['status'])) {
            $data['status'] = $this->normalizeStatus($data['status']);
        }

        if (array_key_exists('current_balance', $data) && ! array_key_exists('redeemed_at', $data)) {
            $data['redeemed_at'] = (float) $data['current_balance'] <= 0 ? Carbon::now() : null;
        }

        if (($data['status'] ?? null) === 'redeemed' && ! array_key_exists('redeemed_at', $data)) {
            $data['redeemed_at'] = Carbon::now();
        }

        $item->update($data);

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

        if (in_array(strtolower($giftCard->status), ['disabled', 'expired', 'fully used', 'redeemed'])) {
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

        $giftCard = EcommerceGiftCard::where('code', $request->code)->first();

        if (!$giftCard) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid gift card code.'
            ], 404);
        }

        if (in_array(strtolower($giftCard->status), ['disabled', 'expired', 'fully used', 'redeemed', 'cancelled'])) {
            return response()->json([
                'success' => false,
                'message' => 'This gift card is not active.'
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

        return response()->json([
            'success' => true,
            'message' => 'Gift card code is valid.',
            'data' => $this->giftCardPayload($giftCard),
        ]);
    }

    /**
     * Redeem a gift card directly into user's wallet balance.
     */
    public function redeemToWallet(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $code = trim($request->code);
        $giftCard = EcommerceGiftCard::where('code', $code)->first();

        if (!$giftCard) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid gift card code.'
            ], 404);
        }

        if (in_array(strtolower($giftCard->status), ['disabled', 'expired', 'fully used', 'redeemed', 'cancelled'])) {
            return response()->json([
                'success' => false,
                'message' => 'This gift card is not active or has already been redeemed.'
            ], 400);
        }

        if ($giftCard->expires_at && $giftCard->expires_at->isPast()) {
            return response()->json([
                'success' => false,
                'message' => 'This gift card has expired.'
            ], 400);
        }

        $balance = (float) $giftCard->current_balance;
        if ($balance <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'This gift card has no remaining balance.'
            ], 400);
        }

        return DB::transaction(function () use ($giftCard, $user, $balance, $request) {
            // Credit user wallet
            $credited = \App\Services\WalletService::adjustWallet(
                $user->id,
                $balance,
                'deposit',
                "Gift Card Redemption (#{$giftCard->code})",
                (string) $giftCard->id
            );

            if (!$credited) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to adjust wallet balance. Please try again.'
                ], 500);
            }

            // Zero out gift card and mark as redeemed
            $giftCard->update([
                'current_balance' => 0,
                'status' => 'redeemed',
                'redeemed_at' => now(),
                'user_id' => $user->id,
                'owner_email' => $user->email,
            ]);

            // Ledger entry
            $giftCard->transactions()->create([
                'transaction_type' => 'Redemption',
                'amount' => -$balance,
                'notes' => "Redeemed to wallet balance by {$user->name} ({$user->email})",
                'created_by' => $user->id,
            ]);

            // Activity Log
            $giftCard->activityLogs()->create([
                'action' => 'Redeemed to Wallet',
                'user_id' => $user->id,
                'old_value' => (string) $balance,
                'new_value' => '0.00',
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Successfully redeemed $' . number_format($balance, 2) . ' into your wallet!',
                'data' => $this->giftCardPayload($giftCard->fresh()),
            ]);
        });
    }

    public function settings()
    {
        $settings = EcommerceGiftCardSetting::query()->first();

        return response()->json([
            'success' => true,
            'data' => $settings?->settings ?? $this->defaultSettings(),
        ]);
    }

    public function saveSettings(Request $request)
    {
        $validated = $request->validate([
            'enable_module' => 'boolean',
            'display_on_store' => 'boolean',
            'prefix' => 'nullable|string|max:20',
            'code_length' => 'nullable|string|max:50',
            'code_format' => 'nullable|string|max:50',
            'min_order_amount' => 'nullable|string|max:50',
            'max_balance' => 'nullable|string|max:50',
            'daily_purchase_limit' => 'nullable|string|max:50',
            'allow_partial_redemption' => 'boolean',
            'expiry_enabled' => 'boolean',
            'expiry_duration' => 'nullable|string|max:50',
            'expiry_type' => 'nullable|string|max:80',
            'send_expiry_reminder' => 'boolean',
            'min_redeem_balance' => 'nullable|string|max:50',
            'max_single_redemption' => 'nullable|string|max:50',
            'total_redemption_limit' => 'nullable|string|max:50',
            'refund_policy' => 'nullable|string|max:80',
            'duplicate_code_check' => 'boolean',
            'email_verification' => 'boolean',
            'auto_deactivate_unused' => 'nullable|string|max:50',
            'types' => 'array',
            'types.*.id' => 'nullable',
            'types.*.name' => 'required_with:types|string|max:120',
            'types.*.description' => 'nullable|string|max:500',
            'types.*.values' => 'nullable|string|max:255',
            'types.*.status' => 'nullable|string|max:40',
            'notification_rules' => 'array',
            'notification_rules.*.id' => 'nullable',
            'notification_rules.*.name' => 'required_with:notification_rules|string|max:120',
            'notification_rules.*.trigger_condition' => 'nullable|string|max:255',
            'notification_rules.*.notify_when' => 'nullable|string|max:120',
            'notification_rules.*.notification_method' => 'nullable|string|max:120',
            'notification_rules.*.status' => 'nullable|string|max:40',
        ]);

        $payload = array_replace_recursive($this->defaultSettings(), $validated);
        $settings = EcommerceGiftCardSetting::query()->first();

        if ($settings) {
            $settings->update(['settings' => $payload]);
        } else {
            $settings = EcommerceGiftCardSetting::create(['settings' => $payload]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Gift card settings saved successfully.',
            'data' => $settings->settings,
        ]);
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
        return [
            'enable_module' => true,
            'display_on_store' => true,
            'prefix' => 'GFT',
            'code_length' => '15 Characters',
            'code_format' => 'Alphanumeric',
            'min_order_amount' => '$10.00',
            'max_balance' => '$500.00',
            'daily_purchase_limit' => '10',
            'allow_partial_redemption' => true,
            'expiry_enabled' => true,
            'expiry_duration' => '12 Months',
            'expiry_type' => 'Date of Issue',
            'send_expiry_reminder' => true,
            'min_redeem_balance' => '$1.00',
            'max_single_redemption' => '$250.00',
            'total_redemption_limit' => 'No Limit',
            'refund_policy' => 'Refund to Gift Card',
            'duplicate_code_check' => true,
            'email_verification' => true,
            'auto_deactivate_unused' => '24 Months',
            'types' => [
                ['id' => 'physical', 'name' => 'Physical Gift Card', 'description' => 'A physical card that will be delivered to the recipient.', 'values' => '$10, $25, $50, $100, $250, $500', 'status' => 'Active'],
                ['id' => 'digital', 'name' => 'E-Gift Card', 'description' => 'Digital gift card sent via email to the recipient.', 'values' => '$10, $25, $50, $100, $250, $500', 'status' => 'Active'],
                ['id' => 'custom', 'name' => 'Custom Gift Card', 'description' => 'Customers can enter any amount within the set range.', 'values' => '$5.00 - $500.00', 'status' => 'Active'],
            ],
            'notification_rules' => [
                ['id' => 'low', 'name' => 'Low Balance Alert', 'trigger_condition' => 'When balance is less than', 'notify_when' => '$10.00', 'notification_method' => 'Email, SMS', 'status' => 'Active'],
                ['id' => 'used', 'name' => 'Balance Used Alert', 'trigger_condition' => 'When gift card is used', 'notify_when' => 'Any amount', 'notification_method' => 'Email', 'status' => 'Active'],
                ['id' => 'expiry', 'name' => 'Balance Expiry Alert', 'trigger_condition' => 'Before card expires', 'notify_when' => '7 Days', 'notification_method' => 'Email, SMS', 'status' => 'Active'],
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
            'internal_notes' => $giftCard->internal_notes,
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

    private function generateUniqueGiftCardCode(?string $prefix = null): string
    {
        if (empty($prefix)) {
            do {
                $code = '';
                for ($i = 0; $i < 15; $i++) {
                    $code .= random_int(0, 9);
                }
            } while (EcommerceGiftCard::where('code', $code)->exists());
            return $code;
        }

        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        do {
            $seg = function() use ($chars) {
                $str = '';
                for ($i = 0; $i < 4; $i++) {
                    $str .= $chars[random_int(0, strlen($chars) - 1)];
                }
                return $str;
            };
            $code = strtoupper($prefix) . '-' . $seg() . '-' . $seg() . '-' . $seg();
        } while (EcommerceGiftCard::where('code', $code)->exists());

        return $code;
    }
}
