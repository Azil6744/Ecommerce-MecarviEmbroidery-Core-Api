<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\EcommerceQuotation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class EcommerceQuotationController extends Controller
{
    public function index(Request $request)
    {
        $quotations = $this->visibleQuery($request)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['success' => true, 'data' => $quotations]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'quote_number' => ['nullable', 'string', 'max:255'],
            'product_id' => ['nullable', 'integer'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'customer_name' => ['required', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'customization' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
            'status' => ['nullable', 'string', 'max:80'],
            'total_estimated' => ['nullable', 'numeric', 'min:0'],
            'valid_until' => ['nullable', 'date'],
        ]);

        if (empty($data['contact_email']) && ! empty($data['customer_email'])) {
            $data['contact_email'] = $data['customer_email'];
        }
        if (empty($data['customer_email']) && ! empty($data['contact_email'])) {
            $data['customer_email'] = $data['contact_email'];
        }

        if (empty($data['valid_until'])) {
            $data['valid_until'] = now()->addDays(14)->toDateString();
        }

        if (empty($data['quote_number'])) {
            $data['quote_number'] = $this->generateQuoteNumber();
        }

        if (! array_key_exists('status', $data) || blank($data['status'])) {
            $data['status'] = 'pending';
        }

        $user = $request->user();
        $userId = $user?->id;
        $customerEmail = strtolower(trim((string) ($data['customer_email'] ?? $data['contact_email'] ?? '')));
        if (! $userId && $customerEmail !== '') {
            $existingUser = \App\Models\User::whereRaw('LOWER(email) = ?', [$customerEmail])->first();
            if ($existingUser) {
                $userId = $existingUser->id;
            }
        }

        if (Schema::hasColumn((new EcommerceQuotation)->getTable(), 'user_id')) {
            $data['user_id'] = $userId;
        }

        $item = EcommerceQuotation::create($data)->load(['product', 'user:id,name,email']);

        try {
            $email = $item->contact_email ?: $item->customer_email ?: optional($item->user)->email;
            if ($email) {
                // Customer-facing confirmation (the customer_qoute_request event below is the admin notice)
                app(\App\Services\EmailNotificationService::class)->sendEvent('quote_submitted', [
                    'customer_name' => $item->customer_name ?: 'Customer',
                    'customer_email' => $email,
                    'quote_number' => $item->quote_number,
                    'site_name' => config('app.name', 'Mecarvi Embroidery'),
                ], $email);
                app(\App\Services\EmailNotificationService::class)->sendEvent('customer_qoute_request', [
                    'customer_name' => $item->customer_name ?: 'Customer',
                    'customer_email' => $email,
                    'quote_number' => $item->quote_number,
                    'site_name' => config('app.name', 'Mecarvi Embroidery'),
                ], $email);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed sending customer_qoute_request email: ' . $e->getMessage());
        }

        return response()->json(['success' => true, 'data' => $item], 201);
    }

    public function show(Request $request, $id)
    {
        $item = $this->resolveQuotation($request, $id);

        return response()->json(['success' => true, 'data' => $item]);
    }

    public function update(Request $request, $id)
    {
        $item = $this->resolveQuotation($request, $id);
        $previousStatus = strtolower((string) $item->status);
        $user = $request->user();
        $isAdmin = $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

        $rules = [
            'company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer_name' => ['sometimes', 'required', 'string', 'max:255'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'customer_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'quantity' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'customization' => ['sometimes', 'nullable', 'array'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'status' => ['sometimes', 'required', 'string', 'max:80'],
            'total_estimated' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'valid_until' => ['sometimes', 'nullable', 'date'],
            'decline_reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'decline_info' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];

        if ($isAdmin) {
            $rules['quote_number'] = ['sometimes', 'required', 'string', 'max:255'];
            $rules['product_id'] = ['sometimes', 'nullable', 'integer'];
        }

        $validated = $request->validate($rules);

        // Store decline reasons into metadata if provided
        if (!empty($validated['decline_reason'])) {
            $meta = $item->metadata ?? [];
            $meta['decline_reason'] = [
                'reason' => $validated['decline_reason'],
                'additional_info' => $validated['decline_info'] ?? null,
                'declined_at' => now()->toIso8601String(),
            ];
            $validated['metadata'] = array_merge($item->metadata ?? [], $meta);
            unset($validated['decline_reason'], $validated['decline_info']);
        }

        $item->update($validated);
        $item->load(['product', 'user:id,name,email']);

        $newStatus = strtolower((string) ($validated['status'] ?? $item->status));
        if ($previousStatus !== $newStatus && in_array($newStatus, ['approved', 'accepted'], true)) {
            try {
                $email = $item->contact_email ?: $item->customer_email ?: optional($item->user)->email;
                if ($email) {
                    app(\App\Services\EmailNotificationService::class)->sendEvent('approved_qoute', [
                        'customer_name' => $item->customer_name ?: 'Customer',
                        'customer_email' => $email,
                        'quote_number' => $item->quote_number,
                        'total_amount' => '$' . number_format((float) ($item->quote_price ?? $item->total_estimated), 2),
                        'site_name' => config('app.name', 'Mecarvi Embroidery'),
                    ], $email);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Failed sending approved_qoute email: ' . $e->getMessage());
            }
        }

        return response()->json(['success' => true, 'data' => $item]);
    }

    public function destroy(Request $request, $id)
    {
        $item = $this->resolveQuotation($request, $id);
        $item->delete();

        return response()->json(['success' => true, 'message' => 'Deleted successfully']);
    }

    private function visibleQuery(Request $request): Builder
    {
        $query = EcommerceQuotation::query()->with(['product', 'user:id,name,email']);
        $user = $request->user();

        if ($user && (! method_exists($user, 'isSuperAdmin') || ! $user->isSuperAdmin())) {
            $userEmail = strtolower(trim((string) $user->email));
            $query->where(function ($q) use ($user, $userEmail) {
                $q->where('user_id', $user->id);
                if ($userEmail !== '') {
                    $q->orWhereRaw('LOWER(customer_email) = ?', [$userEmail])
                      ->orWhereRaw('LOWER(contact_email) = ?', [$userEmail]);
                }
            });
        } elseif (! $user && $request->filled('email')) {
            $reqEmail = strtolower(trim((string) $request->input('email')));
            $query->where(function ($q) use ($reqEmail) {
                $q->whereRaw('LOWER(customer_email) = ?', [$reqEmail])
                  ->orWhereRaw('LOWER(contact_email) = ?', [$reqEmail]);
            });
        }

        return $query;
    }

    private function resolveQuotation(Request $request, int|string $id): EcommerceQuotation
    {
        $query = $this->visibleQuery($request);

        if (is_string($id) && str_starts_with($id, 'Q-')) {
            $item = (clone $query)->where('quote_number', $id)->first();
            if ($item) return $item;

            $numId = substr($id, 2);
            if (is_numeric($numId)) {
                $item = (clone $query)->find($numId);
                if ($item) return $item;
            }
        }

        if (is_numeric($id)) {
            return $query->findOrFail($id);
        }

        return $query->where('quote_number', $id)->firstOrFail();
    }

    private function generateQuoteNumber(): string
    {
        do {
            $quoteNumber = 'Q-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(4));
        } while (EcommerceQuotation::query()->where('quote_number', $quoteNumber)->exists());

        return $quoteNumber;
    }
}
