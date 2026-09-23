<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Charity;
use App\Models\CharityPayout;
use App\Models\Donation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DonationPayoutController extends Controller
{
    public function index(Request $request)
    {
        $payouts = CharityPayout::orderBy('created_at', 'desc')->get();

        // Calculate dynamic stats
        $totalAmountPaid = CharityPayout::where('status', 'Paid')->sum('amount');
        $amountProcessing = CharityPayout::where('status', 'Processing')->sum('amount');
        $canceledAmount = CharityPayout::where('status', 'Canceled')->sum('amount');
        
        $paidCount = CharityPayout::where('status', 'Paid')->count();
        $processingCount = CharityPayout::where('status', 'Processing')->count();
        $canceledCount = CharityPayout::where('status', 'Canceled')->count();
        
        $charitiesPaidCount = CharityPayout::where('status', 'Paid')->distinct('charity_name')->count();
        $totalCharitiesCount = Charity::count();
        $totalPayoutsCount = CharityPayout::count();

        // Payment Methods aggregation
        $bankAmount = CharityPayout::where('status', 'Paid')->where('payment_method', 'Bank Transfer')->sum('amount');
        $bankCount = CharityPayout::where('status', 'Paid')->where('payment_method', 'Bank Transfer')->count();

        $checkAmount = CharityPayout::where('status', 'Paid')->where('payment_method', 'Check')->sum('amount');
        $checkCount = CharityPayout::where('status', 'Paid')->where('payment_method', 'Check')->count();

        // Upcoming Payouts
        $upcoming = CharityPayout::where('status', 'Processing')->orderBy('created_at', 'asc')->take(5)->get();

        return response()->json([
            'success' => true,
            'data' => $payouts,
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
            'upcoming' => $upcoming,
        ]);
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

        $charity = Charity::where('name', $validated['charity_name'])->first();

        $count = CharityPayout::count() + 1;
        $payoutId = 'PAY-' . date('Y') . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);

        $payout = CharityPayout::create([
            'payout_id' => $payoutId,
            'charity_id' => $charity ? $charity->id : null,
            'charity_name' => $validated['charity_name'],
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
            'selected_donation_ids' => $validated['selected_donation_ids'] ?? [],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payout created successfully.',
            'data' => $payout,
        ]);
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

        return response()->json([
            'success' => true,
            'message' => 'Payout canceled successfully.',
            'data' => $payout,
        ]);
    }

    public function complete(Request $request, $id)
    {
        $payout = CharityPayout::where('id', $id)->orWhere('payout_id', $id)->firstOrFail();

        $payout->update([
            'status' => 'Paid',
            'completed_at' => now(),
            'date_paid_or_expected' => now()->format('M d, Y h:i A'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payout marked as complete.',
            'data' => $payout,
        ]);
    }
}
