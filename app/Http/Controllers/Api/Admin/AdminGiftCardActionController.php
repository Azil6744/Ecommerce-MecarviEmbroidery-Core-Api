<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EcommerceGiftCard;
use App\Models\EcommerceGiftCardNote;
use App\Models\User;
use App\Services\AdminGiftCardActionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin gift card decisions (routes sit behind the admin.only middleware):
 * decline a purchase, void or reverse a card, and the card's full history.
 */
class AdminGiftCardActionController extends Controller
{
    private const ACTION_RULES = [
        'reason' => 'required|string|max:255',
        'description' => 'required|string|max:500',
        'internal_note' => 'nullable|string|max:500',
    ];

    public function decline(Request $request, int $id): JsonResponse
    {
        $data = $this->clean($request->validate(self::ACTION_RULES));

        return $this->run(function () use ($request, $id, $data) {
            $result = AdminGiftCardActionService::declineOrder($id, $request->user(), $data, $request->ip());
            $message = match ($result['refund']) {
                'wallet' => 'Gift card order declined and the payment refunded to the buyer’s wallet.',
                'stripe' => 'Gift card order declined and the card payment refunded through Stripe.',
                'paypal' => 'Gift card order declined and the payment refunded through PayPal.',
                'none' => 'Gift card order declined. No payment had been collected.',
                'manual' => 'Gift card order declined. This older order has no saved payment ID, so it is marked "refund pending": refund it in Stripe or PayPal.',
                default => 'Gift card order declined and the test payment refunded.',
            };

            return [$message, $result['order']];
        });
    }

    /** One action per request: action_type is either void or reverse. */
    public function voidOrReverse(Request $request, int $id): JsonResponse
    {
        $data = $this->clean($request->validate(self::ACTION_RULES + [
            'action_type' => 'required|in:void,reverse',
            'transaction_id' => 'required_if:action_type,reverse|nullable|integer',
        ]));

        return $this->run(function () use ($request, $id, $data) {
            if ($data['action_type'] === 'reverse') {
                $card = AdminGiftCardActionService::reverse($id, (int) $data['transaction_id'], $request->user(), $data, $request->ip());

                return ['Transaction reversed and the balance corrected.', $this->history($card)];
            }

            $card = AdminGiftCardActionService::void($id, $request->user(), $data, $request->ip());

            return ['Gift card voided and its remaining balance cancelled.', $this->history($card)];
        });
    }

    public function show(int $id): JsonResponse
    {
        return $this->run(fn () => [null, $this->history(EcommerceGiftCard::findOrFail($id))]);
    }

    /** Ledger, audit log (with the admin responsible) and internal notes for one card. */
    private function history(EcommerceGiftCard $card): array
    {
        $txs = $card->transactions()->latest('id')->get();
        $logs = $card->activityLogs()->latest('id')->get();
        $userIds = $txs->pluck('created_by')->merge($txs->pluck('reversed_by'))->merge($logs->pluck('admin_id'))->merge($logs->pluck('user_id'))->filter()->unique();
        $names = User::whereIn('id', $userIds)->pluck('name', 'id');

        return [
            'id' => $card->id,
            'code' => $card->code,
            'status' => $card->status,
            'initial_balance' => (float) $card->initial_balance,
            'current_balance' => (float) $card->current_balance,
            'transactions' => $txs->map(fn ($tx) => [
                'id' => $tx->id,
                'transaction_type' => $tx->transaction_type,
                'amount' => (float) $tx->amount,
                'order_id' => $tx->order_id,
                'notes' => $tx->notes,
                'created_by' => $names[$tx->created_by] ?? null,
                'created_at' => optional($tx->created_at)->toIso8601String(),
                'reversed_at' => optional($tx->reversed_at)->toIso8601String(),
                'reversed_by' => $names[$tx->reversed_by] ?? null,
                'reversal_of_id' => $tx->reversal_of_id,
                'reversible' => ! $tx->reversed_at && ! $tx->reversal_of_id && in_array($tx->transaction_type, ['Redemption', 'Manual Adjustment', 'Issue'], true),
            ])->values(),
            'activity' => $logs->map(fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'by' => $names[$log->admin_id] ?? $names[$log->user_id] ?? ($log->admin_id || $log->user_id ? 'User #'.($log->admin_id ?: $log->user_id) : 'System'),
                'old_value' => $log->old_value,
                'new_value' => $log->new_value,
                'meta' => $log->meta,
                'created_at' => optional($log->created_at)->toIso8601String(),
            ])->values(),
            'notes' => EcommerceGiftCardNote::where('giftcard_id', $card->id)->latest('id')->get()->map(fn ($n) => [
                'id' => $n->id,
                'author_name' => $n->author_name,
                'note' => $n->note,
                'created_at' => optional($n->created_at)->toIso8601String(),
            ])->values(),
        ];
    }

    private function clean(array $data): array
    {
        return array_map(fn ($v) => is_string($v) ? trim($v) : $v, $data);
    }

    private function run(callable $fn): JsonResponse
    {
        try {
            [$message, $data] = $fn();

            return response()->json(array_filter(['success' => true, 'message' => $message, 'data' => $data], fn ($v) => $v !== null));
        } catch (ModelNotFoundException) {
            return response()->json(['success' => false, 'message' => 'Gift card not found.'], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
