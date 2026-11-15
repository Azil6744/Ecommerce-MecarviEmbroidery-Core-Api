<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MembershipCardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class MembershipCardController extends Controller
{
    public function __construct(private MembershipCardService $cards)
    {
    }

    /** What the membership signup form needs to take a card (Stripe keys + SetupIntent, or test mode). */
    public function setup(Request $request)
    {
        try {
            return response()->json(['success' => true, 'data' => $this->cards->setup($request->user())]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first() ?: 'Card payments are unavailable right now.',
            ], 422);
        }
    }

    /**
     * Called by Central's billing job when a card membership is due (trial ended or renewal).
     * Always answers 200 with `paid` true/false so the job can renew or start the plan's
     * failed-payment handling; other statuses mean the request itself was wrong.
     */
    public function chargeForRenewal(Request $request)
    {
        $secret = (string) config('services.internal_notifications.secret');
        if ($secret === '' || ! hash_equals($secret, (string) $request->header('X-Internal-Notification-Secret'))) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate([
            'membership_id' => 'required',
            'email' => 'required|email',
            'amount' => 'required|numeric|min:0.5',
            'currency' => 'nullable|string|size:3',
            'description' => 'nullable|string|max:255',
            'idempotency_key' => 'required|string|max:200',
            'payment_card' => 'nullable|array',
        ]);

        $user = User::whereRaw('LOWER(email) = ?', [strtolower($data['email'])])->first();
        $card = $this->cards->resolveForRenewal($user, (array) ($data['payment_card'] ?? []));
        if (! $card) {
            return response()->json(['success' => true, 'paid' => false, 'reason' => 'No card on file.']);
        }

        try {
            $result = $this->cards->charge(
                $card,
                (float) $data['amount'],
                (string) ($data['currency'] ?? 'USD'),
                (string) ($data['description'] ?? 'Membership renewal'),
                ['purpose' => 'membership_renewal', 'central_membership_id' => $data['membership_id'], 'user_id' => $user?->id],
                $data['idempotency_key']
            );
        } catch (\Throwable $e) {
            Log::warning('Membership renewal card charge failed: ' . $e->getMessage());

            return response()->json(['success' => true, 'paid' => false, 'reason' => 'The card could not be charged right now.']);
        }

        return response()->json([
            'success' => true,
            'paid' => $result['paid'],
            'reference' => $result['reference'],
            'reason' => $result['reason'],
            'processor' => $result['processor'],
            'card' => ['brand' => $card['brand'], 'last4' => $card['last4']],
        ]);
    }
}
