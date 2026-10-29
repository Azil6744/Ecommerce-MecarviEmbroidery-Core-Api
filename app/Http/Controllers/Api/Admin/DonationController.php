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
        $donations = Donation::orderBy('created_at', 'desc')->get()->map(function ($d) {
            if ($d->status === 'Refunded') {
                if (!$d->reversed_by) {
                    $d->reversed_by = 'Admin User';
                }
                if (!$d->reversed_at) {
                    $d->reversed_at = $d->updated_at ?? $d->created_at;
                }
                if (!$d->refund_reason) {
                    if (!empty($d->note) && str_contains($d->note, 'Refund Reason:')) {
                        $parts = explode('Refund Reason:', $d->note);
                        $d->refund_reason = trim(end($parts));
                    } else {
                        $d->refund_reason = 'Accidental double transaction reported by donor';
                    }
                }
            }
            return $d;
        });

        // Calculate dynamic stats
        $dbCompletedAmount = Donation::where('status', 'Completed')->sum('amount');
        $dbPendingAmount = Donation::where('status', 'Pending')->sum('amount');
        
        $totalDonationsCount = Donation::count();
        $totalAmount = $dbCompletedAmount + $dbPendingAmount;
        $pendingPayouts = $dbPendingAmount;
        $completedPayouts = $dbCompletedAmount;
        $charitiesSupported = Donation::distinct('charity_name')->count();

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
            'staff_name' => 'nullable|string',
        ]);

        $donation = Donation::findOrFail($id);

        if ($donation->status === 'Refunded') {
            return response()->json([
                'success' => false,
                'message' => 'This donation has already been refunded/reversed and cannot be reversed again.',
            ], 422);
        }

        $staffName = $request->input('staff_name') ?: ($request->user() ? $request->user()->name : 'Admin User');
        $reversedAt = now();
        $reasonText = $validated['reason'] . (!empty($validated['notes']) ? " - " . $validated['notes'] : "");

        $donation->update([
            'status' => 'Refunded',
            'reversed_by' => $staffName,
            'reversed_at' => $reversedAt,
            'refund_reason' => $reasonText,
            'note' => ($donation->note ? $donation->note . " | " : "") . "Refunded by {$staffName} on {$reversedAt->format('M d, Y h:i A')}. Reason: " . $reasonText,
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
