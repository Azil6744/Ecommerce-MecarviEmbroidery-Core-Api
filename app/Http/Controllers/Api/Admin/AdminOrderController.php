<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EcommerceOrder;
use App\Services\EmailNotificationService;
use Illuminate\Http\Request;

class AdminOrderController extends Controller
{
    private const ORDER_STATUSES = [
        'pending',
        'payment_pending',
        'confirmed',
        'processing',
        'proof_ready',
        'proof_revision',
        'approved',
        'in_production',
        'shipped',
        'delivered',
        'completed',
        'cancelled',
        'refunded',
        'voided',
        'void',
    ];

    /**
     * Display all orders (admin only)
     */
    public function index(Request $request)
    {
        try {
            $query = EcommerceOrder::with(['user', 'items.product', 'proofs', 'verifications', 'statusEvents']);

            if ($request->has('status') && $request->status && $request->status !== 'all') {
                $statusParam = strtolower(trim((string) $request->status));
                if ($statusParam === 'delivered' || $statusParam === 'completed') {
                    $query->whereIn('status', ['delivered', 'completed']);
                } elseif ($statusParam === 'pending') {
                    $query->whereIn('status', ['pending', 'payment_pending', 'confirmed']);
                } elseif ($statusParam === 'processing') {
                    $query->whereIn('status', ['processing', 'in_production', 'proof_ready', 'proof_revision', 'approved']);
                } elseif ($statusParam === 'shipped') {
                    $query->whereIn('status', ['shipped', 'out_for_delivery']);
                } elseif ($statusParam === 'cancelled') {
                    $query->whereIn('status', ['cancelled', 'refunded', 'voided', 'void']);
                } else {
                    $query->where('status', $statusParam);
                }
            }

            if ($request->has('search') && $request->search) {
                $search = trim((string) $request->search);
                $query->where(function ($q) use ($search) {
                    $q->where('order_number', 'like', "%{$search}%")
                      ->orWhere('customer_name', 'like', "%{$search}%")
                      ->orWhere('customer_email', 'like', "%{$search}%")
                      ->orWhere('customer_phone', 'like', "%{$search}%")
                      ->orWhere('shipping_address', 'like', "%{$search}%")
                      ->orWhere('company_name', 'like', "%{$search}%")
                      ->orWhereHas('user', function ($uq) use ($search) {
                          $uq->where('name', 'like', "%{$search}%")
                              ->orWhere('email', 'like', "%{$search}%");
                      });
                });
            }

            if ($request->has('date_from') && $request->date_from) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }

            if ($request->has('date_to') && $request->date_to) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            $orders = $query->orderBy('created_at', 'desc')
                ->paginate(min((int) $request->get('per_page', 20), 100));
            $orders->getCollection()->transform(fn (EcommerceOrder $order) => $this->orderPayload($order));

            return response()->json([
                'success' => true,
                'data' => $orders,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch orders.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Resolve order by ID or order_number (with optional # prefix)
     */
    private function resolveOrder($id): ?EcommerceOrder
    {
        $raw = trim((string) $id);
        $clean = ltrim($raw, '#');

        $query = EcommerceOrder::query();

        if (is_numeric($clean)) {
            $query->where(function ($q) use ($clean, $raw) {
                $q->where('id', (int) $clean)
                  ->orWhere('order_number', $raw)
                  ->orWhere('order_number', $clean)
                  ->orWhere('order_number', '#' . $clean);
            });
        } else {
            $query->where(function ($q) use ($clean, $raw) {
                $q->where('order_number', $raw)
                  ->orWhere('order_number', $clean)
                  ->orWhere('order_number', '#' . $clean)
                  ->orWhereRaw('LOWER(order_number) = ?', [strtolower($clean)])
                  ->orWhereRaw('LOWER(order_number) = ?', [strtolower($raw)]);
            });
        }

        return $query->first();
    }

    /**
     * Get order details
     */
    public function show($id)
    {
        try {
            $order = $this->resolveOrder($id);
            if (! $order) {
                return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
            }
            $order->load(['user', 'items.product', 'proofs', 'verifications', 'statusEvents']);
            return response()->json(['success' => true, 'data' => ['order' => $this->orderPayload($order)]]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
        }
    }

    /**
     * Update order status
     */
    public function updateStatus(Request $request, $id)
    {
        try {
            $request->validate([
                'status' => 'required|in:' . implode(',', self::ORDER_STATUSES),
                'payment_status' => 'nullable|in:unpaid,paid,failed,refunded,partially_refunded,voided',
                'tracking_carrier' => 'nullable|string|max:255',
                'tracking_number' => 'nullable|string|max:255',
                'tracking_url' => 'nullable|string|max:1000',
                'estimated_delivery_at' => 'nullable|date',
                'note' => 'nullable|string',
            ]);

            $order = $this->resolveOrder($id);
            if (! $order) {
                return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
            }
            $previousStatus = strtolower((string) $order->status);
            $previousPaymentStatus = strtolower((string) $order->payment_status);
            $payload = $request->only([
                'status',
                'payment_status',
                'tracking_carrier',
                'tracking_number',
                'tracking_url',
                'estimated_delivery_at',
            ]);

            if ($request->status === 'shipped' && ! $order->shipped_at) {
                $payload['shipped_at'] = now();
            }

            if (in_array($request->status, ['delivered', 'completed'], true) && ! $order->delivered_at) {
                $payload['delivered_at'] = now();
            }

            $order->update($payload);

            // Restore product stock once when an order is cancelled, voided, or refunded by admin
            $currentStatusLower = strtolower((string) $order->status);
            $currentPayLower = strtolower((string) $order->payment_status);
            if (in_array($currentStatusLower, ['cancelled', 'voided', 'void', 'refunded'], true) || in_array($currentPayLower, ['voided', 'refunded'], true)) {
                $meta = is_array($order->metadata) ? $order->metadata : [];
                if (empty($meta['stock_restored'])) {
                    foreach ($order->items()->get() as $orderItem) {
                        if ($orderItem->product_id) {
                            $prod = \App\Models\Product::find($orderItem->product_id);
                            if ($prod && !$prod->is_digital && strtolower((string) $prod->product_type) !== 'quotation' && $prod->stock_quantity !== null) {
                                $newStock = (int) $prod->stock_quantity + max(0, (int) $orderItem->quantity);
                                $attrs = is_array($prod->attributes) ? $prod->attributes : [];
                                if (array_key_exists('stockQuantity', $attrs)) {
                                    $attrs['stockQuantity'] = (string) $newStock;
                                }
                                $prod->forceFill(['stock_quantity' => $newStock, 'attributes' => $attrs])->save();
                            }
                        }
                    }
                    $meta['stock_restored'] = true;
                    $order->update(['metadata' => $meta]);
                }

                \App\Models\Donation::where('order_id', $order->order_number)
                    ->where('status', '!=', 'Refunded')
                    ->update([
                        'status' => 'Refunded',
                        'reversed_by' => optional($request->user())->name ?? 'Admin',
                        'reversed_at' => now(),
                        'refund_reason' => $request->note ?: ('Order marked as ' . ($currentStatusLower ?: $currentPayLower)),
                    ]);
                $order->transactions()->update(['status' => 'refunded']);
            }

            // Handle Loyalty Points transitions based on status changes
            if ($order->user_id) {
                $currentStatus = strtolower((string) $order->status);
                $currentPaymentStatus = strtolower((string) $order->payment_status);

                // Determine which statuses trigger point release based on admin setting
                $loyaltySettings = \App\Services\LoyaltyService::getSettings();
                $availabilityTrigger = $loyaltySettings['point_availability_trigger'] ?? 'after_completed';

                $releaseStatuses = match ($availabilityTrigger) {
                    'after_payment'    => ['confirmed', 'payment_pending', 'processing', 'in_production', 'shipped', 'delivered', 'completed'],
                    'after_production' => ['in_production', 'shipped', 'delivered', 'completed'],
                    'after_shipped'    => ['shipped', 'delivered', 'completed'],
                    'manual'           => [], // Admin releases manually only — no auto-trigger
                    default            => ['delivered', 'completed'], // 'after_completed' (spec default)
                };

                // Award Points After Order Completion — prevents awarding points for unpaid or incomplete orders
                if (!empty($releaseStatuses) && in_array($currentStatus, $releaseStatuses, true) && $currentPaymentStatus === 'paid') {
                    // Only release if we're transitioning INTO an eligible state or payment was just marked paid
                    if (!in_array($previousStatus, $releaseStatuses, true) || $previousPaymentStatus !== 'paid') {
                        \App\Services\LoyaltyService::releasePendingPoints($order);
                    }
                } elseif (in_array($currentStatus, ['cancelled', 'voided', 'void'], true) || $currentPaymentStatus === 'voided') {
                    // Reverse Points on Refunds, voided or Cancellations
                    \App\Services\LoyaltyService::reverseOrderPoints($order, null, 'Order ' . ($currentStatus ?: 'cancelled') . ' by admin');
                    \App\Services\LoyaltyService::restoreRedeemedPoints($order, 'Restored redeemed points due to admin order ' . ($currentStatus ?: 'cancellation'));
                } elseif (in_array($currentStatus, ['refunded'], true) || in_array($currentPaymentStatus, ['refunded', 'partially_refunded'], true)) {
                    // Reverse Points on Refunds, voided or Cancellations
                    \App\Services\LoyaltyService::reverseOrderPoints($order, null, 'Order refund processed');
                    \App\Services\LoyaltyService::restoreRedeemedPoints($order, 'Restored redeemed points due to order refund');
                }
            }

            $order->statusEvents()->create([
                'user_id' => optional($request->user())->id,
                'status' => $request->status,
                'label' => 'Status updated to ' . str_replace('_', ' ', $request->status),
                'note' => $request->note,
            ]);

            $newStatus = strtolower((string) $request->status);
            if ($previousStatus !== $newStatus) {
                $eventKey = match ($newStatus) {
                    'in_production' => 'order_in_production',
                    'processing' => 'order_processing',
                    'proof_ready' => 'order_proof_ready',
                    'proof_revision' => 'order_proof_revision_requested',
                    'approved' => 'order_proof_approved',
                    'ready_for_pickup' => 'order_ready_for_pickup',
                    'on_hold', 'hold' => 'order_on_hold',
                    'shipped' => 'order_shipped',
                    'out_for_delivery' => 'order_out_for_delivery',
                    'delivered', 'completed' => 'order_delivered',
                    'cancelled' => 'customer_order_cancellation',
                    'refunded' => 'order_refunded',
                    'declined' => 'order_declined',
                    'delayed' => 'order_delayed',
                    'confirmed' => 'order_confirmed',
                    'pending_verification', 'verification' => 'order_verification_required',
                    default => 'order_status_changed',
                };

                app(EmailNotificationService::class)->sendOrderEvent($eventKey, $order->fresh(['items', 'pickupLocation']), [
                    'reason' => $request->note ?: 'Order status updated to ' . str_replace('_', ' ', $newStatus),
                    'delay_reason' => $request->note ?: 'Scheduled processing update',
                    'hold_reason' => $request->note ?: 'Order is temporarily on hold',
                ]);

                if ($eventKey === 'customer_order_cancellation') {
                    app(EmailNotificationService::class)->sendOrderEvent('order_cancelled', $order->fresh(['items']));
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Order status updated.',
                'data' => ['order' => $this->orderPayload($order->fresh(['user', 'items.product', 'proofs', 'verifications', 'statusEvents']))],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to update order.', 'error' => config('app.debug') ? $e->getMessage() : null], 500);
        }
    }

    /**
     * Delete order
     */
    public function destroy($id)
    {
        try {
            $order = $this->resolveOrder($id);
            if (! $order) {
                return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
            }
            $order->delete();
            return response()->json(['success' => true, 'message' => 'Order deleted.']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to delete order.'], 500);
        }
    }

    /**
     * Get orders summary stats
     */
    public function stats()
    {
        try {
            $total = EcommerceOrder::count();
            $pending = EcommerceOrder::whereIn('status', ['pending', 'payment_pending', 'confirmed'])->count();
            $processing = EcommerceOrder::whereIn('status', ['processing', 'in_production', 'proof_ready', 'proof_revision', 'approved'])->count();
            $shipped = EcommerceOrder::whereIn('status', ['shipped', 'out_for_delivery'])->count();
            $delivered = EcommerceOrder::whereIn('status', ['delivered', 'completed'])->count();
            $cancelled = EcommerceOrder::whereIn('status', ['cancelled', 'refunded', 'voided', 'void'])->count();
            $revenue = (float) EcommerceOrder::whereIn('status', ['delivered', 'completed'])->sum('total_amount');

            return response()->json([
                'success' => true,
                'data' => [
                    'total' => $total,
                    'pending' => $pending,
                    'processing' => $processing,
                    'shipped' => $shipped,
                    'delivered' => $delivered,
                    'completed' => $delivered,
                    'cancelled' => $cancelled,
                    'revenue' => round($revenue, 2),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to get stats.'], 500);
        }
    }

    private function orderPayload(EcommerceOrder $order): array
    {
        $order->loadMissing(['user', 'items.product', 'proofs', 'verifications', 'statusEvents', 'transactions']);
        $subtotal = (float) ($order->subtotal ?: $order->items->sum('total_price'));

        $items = $order->items->map(function ($item) {
            $prod = $item->product;
            $opts = is_array($item->product_options) ? $item->product_options : [];
            $img = $opts['image_url'] ?? ($opts['product_image'] ?? ($opts['image'] ?? null));
            if (!$img && $prod) {
                if ($prod->primary_image_url) {
                    $img = $prod->primary_image_url;
                } elseif ($prod->image_url) {
                    $img = $prod->image_url;
                } elseif (is_array($prod->images) && count($prod->images) > 0) {
                    $firstImg = $prod->images[0];
                    $img = is_string($firstImg) ? (str_starts_with($firstImg, 'http') || str_starts_with($firstImg, '/') ? $firstImg : '/storage/' . $firstImg) : null;
                }
            }
            if ($img && !str_starts_with($img, 'http://') && !str_starts_with($img, 'https://')) {
                if (str_starts_with($img, 'storage/') || str_starts_with($img, '/storage/')) {
                    $img = asset(ltrim($img, '/'));
                } elseif (str_starts_with($img, 'products/') || str_starts_with($img, '/products/')) {
                    $img = asset('storage/' . ltrim($img, '/'));
                }
            }

            $name = $item->product_name ?: ($prod?->name ?? 'Embroidered Apparel');
            $type = 'hoodie';
            $nameLower = strtolower($name);
            if (str_contains($nameLower, 'polo')) $type = 'polo';
            elseif (str_contains($nameLower, 'tee') || str_contains($nameLower, 't-shirt') || str_contains($nameLower, 'shirt')) $type = 'tshirt';
            elseif (str_contains($nameLower, 'cap') || str_contains($nameLower, 'hat')) $type = 'cap';
            elseif (str_contains($nameLower, 'tote') || str_contains($nameLower, 'bag')) $type = 'bag';
            elseif (str_contains($nameLower, 'jacket') || str_contains($nameLower, 'hoodie')) $type = 'hoodie';
            elseif (str_contains($nameLower, 'tumbler') || str_contains($nameLower, 'bottle')) $type = 'tumbler';

            return [
                'id' => $item->id,
                'name' => $name,
                'product_name' => $name,
                'type' => $type,
                'product_id' => $item->product_id,
                'product_sku' => $item->product_sku ?: $prod?->sku,
                'quantity' => (int) ($item->quantity ?: 1),
                'qty' => (int) ($item->quantity ?: 1),
                'unit_price' => (float) $item->unit_price,
                'price' => (float) $item->unit_price,
                'total_price' => (float) $item->total_price,
                'product_options' => $opts,
                'color' => $opts['color'] ?? 'black',
                'size' => $opts['size'] ?? null,
                'image' => $img,
                'product' => $prod ? [
                    'id' => $prod->id,
                    'name' => $prod->name,
                    'sku' => $prod->sku,
                    'images' => $prod->images,
                ] : null,
            ];
        });

        // Extract last 4 of card from payment method or meta
        $cardLast4 = null;
        if (is_array($order->metadata) && !empty($order->metadata['card_last4'])) {
            $cardLast4 = (string) $order->metadata['card_last4'];
        } elseif ($order->payment_method && preg_match('/\b(\d{4})\b/', $order->payment_method, $matches)) {
            $cardLast4 = $matches[1];
        }

        $customerName = $order->customer_name ?: ($order->user?->name ?: 'Guest Customer');
        $customerEmail = $order->customer_email ?: ($order->user?->email ?: 'customer@example.com');
        $customerPhone = $order->customer_phone ?: ($order->user?->phone ?: '');

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'user_id' => $order->user_id,
            'user' => $order->user,
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'customer_phone' => $customerPhone,
            'customer_location' => $order->shipping_address ? explode("\n", trim($order->shipping_address))[count(explode("\n", trim($order->shipping_address))) - 1] : '',
            'company_name' => $order->company_name,
            'status' => strtolower($order->status),
            'payment_status' => $order->payment_status ?: 'paid',
            'payment_method' => $order->payment_method ?: 'VISA',
            'card_last4' => $cardLast4,
            'shipping_method' => $order->shipping_method ?: 'Standard Delivery',
            'fulfillment_method' => $order->shipping_method ?: 'Standard Delivery',
            'fulfillment_time' => (str_contains(strtolower((string) $order->shipping_method), 'express') ? '1-2 days' : (str_contains(strtolower((string) $order->shipping_method), 'next') ? '1 day' : '2-5 days')),
            'currency' => $order->currency ?? 'USD',
            'subtotal' => round($subtotal, 2),
            'shipping_amount' => (float) ($order->shipping_amount ?? 0),
            'shipping_cost' => (float) ($order->shipping_amount ?? 0),
            'turnaround_amount' => is_array($order->metadata) && isset($order->metadata['turnaround_amount']) ? (float) $order->metadata['turnaround_amount'] : 0,
            'discount_amount' => (float) ($order->discount_amount ?? 0),
            'tax_amount' => (float) ($order->tax_amount ?? 0),
            'tip_amount' => (float) ($order->tip_amount ?? 0),
            'donation_amount' => (float) ($order->donation_amount ?? 0),
            'selected_charity' => $order->selected_charity ?? null,
            'total_amount' => (float) $order->total_amount,
            'transactions' => $order->transactions ? $order->transactions->map(fn ($t) => [
                'id' => $t->id,
                'type' => $t->type,
                'label' => $t->label,
                'amount' => (float) $t->amount,
                'refunded_amount' => (float) $t->refunded_amount,
                'status' => $t->status,
                'charity_name' => $t->charity_name,
                'shipment_group' => $t->shipment_group,
                'meta' => $t->meta,
            ])->values() : [],
            'shipping_address' => $order->shipping_address,
            'billing_address' => $order->billing_address,
            'tracking_carrier' => $order->tracking_carrier,
            'tracking_number' => $order->tracking_number,
            'tracking_url' => $order->tracking_url,
            'estimated_delivery_at' => optional($order->estimated_delivery_at)->toIso8601String(),
            'shipped_at' => optional($order->shipped_at)->toIso8601String(),
            'delivered_at' => optional($order->delivered_at)->toIso8601String(),
            'notes' => $order->notes,
            'metadata' => $order->metadata,
            'packaging_option' => is_array($order->metadata) && isset($order->metadata['packaging_option']) ? $order->metadata['packaging_option'] : 'Standard Packaging',
            'packaging_mode' => is_array($order->metadata) && isset($order->metadata['packaging_mode']) ? $order->metadata['packaging_mode'] : 'together',
            'packaging_style' => is_array($order->metadata) && isset($order->metadata['packaging_style']) ? $order->metadata['packaging_style'] : 'standard',
            'packaging_amount' => is_array($order->metadata) && isset($order->metadata['packaging_amount']) ? (float) $order->metadata['packaging_amount'] : 0,
            'add_thank_you_card' => is_array($order->metadata) && !empty($order->metadata['add_thank_you_card']),
            'add_extra_protection' => is_array($order->metadata) && !empty($order->metadata['add_extra_protection']),
            'add_package_insurance' => is_array($order->metadata) && !empty($order->metadata['add_package_insurance']),
            'gift_details' => is_array($order->metadata) && isset($order->metadata['gift_details']) ? $order->metadata['gift_details'] : null,
            'recipient_name' => is_array($order->metadata) ? ($order->metadata['recipient_name'] ?? ($order->metadata['gift_details']['recipient_name'] ?? null)) : null,
            'gift_message' => is_array($order->metadata) ? ($order->metadata['gift_message'] ?? ($order->metadata['gift_details']['special_message'] ?? ($order->metadata['gift_details']['message'] ?? null))) : null,
            'card_orientation' => is_array($order->metadata) ? ($order->metadata['card_orientation'] ?? ($order->metadata['gift_details']['card_orientation'] ?? 'horizontal')) : 'horizontal',
            'additional_instructions' => is_array($order->metadata) ? ($order->metadata['additional_instructions'] ?? ($order->metadata['gift_details']['additional_instructions'] ?? null)) : null,
            'order_date' => optional($order->order_date ?: $order->created_at)->toIso8601String(),
            'created_at' => optional($order->created_at)->toIso8601String(),
            'updated_at' => optional($order->updated_at)->toIso8601String(),
            'items' => $items,
            'item_count' => (int) ($items->sum('quantity') ?: 1),
            'products_preview' => $items->take(3)->values(),
            'more_products_count' => max(0, $items->count() - 3),
            'proofs' => $order->proofs,
            'verifications' => $order->verifications,
            'status_events' => $order->statusEvents,
        ];
    }
}
