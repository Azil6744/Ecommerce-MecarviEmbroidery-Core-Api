<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Charity;
use App\Models\CharityActivityLog;
use App\Models\CharityPayout;
use App\Models\Donation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DonationPayoutController extends Controller
{
    private function getSummaryData()
    {
        $totalAmountPaid = (float) CharityPayout::where('status', 'Paid')->sum('amount');
        $amountProcessing = (float) CharityPayout::where('status', 'Processing')->sum('amount');
        $canceledAmount = (float) CharityPayout::where('status', 'Canceled')->sum('amount');
        
        $paidCount = CharityPayout::where('status', 'Paid')->count();
        $processingCount = CharityPayout::where('status', 'Processing')->count();
        $canceledCount = CharityPayout::where('status', 'Canceled')->count();
        
        $charitiesPaidCount = CharityPayout::where('status', 'Paid')->distinct()->count('charity_name');
        $totalCharitiesCount = Charity::count();
        $totalPayoutsCount = CharityPayout::count();

        // Payment Methods aggregation
        $bankAmount = (float) CharityPayout::where('status', 'Paid')->where('payment_method', 'Bank Transfer')->sum('amount');
        $bankCount = CharityPayout::where('status', 'Paid')->where('payment_method', 'Bank Transfer')->count();

        $checkAmount = (float) CharityPayout::where('status', 'Paid')->where('payment_method', 'Check')->sum('amount');
        $checkCount = CharityPayout::where('status', 'Paid')->where('payment_method', 'Check')->count();

        // Settlement Information
        $nextProcessing = CharityPayout::where('status', 'Processing')
            ->orderBy('created_at', 'asc')
            ->first();
        $nextScheduledPayout = $nextProcessing ? ($nextProcessing->scheduled_date ?? $nextProcessing->date_paid_or_expected ?? 'May 30, 2024') : 'All settled';
        $charitiesToBePaid = CharityPayout::where('status', 'Processing')->distinct()->count('charity_name');

        // Upcoming Payouts
        $upcoming = CharityPayout::where('status', 'Processing')->orderBy('created_at', 'asc')->take(10)->get();

        return [
            'stats' => [
                'total_amount_paid' => $totalAmountPaid,
                'paid_count' => $paidCount,
                'amount_processing' => $amountProcessing,
                'processing_count' => $processingCount,
                'canceled_amount' => $canceledAmount,
                'canceled_count' => $canceledCount,
                'charities_paid' => $charitiesPaidCount,
                'total_charities' => $totalCharitiesCount,
                'total_payouts' => $totalPayoutsCount,
            ],
            'methods' => [
                'bank_amount' => $bankAmount,
                'bank_count' => $bankCount,
                'check_amount' => $checkAmount,
                'check_count' => $checkCount,
            ],
            'settlement' => [
                'next_scheduled_payout' => $nextScheduledPayout,
                'estimated_amount' => $amountProcessing,
                'charities_to_be_paid' => $charitiesToBePaid,
                'payout_monthly_cycle' => 'Monthly',
            ],
            'upcoming' => $upcoming,
        ];
    }

    public function index(Request $request)
    {
        // Auto-link any unlinked payouts
        CharityPayout::whereNull('charity_id')->get()->each(function ($p) {
            $c = Charity::where('name', $p->charity_name)
                ->orWhere('name', 'like', explode(' ', $p->charity_name)[0] . '%')
                ->first();
            if ($c) {
                $p->update(['charity_id' => $c->id]);
            }
        });

        $query = CharityPayout::with('charity');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('payout_id', 'like', "%{$search}%")
                  ->orWhere('charity_name', 'like', "%{$search}%")
                  ->orWhere('reference_or_check', 'like', "%{$search}%")
                  ->orWhere('payment_method', 'like', "%{$search}%")
                  ->orWhere('status', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('created_at', [$request->input('start_date'), $request->input('end_date')]);
        }

        $payouts = $query->orderBy('created_at', 'desc')->get();
        $summary = $this->getSummaryData();

        // Collect donation IDs already included in Paid or Processing payouts
        $paidOutIds = [];
        foreach (CharityPayout::whereIn('status', ['Paid', 'Processing'])->get() as $activePayout) {
            if (is_array($activePayout->selected_donation_ids)) {
                foreach ($activePayout->selected_donation_ids as $did) {
                    $paidOutIds[(string) $did] = true;
                }
            }
        }

        $unpaidDonations = Donation::where('status', 'Completed')
            ->orderBy('created_at', 'desc')
            ->get()
            ->filter(fn ($d) => !isset($paidOutIds[(string) $d->id]));

        $allCharities = Charity::where('status', 'Active')->get()->map(function ($charity) use ($unpaidDonations) {
            $charityUnpaid = $unpaidDonations->filter(function ($d) use ($charity) {
                return strcasecmp(trim((string) $d->charity_name), trim((string) $charity->name)) === 0;
            })->map(function ($d) {
                return [
                    'id' => (string) $d->id,
                    'date' => $d->created_at ? $d->created_at->format('M d, Y') : 'Recent',
                    'donor' => $d->donor_name ?: 'Anonymous',
                    'orderId' => $d->order_id ?: ('ORD-' . $d->id),
                    'amount' => (float) $d->amount,
                ];
            })->values();

            $charity->unpaid_donations = $charityUnpaid;
            return $charity;
        });

        return response()->json(array_merge([
            'success' => true,
            'data' => $payouts,
            'charities' => $allCharities,
        ], $summary));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'charity_name' => 'required|string',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string|in:Bank Transfer,Check',
            'reference_or_check' => 'nullable|string',
            'scheduled_date' => 'nullable|string',
            'notes_to_charity' => 'nullable|string|max:500',
            'admin_notes' => 'nullable|string|max:500',
            'selected_donation_ids' => 'nullable|array',
        ]);

        $charity = Charity::where('name', $validated['charity_name'])
            ->orWhere('name', 'like', explode(' ', $validated['charity_name'])[0] . '%')
            ->first();

        $selectedIds = array_values(array_unique(array_map('strval', $validated['selected_donation_ids'] ?? [])));

        // Verify none of the selected donations are Refunded or already in an active payout
        if (!empty($selectedIds)) {
            $refundedCount = Donation::whereIn('id', $selectedIds)->where('status', 'Refunded')->count();
            if ($refundedCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'One or more selected donations have been refunded/reversed and cannot be paid out.',
                ], 422);
            }

            foreach (CharityPayout::whereIn('status', ['Paid', 'Processing'])->get() as $existingPayout) {
                $existingIds = array_map('strval', is_array($existingPayout->selected_donation_ids) ? $existingPayout->selected_donation_ids : []);
                if (!empty(array_intersect($selectedIds, $existingIds))) {
                    return response()->json([
                        'success' => false,
                        'message' => "One or more selected donations are already included in payout {$existingPayout->payout_id}.",
                    ], 422);
                }
            }
        }

        $count = CharityPayout::count() + 1;
        $payoutId = 'PAY-' . date('Y') . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);

        $payout = CharityPayout::create([
            'payout_id' => $payoutId,
            'charity_id' => $charity ? $charity->id : null,
            'charity_name' => $charity ? $charity->name : $validated['charity_name'],
            'charity_tagline' => $charity ? $charity->tagline : 'Empowering Communities',
            'charity_logo_type' => $charity ? $charity->logo_svg_type : 'generic_charity',
            'amount' => $validated['amount'],
            'payment_method' => $validated['payment_method'],
            'reference_or_check' => $validated['reference_or_check'] ?? $payoutId,
            'status' => 'Processing',
            'scheduled_date' => $validated['scheduled_date'] ?? date('M d, Y'),
            'date_paid_or_expected' => $validated['scheduled_date'] ?? date('M d, Y'),
            'notes_to_charity' => $validated['notes_to_charity'] ?? null,
            'admin_notes' => $validated['admin_notes'] ?? null,
            'selected_donation_ids' => $selectedIds,
        ]);

        if ($charity) {
            $admin = auth('sanctum')->user() ?? auth()->user() ?? $request->user();
            $staffName = $admin ? ($admin->name ?? $admin->email) : 'Administrator';
            $staffRole = $admin && !empty($admin->role) ? ucfirst($admin->role) : 'Administrator';

            CharityActivityLog::create([
                'charity_id' => $charity->id,
                'charity_name' => $charity->name,
                'action_type' => 'payout_created',
                'title' => "Payout {$payout->payout_id} Scheduled ($" . number_format($payout->amount, 2) . ")",
                'description' => "Payout of $" . number_format($payout->amount, 2) . " scheduled via {$payout->payment_method} (Ref: {$payout->reference_or_check}).",
                'staff_id' => $admin ? $admin->id : null,
                'staff_name' => $staffName,
                'staff_role' => $staffRole,
                'metadata' => [
                    'payout_id' => $payout->payout_id,
                    'amount' => (float) $payout->amount,
                    'payment_method' => $payout->payment_method,
                    'reference' => $payout->reference_or_check,
                    'selected_donation_ids' => $selectedIds,
                ],
            ]);
        }

        $payout->load('charity');

        return response()->json(array_merge([
            'success' => true,
            'message' => 'Payout created successfully.',
            'data' => $payout,
        ], $this->getSummaryData()));
    }

    public function cancel(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => 'required|string',
            'notes' => 'nullable|string|max:500',
        ]);

        $payout = CharityPayout::where('id', $id)->orWhere('payout_id', $id)->firstOrFail();

        $payout->update([
            'status' => 'Canceled',
            'cancellation_reason' => $validated['reason'],
            'cancellation_notes' => $validated['notes'] ?? null,
        ]);

        // Record reversal in charity activity log
        $charity = $payout->charity_id ? Charity::find($payout->charity_id) : Charity::where('name', $payout->charity_name)->first();
        if ($charity) {
            $admin = auth('sanctum')->user() ?? auth()->user();
            $staffName = $admin ? ($admin->name ?? $admin->email) : 'Admin (Finance Supervisor)';
            $staffRole = $admin && !empty($admin->role) ? ucfirst($admin->role) : 'Administrator';

            CharityActivityLog::create([
                'charity_id' => $charity->id,
                'charity_name' => $charity->name,
                'action_type' => 'payout_reversed',
                'title' => "Payout {$payout->payout_id} Reversed / Canceled",
                'description' => "Payout of $" . number_format($payout->amount, 2) . " was canceled/reversed. Reason: " . $validated['reason'],
                'staff_id' => $admin ? $admin->id : null,
                'staff_name' => $staffName,
                'staff_role' => $staffRole,
                'metadata' => [
                    'payout_id' => $payout->payout_id,
                    'amount' => (float) $payout->amount,
                    'payment_method' => $payout->payment_method,
                    'reason' => $validated['reason'],
                    'notes' => $validated['notes'] ?? null,
                ]
            ]);
        }

        $payout->load('charity');

        return response()->json(array_merge([
            'success' => true,
            'message' => 'Payout canceled successfully.',
            'data' => $payout,
        ], $this->getSummaryData()));
    }

    public function complete(Request $request, $id)
    {
        $payout = CharityPayout::where('id', $id)->orWhere('payout_id', $id)->firstOrFail();

        $payout->update([
            'status' => 'Paid',
            'completed_at' => now(),
            'date_paid_or_expected' => now()->format('M d, Y h:i A'),
        ]);

        // Record completion in charity activity log
        $charity = $payout->charity_id ? Charity::find($payout->charity_id) : Charity::where('name', $payout->charity_name)->first();
        if ($charity) {
            $admin = auth('sanctum')->user() ?? auth()->user();
            $staffName = $admin ? ($admin->name ?? $admin->email) : 'Admin (Operations)';
            $staffRole = $admin && !empty($admin->role) ? ucfirst($admin->role) : 'Administrator';

            CharityActivityLog::create([
                'charity_id' => $charity->id,
                'charity_name' => $charity->name,
                'action_type' => 'payout_completed',
                'title' => "Payout {$payout->payout_id} Completed",
                'description' => "Payout of $" . number_format($payout->amount, 2) . " marked as Paid via " . $payout->payment_method . ".",
                'staff_id' => $admin ? $admin->id : null,
                'staff_name' => $staffName,
                'staff_role' => $staffRole,
                'metadata' => [
                    'payout_id' => $payout->payout_id,
                    'amount' => (float) $payout->amount,
                    'payment_method' => $payout->payment_method,
                    'reference' => $payout->reference_or_check,
                ]
            ]);
        }

        $payout->load('charity');

        return response()->json(array_merge([
            'success' => true,
            'message' => 'Payout marked as complete.',
            'data' => $payout,
        ], $this->getSummaryData()));
    }
}
