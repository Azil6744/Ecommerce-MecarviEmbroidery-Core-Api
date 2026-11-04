<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Charity;
use App\Models\CharityActivityLog;
use App\Models\CharityPayout;
use App\Models\Donation;
use App\Models\EcommerceOrder;
use App\Models\EcommerceOrderTransaction;
use App\Models\EcommerceWalletTransaction;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class DonationController extends Controller
{
    public function index()
    {
        // Preload orders and users by order_number and email so we can attach real donor phone & address
        $rawDonations = Donation::orderBy('created_at', 'desc')->get();
        $orderNumbers = $rawDonations->pluck('order_id')->filter()->unique()->values()->all();
        $donorEmails = $rawDonations->pluck('donor_email')->filter()->unique()->values()->all();

        $ordersByNumber = !empty($orderNumbers)
            ? EcommerceOrder::whereIn('order_number', $orderNumbers)->get()->keyBy('order_number')
            : collect();
        $usersByEmail = !empty($donorEmails)
            ? User::whereIn('email', $donorEmails)->get()->keyBy(fn ($u) => strtolower($u->email))
            : collect();

        // Collect donation IDs already included in active (Paid or Processing) payouts
        $paidOutDonationIds = [];
        $activePayouts = CharityPayout::whereIn('status', ['Paid', 'Processing'])->get();
        foreach ($activePayouts as $p) {
            if (is_array($p->selected_donation_ids)) {
                foreach ($p->selected_donation_ids as $did) {
                    $paidOutDonationIds[(string) $did] = $p->payout_id;
                }
            }
        }

        // Fetch wallet refund references for refunded donations to check if wallet was credited
        $refundedDonations = $rawDonations->where('status', 'Refunded');
        $expectedRefs = [];
        foreach ($refundedDonations as $rd) {
            $expectedRefs[] = 'REF-DON-' . $rd->id;
            $expectedRefs[] = 'DON-REV-' . $rd->id;
        }
        $existingWalletRefunds = !empty($expectedRefs)
            ? EcommerceWalletTransaction::whereIn('reference_id', $expectedRefs)
                ->pluck('reference_id')
                ->map(fn($r) => (string)$r)
                ->flip()
                ->all()
            : [];

        $donations = $rawDonations->map(function ($d) use ($ordersByNumber, $usersByEmail, $paidOutDonationIds, $existingWalletRefunds) {
            if ($d->status === 'Refunded') {
                if (!$d->reversed_by) {
                    $d->reversed_by = 'Administrator';
                }
                if (!$d->reversed_at) {
                    $d->reversed_at = $d->updated_at ?? $d->created_at;
                }
                if (!$d->refund_reason) {
                    if (!empty($d->note) && str_contains($d->note, 'Refund Reason:')) {
                        $parts = explode('Refund Reason:', $d->note);
                        $d->refund_reason = trim(end($parts));
                    } elseif (!empty($d->note) && str_contains($d->note, 'Reason:')) {
                        $parts = explode('Reason:', $d->note);
                        $d->refund_reason = trim(end($parts));
                    } else {
                        $d->refund_reason = 'Administrative Refund';
                    }
                }
                $d->wallet_credited = isset($existingWalletRefunds['REF-DON-' . $d->id]) || isset($existingWalletRefunds['DON-REV-' . $d->id]);
            } else {
                $d->wallet_credited = null;
            }

            $order = $d->order_id ? ($ordersByNumber[$d->order_id] ?? null) : null;
            $user = $d->donor_email ? ($usersByEmail[strtolower($d->donor_email)] ?? null) : null;

            $donorPhone = null;
            $donorAddress = null;

            if ($order) {
                $addr = is_array($order->shipping_address)
                    ? $order->shipping_address
                    : (is_array($order->billing_address) ? $order->billing_address : []);
                $donorPhone = $addr['phone'] ?? ($order->metadata['customer_phone'] ?? null);
                $addressParts = array_filter([
                    $addr['address_line_1'] ?? ($addr['address'] ?? ($addr['street'] ?? null)),
                    $addr['address_line_2'] ?? null,
                    $addr['city'] ?? null,
                    $addr['state'] ?? null,
                    $addr['postal_code'] ?? ($addr['zip'] ?? null),
                    $addr['country'] ?? null,
                ]);
                if (!empty($addressParts)) {
                    $donorAddress = implode(', ', $addressParts);
                }
            }

            if (!$donorPhone && $user && !empty($user->phone)) {
                $donorPhone = $user->phone;
            }
            if (!$donorAddress && $user && !empty($user->address)) {
                $donorAddress = $user->address;
            }

            $d->donor_phone = $donorPhone;
            $d->donor_address = $donorAddress;
            $d->is_paid_out = isset($paidOutDonationIds[(string) $d->id]);
            $d->payout_id = $paidOutDonationIds[(string) $d->id] ?? null;

            return $d;
        });

        // Calculate dynamic stats
        $dbCompletedAmount = (float) Donation::where('status', 'Completed')->sum('amount');
        $dbPendingAmount = (float) Donation::where('status', 'Pending')->sum('amount');
        
        $totalDonationsCount = Donation::count();
        $totalAmount = $dbCompletedAmount + $dbPendingAmount;
        $pendingPayouts = $dbPendingAmount;
        $completedPayouts = $dbCompletedAmount;
        $charitiesSupported = Donation::whereIn('status', ['Completed', 'Pending'])->distinct('charity_name')->count('charity_name');

        // Calculate Top Charities dynamically (Completed or Pending donations)
        $topCharities = Donation::whereIn('status', ['Completed', 'Pending'])
            ->select('charity_name as name', 'charity_logo_type as logoType', DB::raw('SUM(amount) as amount'), DB::raw('COUNT(id) as donation_count'))
            ->groupBy('charity_name', 'charity_logo_type')
            ->orderBy('amount', 'desc')
            ->get();

        // Calculate Payment Methods Breakdown
        $methodsBreakdown = Donation::whereIn('status', ['Completed', 'Pending'])
            ->select('payment_method_brand as brand', DB::raw('SUM(amount) as amount'), DB::raw('COUNT(id) as count'))
            ->groupBy('payment_method_brand')
            ->get();

        // Calculate Recent Activity Feed dynamically
        $recentDonations = Donation::orderBy('created_at', 'desc')->take(10)->get();
        $activities = [];

        foreach ($recentDonations as $don) {
            $activities[] = [
                'id' => 'don_' . $don->id,
                'type' => $don->status === 'Refunded' ? 'donation_reversed' : 'donation_received',
                'text' => '$' . number_format($don->amount, 2) . ($don->status === 'Refunded' ? ' refunded to ' : ' from ') . $don->donor_name,
                'time' => $don->created_at ? $don->created_at->format('M d, Y • h:i A') : '',
                'variant' => $don->status === 'Failed' || $don->status === 'Refunded' ? 'warning' : 'received',
            ];
        }

        // Also return all active charities with their real bank/contact profile so CreatePayoutModal on Donations page uses real charity data
        $charities = Charity::where('status', 'Active')->orderBy('name', 'asc')->get();

        return response()->json([
            'success' => true,
            'data' => $donations,
            'charities' => $charities,
            'stats' => [
                'total_donations' => $totalDonationsCount,
                'total_amount' => $totalAmount,
                'pending_payouts' => $pendingPayouts,
                'completed_payouts' => $completedPayouts,
                'charities_supported' => $charitiesSupported
            ],
            'top_charities' => $topCharities,
            'methods' => $methodsBreakdown,
            'recent_activity' => $activities
        ]);
    }

    public function reverse(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => 'required|string',
            'notes' => 'nullable|string|max:500',
            'amount' => 'nullable|numeric|min:0.01',
            'staff_name' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request, $id, $validated) {
            $donation = Donation::lockForUpdate()->findOrFail($id);

            // Locate donor user
            $user = null;
            if (!empty($donation->user_id)) {
                $user = User::find($donation->user_id);
            }
            $order = null;
            if (!empty($donation->order_id)) {
                $order = EcommerceOrder::where('order_number', $donation->order_id)->first();
                if (!$user && $order) {
                    if (!empty($order->user_id)) {
                        $user = User::find($order->user_id);
                    }
                    if (!$user && !empty($order->customer_email)) {
                        $user = User::whereRaw('LOWER(email) = ?', [strtolower(trim($order->customer_email))])->first();
                    }
                }
            }
            if (!$user && !empty($donation->donor_email)) {
                $user = User::whereRaw('LOWER(email) = ?', [strtolower(trim($donation->donor_email))])->first();
            }

            // If user not in local DB, create record so wallet can be credited & synced
            $donorEmail = strtolower(trim((string)($donation->donor_email ?: ($order->customer_email ?? ''))));
            if (!$user && !empty($donorEmail)) {
                $user = User::create([
                    'name' => $donation->donor_name ?: ($order->customer_name ?? 'Customer'),
                    'email' => $donorEmail,
                    'password' => bcrypt(\Illuminate\Support\Str::random(24)),
                    'role' => 'customer',
                    'wallet_balance' => 0.00,
                ]);
            }

            if ($user && empty($donation->user_id)) {
                $donation->user_id = $user->id;
                $donation->save();
            }

            $admin = auth('sanctum')->user() ?? auth()->user() ?? $request->user();
            $staffName = $admin ? ($admin->name ?? $admin->email) : ($request->input('staff_name') ?: 'Administrator');
            $staffRole = $admin && !empty($admin->role) ? ucfirst($admin->role) : 'Administrator';
            $staffId = $admin ? $admin->id : null;

            $reversedAt = now();
            $reasonText = trim($validated['reason'] . (!empty($validated['notes']) ? " - " . $validated['notes'] : ""));

            // If donation is ALREADY marked as Refunded, check if wallet credit was missing and credit it now
            if ($donation->status === 'Refunded') {
                $walletTxExists = false;
                if ($user) {
                    $walletTxExists = EcommerceWalletTransaction::where('user_id', $user->id)
                        ->where(function ($q) use ($donation) {
                            $q->where('reference_id', 'REF-DON-' . $donation->id)
                              ->orWhere('reference_id', 'DON-REV-' . $donation->id)
                              ->orWhere('reference_id', 'like', 'REF-DON-' . $donation->id . '-%');
                        })
                        ->exists();
                }

                if ($walletTxExists) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This donation has already been refunded and credited to the user wallet.',
                    ], 422);
                }

                $reversalAmount = (float) $donation->amount;
                $refId = 'REF-DON-' . $donation->id . '-' . time();
                $desc = "Refund for donation to {$donation->charity_name}" . ($donation->order_id ? " (Order #{$donation->order_id})" : "");

                $walletCredited = false;
                if ($user && $reversalAmount > 0) {
                    $walletCredited = WalletService::adjustWallet(
                        $user->id,
                        $reversalAmount,
                        'refund',
                        $desc,
                        $refId
                    );
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Donation refund wallet credit has now been successfully applied to the donor\'s account.',
                    'data' => $donation,
                    'is_partial' => false,
                    'reversed_amount' => $reversalAmount,
                    'wallet_credited' => $walletCredited,
                ]);
            }

            $originalAmount = (float) $donation->amount;
            $requestedAmount = isset($validated['amount']) ? (float) $validated['amount'] : $originalAmount;
            $reversalAmount = round(min($originalAmount, max(0.01, $requestedAmount)), 2);
            $isPartial = ($originalAmount - $reversalAmount) >= 0.01;

            if ($isPartial) {
                $remainingAmount = round($originalAmount - $reversalAmount, 2);
                $donation->update([
                    'amount' => $remainingAmount,
                    'note' => ($donation->note ? $donation->note . " | " : "") . "Partial refund of $" . number_format($reversalAmount, 2) . " by {$staffName} on {$reversedAt->format('M d, Y h:i A')}. Reason: {$reasonText}",
                ]);

                // Create a dedicated Refunded record for the reversed portion so audit logs & reversal history remain exact
                $refundedRecord = Donation::create([
                    'user_id' => $user?->id,
                    'order_id' => $donation->order_id,
                    'txn_id' => $donation->txn_id ? ($donation->txn_id . '-REV') : ('TXN-REV-' . $donation->id),
                    'donor_name' => $donation->donor_name,
                    'donor_email' => $donation->donor_email,
                    'charity_name' => $donation->charity_name,
                    'charity_category' => $donation->charity_category,
                    'charity_logo_type' => $donation->charity_logo_type,
                    'amount' => $reversalAmount,
                    'payment_method_brand' => $donation->payment_method_brand,
                    'payment_method_details' => $donation->payment_method_details,
                    'payment_method_email' => $donation->payment_method_email,
                    'status' => 'Refunded',
                    'reversed_by' => $staffName,
                    'reversed_at' => $reversedAt,
                    'refund_reason' => "Partial Reversal ($" . number_format($reversalAmount, 2) . " of $" . number_format($originalAmount, 2) . "): " . $reasonText,
                    'note' => "Partial reversal from Donation #{$donation->id} by {$staffName} on {$reversedAt->format('M d, Y h:i A')}. Reason: " . $reasonText,
                ]);
                $targetDonationForResponse = $refundedRecord;
            } else {
                $donation->update([
                    'status' => 'Refunded',
                    'reversed_by' => $staffName,
                    'reversed_at' => $reversedAt,
                    'refund_reason' => $reasonText,
                    'note' => ($donation->note ? $donation->note . " | " : "") . "Refunded by {$staffName} on {$reversedAt->format('M d, Y h:i A')}. Reason: " . $reasonText,
                ]);
                $targetDonationForResponse = $donation;
            }

            // Sync split order transaction ledger (`ecommerce_order_transactions`) & `EcommerceOrder`
            if (!empty($donation->order_id)) {
                $order = $order ?? EcommerceOrder::where('order_number', $donation->order_id)->first();
                if ($order) {
                    $newOrderDonationAmount = max(0, round(((float) $order->donation_amount) - $reversalAmount, 2));
                    $meta = is_array($order->metadata) ? $order->metadata : [];
                    $meta['donation_reversed_amount'] = round(((float) ($meta['donation_reversed_amount'] ?? 0)) + $reversalAmount, 2);
                    $meta['last_donation_reversal'] = [
                        'donation_id' => $donation->id,
                        'charity_name' => $donation->charity_name,
                        'amount' => $reversalAmount,
                        'is_partial' => $isPartial,
                        'reversed_by' => $staffName,
                        'reversed_at' => $reversedAt->toIso8601String(),
                        'reason' => $reasonText,
                    ];
                    $order->donation_amount = $newOrderDonationAmount;
                    $order->metadata = $meta;
                    $order->save();
                }

                $ledgerQuery = EcommerceOrderTransaction::where('order_number', $donation->order_id)
                    ->where('type', 'donation')
                    ->where('status', '!=', 'refunded');

                $ledgerTxns = $ledgerQuery->get();
                $matchedLedger = $ledgerTxns->first(function ($tx) use ($donation) {
                    $txCharity = $tx->charity_name ?? ($tx->meta['charity_name'] ?? '');
                    return strcasecmp((string)$txCharity, (string)$donation->charity_name) === 0
                        || stripos((string) ($tx->label ?? ''), (string) $donation->charity_name) !== false;
                }) ?? $ledgerTxns->first();

                if ($matchedLedger) {
                    $txMeta = is_array($matchedLedger->meta) ? $matchedLedger->meta : [];
                    $txMeta['reversed_by'] = $staffName;
                    $txMeta['reversed_at'] = $reversedAt->toIso8601String();
                    $txMeta['refund_reason'] = $reasonText;

                    if ($isPartial && (float) $matchedLedger->amount > $reversalAmount) {
                        $matchedLedger->refunded_amount = round(((float) ($matchedLedger->refunded_amount ?? 0)) + $reversalAmount, 2);
                        $matchedLedger->status = 'partially_refunded';
                    } else {
                        $matchedLedger->refunded_amount = (float) $matchedLedger->amount;
                        $matchedLedger->status = 'refunded';
                    }
                    $matchedLedger->meta = $txMeta;
                    $matchedLedger->save();
                }
            }

            // Credit the donor's wallet
            $walletCredited = false;
            if ($user && $reversalAmount > 0) {
                $refId = 'REF-DON-' . $targetDonationForResponse->id . ($isPartial ? ('-P' . time()) : '');
                $desc = "Refund for donation to {$donation->charity_name}" . ($donation->order_id ? " (Order #{$donation->order_id})" : "");
                try {
                    $walletCredited = WalletService::adjustWallet(
                        $user->id,
                        $reversalAmount,
                        'refund',
                        $desc,
                        $refId
                    );
                } catch (\Throwable $e) {
                    Log::error("Failed to credit wallet for donation reversal #{$donation->id}: " . $e->getMessage());
                }
            }

            // Record in CharityActivityLog so Charity History modal & drawer show the exact reversal audit
            $charity = Charity::where('name', $donation->charity_name)->first();
            CharityActivityLog::create([
                'charity_id' => $charity ? $charity->id : null,
                'charity_name' => $donation->charity_name,
                'action_type' => 'donation_reversed',
                'title' => ($isPartial ? "Partial Donation Reversed" : "Donation Refunded / Reversed") . " ($" . number_format($reversalAmount, 2) . ")",
                'description' => "Donation from {$donation->donor_name} (Order {$donation->order_id}) reversed by {$staffName}. Reason: {$reasonText}",
                'staff_id' => $staffId,
                'staff_name' => $staffName,
                'staff_role' => $staffRole,
                'metadata' => [
                    'donation_id' => $targetDonationForResponse->id,
                    'original_donation_id' => $donation->id,
                    'order_id' => $donation->order_id,
                    'donor' => $donation->donor_name,
                    'amount' => $reversalAmount,
                    'original_amount' => $originalAmount,
                    'is_partial' => $isPartial,
                    'status' => 'Refunded',
                    'reason' => $reasonText,
                    'wallet_credited' => $walletCredited,
                ],
            ]);

            return response()->json([
                'success' => true,
                'message' => $isPartial
                    ? 'Partial donation reversal ($' . number_format($reversalAmount, 2) . ') processed and credited to user wallet.'
                    : 'Donation has been successfully reversed and refunded to user wallet.',
                'data' => $targetDonationForResponse,
                'updated_original' => $isPartial ? $donation : null,
                'is_partial' => $isPartial,
                'reversed_amount' => $reversalAmount,
                'wallet_credited' => $walletCredited,
            ]);
        });
    }

    public function sendReceipt(Request $request, $id)
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $donation = Donation::findOrFail($id);
        $recipientEmail = $validated['email'];
        $dateFormatted = $donation->created_at ? $donation->created_at->format('M d, Y h:i A') : now()->format('M d, Y h:i A');
        $amountFormatted = number_format((float) $donation->amount, 2);
        $referenceId = 'REF-' . $donation->id;

        $html = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 16px; overflow: hidden;'>
                <div style='background: linear-gradient(90deg, #ff007f, #e60076); color: #ffffff; padding: 24px;'>
                    <h2 style='margin: 0; font-size: 22px;'>Official Donation Receipt</h2>
                    <p style='margin: 4px 0 0; font-size: 13px; opacity: 0.9;'>Thank you for your generous support via Mecarvi Embroidery</p>
                </div>
                <div style='padding: 24px; color: #1e293b;'>
                    <p style='font-size: 14px;'>Dear <strong>" . e($donation->donor_name ?: 'Valued Donor') . "</strong>,</p>
                    <p style='font-size: 14px; line-height: 1.5;'>This receipt confirms that your charitable contribution has been received and processed. Please retain this receipt for your records.</p>
                    <table style='width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 13px;'>
                        <tr style='border-bottom: 1px solid #f1f5f9;'><td style='padding: 8px 0; color: #64748b;'>Charity / Foundation</td><td style='padding: 8px 0; text-align: right; font-weight: bold;'>" . e($donation->charity_name) . "</td></tr>
                        <tr style='border-bottom: 1px solid #f1f5f9;'><td style='padding: 8px 0; color: #64748b;'>Donation Amount</td><td style='padding: 8px 0; text-align: right; font-weight: bold; color: #059669; font-size: 16px;'>$" . $amountFormatted . "</td></tr>
                        <tr style='border-bottom: 1px solid #f1f5f9;'><td style='padding: 8px 0; color: #64748b;'>Order ID</td><td style='padding: 8px 0; text-align: right; font-family: monospace;'>" . e($donation->order_id ?: ('ORD-' . $donation->id)) . "</td></tr>
                        <tr style='border-bottom: 1px solid #f1f5f9;'><td style='padding: 8px 0; color: #64748b;'>Transaction ID</td><td style='padding: 8px 0; text-align: right; font-family: monospace;'>" . e($donation->txn_id ?: ('TXN-' . $donation->id)) . "</td></tr>
                        <tr style='border-bottom: 1px solid #f1f5f9;'><td style='padding: 8px 0; color: #64748b;'>Reference</td><td style='padding: 8px 0; text-align: right; font-family: monospace;'>" . e($referenceId) . "</td></tr>
                        <tr style='border-bottom: 1px solid #f1f5f9;'><td style='padding: 8px 0; color: #64748b;'>Payment Method</td><td style='padding: 8px 0; text-align: right;'>" . e(($donation->payment_method_brand ?: 'Card') . ($donation->payment_method_details ? ' (' . $donation->payment_method_details . ')' : '')) . "</td></tr>
                        <tr style='border-bottom: 1px solid #f1f5f9;'><td style='padding: 8px 0; color: #64748b;'>Date &amp; Time</td><td style='padding: 8px 0; text-align: right;'>" . e($dateFormatted) . "</td></tr>
                        <tr><td style='padding: 8px 0; color: #64748b;'>Status</td><td style='padding: 8px 0; text-align: right; font-weight: bold;'>" . e($donation->status ?: 'Completed') . "</td></tr>
                    </table>
                    <p style='font-size: 12px; color: #64748b; margin-top: 24px;'>Mecarvi Embroidery • www.mecarviembroidery.com • info@mecarviembroidery.com</p>
                </div>
            </div>
        ";

        $mailDispatched = false;
        try {
            Mail::html($html, function ($message) use ($recipientEmail, $donation) {
                $message->to($recipientEmail)
                    ->subject('Official Donation Receipt - ' . ($donation->charity_name ?: 'Mecarvi Embroidery') . ' (' . ($donation->order_id ?: ('DON-' . $donation->id)) . ')');
            });
            $mailDispatched = true;
        } catch (\Throwable $e) {
            Log::warning('Failed to send donation receipt email to ' . $recipientEmail . ': ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'mail_sent' => $mailDispatched,
            'message' => 'Donation receipt dispatched successfully to ' . $recipientEmail,
            'data' => $donation,
        ]);
    }
}
