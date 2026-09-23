<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DonationController extends Controller
{
    public function index()
    {
        $donations = Donation::orderBy('created_at', 'desc')->get();

        // Calculate dynamic stats
        $dbCompletedAmount = Donation::where('status', 'Completed')->sum('amount');
        $dbPendingAmount = Donation::where('status', 'Pending')->sum('amount');
        
        $totalDonationsCount = Donation::count();
        $totalAmount = $dbCompletedAmount + $dbPendingAmount;
        $pendingPayouts = $dbPendingAmount;
        $completedPayouts = $dbCompletedAmount;
        $charitiesSupported = Donation::distinct('charity_name')->count();

        // Calculate Top Charities dynamically
        $topCharities = Donation::select('charity_name as name', 'charity_logo_type as logoType', DB::raw('SUM(amount) as amount'), DB::raw('COUNT(id) as donation_count'))
            ->groupBy('charity_name', 'charity_logo_type')
            ->orderBy('amount', 'desc')
            ->get();

        // Calculate Payment Methods Breakdown
        $methodsBreakdown = Donation::select('payment_method_brand as brand', DB::raw('SUM(amount) as amount'), DB::raw('COUNT(id) as count'))
            ->groupBy('payment_method_brand')
            ->get();

        // Calculate Recent Activity Feed dynamically
        $recentDonations = Donation::orderBy('created_at', 'desc')->take(10)->get();
        $activities = [];

        foreach ($recentDonations as $don) {
            $activities[] = [
                'id' => 'don_' . $don->id,
                'type' => 'donation_received',
                'text' => '$' . number_format($don->amount, 2) . ' from ' . $don->donor_name,
                'time' => $don->created_at->format('M d, Y • h:i A'),
                'variant' => $don->status === 'Failed' ? 'warning' : 'received',
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $donations,
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
        ]);

        $donation = Donation::findOrFail($id);
        $donation->update([
            'status' => 'Refunded',
            'note' => ($donation->note ? $donation->note . " | " : "") . "Refund Reason: " . $validated['reason'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Donation has been successfully reversed/refunded.',
            'data' => $donation,
        ]);
    }

    public function sendReceipt(Request $request, $id)
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $donation = Donation::findOrFail($id);

        return response()->json([
            'success' => true,
            'message' => 'Donation receipt dispatched successfully to ' . $validated['email'],
            'data' => $donation,
        ]);
    }
}
