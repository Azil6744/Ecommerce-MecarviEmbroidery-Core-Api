<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EcommerceOrderTransaction;
use Illuminate\Http\Request;

/**
 * Admin view of the per-order ledger: order, shipping, packaging, turnaround, tips and donations
 * recorded as separate transactions (one customer payment, split for reporting).
 */
class OrderTransactionController extends Controller
{
    public function index(Request $request)
    {
        $query = EcommerceOrderTransaction::query();

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('charity_name', 'like', "%{$search}%");
            });
        }
        if ($from = $request->query('from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        // Totals per type for the same filters (net of refunds)
        $totals = (clone $query)
            ->selectRaw('type, COUNT(*) as count, SUM(amount) as gross, SUM(refunded_amount) as refunded')
            ->groupBy('type')
            ->get()
            ->mapWithKeys(fn($r) => [$r->type => [
                'count' => (int) $r->count,
                'gross' => round((float) $r->gross, 2),
                'refunded' => round((float) $r->refunded, 2),
                'net' => round((float) $r->gross - (float) $r->refunded, 2),
            ]]);

        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $rows = $query->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $rows->items(),
            'totals' => $totals,
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
        ]);
    }
}
