<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EcommerceQuotation;
use Illuminate\Http\Request;

class AdminQuotationController extends Controller
{
    /**
     * Get all quotations
     */
    public function index(Request $request)
    {
        $query = EcommerceQuotation::with(['product', 'user:id,name,email'])->latest();

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('quote_number', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_email', 'like', "%{$search}%")
                  ->orWhere('contact_email', 'like', "%{$search}%")
                  ->orWhere('company_name', 'like', "%{$search}%");
            });
        }

        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);
        $quotations = $query->paginate($perPage);

        return response()->json($quotations);
    }

    /**
     * Show quotation details
     */
    public function show(EcommerceQuotation $quotation)
    {
        return response()->json($quotation->load('user'));
    }

    /**
     * Update quotation status
     */
    public function updateStatus(Request $request, EcommerceQuotation $quotation)
    {
        $request->validate([
            'status' => 'required|string|in:pending,quoted,approved,accepted,declined,rejected,expired,cancelled',
        ]);

        $previous = strtolower((string) $quotation->status);
        $status = strtolower($request->status);

        $quotation->update(['status' => $status]);
        $loaded = $quotation->load(['product', 'user:id,name,email']);

        if ($previous !== $status && in_array($status, ['approved', 'accepted'], true)) {
            try {
                $email = $loaded->contact_email ?: $loaded->customer_email ?: optional($loaded->user)->email;
                if ($email) {
                    app(\App\Services\EmailNotificationService::class)->sendEvent('approved_qoute', [
                        'customer_name' => $loaded->customer_name ?: 'Customer',
                        'customer_email' => $email,
                        'quote_number' => $loaded->quote_number,
                        'total_amount' => '$' . number_format((float) ($loaded->quote_price ?? $loaded->total_estimated), 2),
                        'site_name' => config('app.name', 'Mecarvi Embroidery'),
                    ], $email);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Failed sending approved_qoute email: ' . $e->getMessage());
            }
        }

        return response()->json($loaded);
    }

    /**
     * Send quote response to user
     */
    public function sendQuote(Request $request, EcommerceQuotation $quotation)
    {
        $validated = $request->validate([
            'quote_price' => 'required|numeric|min:0',
            'quote_details' => 'nullable|string',
            'valid_until' => 'nullable|date',
            'metadata' => 'nullable|array',
        ]);

        $meta = array_merge($quotation->metadata ?? [], $validated['metadata'] ?? []);
        $meta['last_quoted_at'] = now()->toIso8601String();

        $updateData = [
            'quote_price' => $validated['quote_price'],
            'quote_details' => $validated['quote_details'] ?? $quotation->quote_details,
            'status' => 'quoted',
            'quoted_at' => now(),
            'metadata' => $meta,
        ];

        if (!empty($validated['valid_until'])) {
            $updateData['valid_until'] = $validated['valid_until'];
        }

        $quotation->update($updateData);
        $loaded = $quotation->load(['product', 'user:id,name,email']);

        // Send email notification to user
        try {
            $email = $loaded->contact_email ?: $loaded->customer_email ?: optional($loaded->user)->email;
            if ($email) {
                app(\App\Services\EmailNotificationService::class)->sendEvent('quote_ready', [
                    'customer_name' => $loaded->customer_name ?: 'Customer',
                    'customer_email' => $email,
                    'quote_number' => $loaded->quote_number,
                    'total_amount' => '$' . number_format((float) $loaded->quote_price, 2),
                    'site_name' => config('app.name', 'Mecarvi Embroidery'),
                ], $email);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed sending quote sent email: ' . $e->getMessage());
        }

        return response()->json($loaded);
    }

    /**
     * Delete quotation
     */
    public function destroy(EcommerceQuotation $quotation)
    {
        $quotation->delete();

        return response()->json(['success' => true, 'message' => 'Quotation deleted successfully']);
    }
}