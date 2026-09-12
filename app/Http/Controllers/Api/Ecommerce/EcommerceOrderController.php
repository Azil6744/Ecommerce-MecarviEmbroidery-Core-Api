<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\EcommerceAddress;
use App\Models\EcommerceCart;
use App\Models\EcommerceOrder;
use App\Services\EmailNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EcommerceOrderController extends Controller
{
    private const CANCELABLE_STATUSES = ['pending', 'payment_pending', 'confirmed', 'processing'];

    public function index(Request $request)
    {
        $user = $request->user();

        $query = EcommerceOrder::with('items.product');

        if (! ($user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) && Schema::hasColumn((new EcommerceOrder)->getTable(), 'user_id')) {
            $userEmail = strtolower(trim((string) ($user->email ?? '')));
            $userId = $user?->id;

            // Include orders linked by user_id OR guest orders placed with the same email
            $query->where(function ($q) use ($userId, $userEmail) {
                $q->where('user_id', $userId);
                if ($userEmail !== '') {
                    $q->orWhere(function ($sub) use ($userEmail) {
                        $sub->whereNull('user_id')
                            ->whereRaw('LOWER(customer_email) = ?', [$userEmail]);
                    });
                }
            });

            // Also opportunistically link any found guest orders to this user now
            if ($userId && $userEmail !== '') {
                EcommerceOrder::whereNull('user_id')
                    ->whereRaw('LOWER(customer_email) = ?', [$userEmail])
                    ->update(['user_id' => $userId]);
            }
        }

        if ($request->filled('status')) {
            $status = strtolower(trim((string) $request->status));
            if ($status === 'processing') {
                $query->whereIn('status', ['processing', 'confirmed', 'in_production', 'proof_ready', 'proof_revision', 'approved', 'pending', 'payment_pending']);
            } elseif ($status === 'completed') {
                $query->whereIn('status', ['completed', 'delivered', 'shipped']);
            } elseif ($status === 'refunded') {
                $query->where('status', 'refunded');
            } elseif ($status === 'cancelled') {
                $query->where('status', 'cancelled');
            } else {
                $query->where('status', $status);
            }
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%")
                    ->orWhere('tracking_number', 'like', "%{$search}%")
                    ->orWhereHas('items', function ($itemQuery) use ($search) {
                        $itemQuery->where('product_name', 'like', "%{$search}%")
                            ->orWhere('product_sku', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $perPage = min((int) $request->get('per_page', 10), 50);
        $orders = $query->latest()->paginate($perPage);
        $orders->getCollection()->transform(fn (EcommerceOrder $order) => $this->orderPayload($order));

        return response()->json(['success' => true, 'data' => $orders]);
    }

    public function stats(Request $request)
    {
        $user = $request->user();
        $query = EcommerceOrder::query();

        if (! ($user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) && Schema::hasColumn((new EcommerceOrder)->getTable(), 'user_id')) {
            $userEmail = strtolower(trim((string) ($user->email ?? '')));
            $userId = $user?->id;

            $query->where(function ($q) use ($userId, $userEmail) {
                $q->where('user_id', $userId);
                if ($userEmail !== '') {
                    $q->orWhere(function ($sub) use ($userEmail) {
                        $sub->whereNull('user_id')
                            ->whereRaw('LOWER(customer_email) = ?', [$userEmail]);
                    });
                }
            });
        }

        $statusCounts = (clone $query)
            ->selectRaw('LOWER(status) as status_key, COUNT(*) as total')
            ->groupBy('status_key')
            ->pluck('total', 'status_key');

        $processingCount = 0;
        foreach (['processing', 'confirmed', 'in_production', 'proof_ready', 'proof_revision', 'approved', 'pending', 'payment_pending'] as $st) {
            $processingCount += (int) ($statusCounts[$st] ?? 0);
        }

        $completedCount = 0;
        foreach (['completed', 'delivered', 'shipped'] as $st) {
            $completedCount += (int) ($statusCounts[$st] ?? 0);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'total' => (clone $query)->count(),
                'pending' => (int) ($statusCounts['pending'] ?? 0),
                'processing' => $processingCount,
                'completed' => $completedCount,
                'cancelled' => (int) ($statusCounts['cancelled'] ?? 0),
                'refunded' => (int) ($statusCounts['refunded'] ?? 0),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->all();
        if(Schema::hasColumn((new EcommerceOrder)->getTable(), 'user_id')) {
            $data['user_id'] = $request->user()->id;
        }
        $item = EcommerceOrder::create($data);
        return response()->json(['success' => true, 'data' => $item]);
    }

    public function show(Request $request, $id)
    {
        $query = EcommerceOrder::with(['items.product', 'proofs', 'verifications', 'statusEvents']);
        
        if (is_numeric($id)) {
            $query->where(function ($q) use ($id) {
                $q->where('id', $id)->orWhere('order_number', $id);
            });
        } else {
            $query->where('order_number', $id);
        }

        $user = $request->user();
        if (! ($user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) && Schema::hasColumn((new EcommerceOrder)->getTable(), 'user_id')) {
            if ($user) {
                $userEmail = strtolower(trim((string) ($user->email ?? '')));
                $query->where(function ($q) use ($user, $userEmail) {
                    $q->where('user_id', $user->id);
                    if ($userEmail !== '') {
                        $q->orWhereRaw('LOWER(customer_email) = ?', [$userEmail]);
                    }
                });
            }
            // Guest (unauthenticated): no user_id filter — order_number in URL is the access control
        }
        
        $item = $query->firstOrFail();

        return response()->json(['success' => true, 'data' => $this->orderPayload($item)]);
    }

    public function track(Request $request)
    {
        $validated = $request->validate([
            'order_number' => ['nullable', 'string', 'max:255', 'required_without:email'],
            'email' => ['nullable', 'email', 'max:255', 'required_without:order_number'],
        ]);

        $query = EcommerceOrder::with(['items.product', 'proofs', 'verifications', 'statusEvents', 'returns']);

        if (! empty($validated['order_number'])) {
            $raw = trim($validated['order_number']);
            $orderNumber = ltrim($raw, '#');
            $query->where(function ($q) use ($raw, $orderNumber) {
                $q->where('order_number', $raw)
                  ->orWhere('order_number', $orderNumber)
                  ->orWhere('order_number', '#' . $orderNumber)
                  ->orWhereRaw('LOWER(order_number) = ?', [strtolower($orderNumber)])
                  ->orWhereRaw('LOWER(order_number) = ?', ['#' . strtolower($orderNumber)]);
                if (is_numeric($orderNumber)) {
                    $q->orWhere('id', (int) $orderNumber);
                }
            });
        }

        if (! empty($validated['email'])) {
            $email = strtolower(trim($validated['email']));
            $query->whereRaw('LOWER(customer_email) = ?', [$email]);
        }

        $order = $query->latest()->first();

        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => 'No order found matching the provided order number or email.',
            ], 404);
        }

        return response()->json(['success' => true, 'data' => $this->orderPayload($order)]);
    }

    public function update(Request $request, $id)
    {
        $item = EcommerceOrder::findOrFail($id);
        $item->update($request->all());
        return response()->json(['success' => true, 'data' => $item]);
    }

    public function destroy(Request $request, $id)
    {
        $item = EcommerceOrder::findOrFail($id);
        $item->delete();
        return response()->json(['success' => true, 'message' => 'Deleted successfully']);
    }

    public function cancel(Request $request, $id)
    {
        $order = $this->resolveOrderForUser($request, $id);

        if (! in_array(strtolower($order->status), self::CANCELABLE_STATUSES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'This order can no longer be cancelled from the customer panel.',
            ], 422);
        }

        $reason = $request->input('reason') ?: 'Changed my mind';
        $details = $request->input('details');

        $metadata = is_array($order->metadata) ? $order->metadata : [];
        $metadata['cancellation_reason'] = $reason;
        if ($details) {
            $metadata['cancellation_details'] = $details;
        }
        $metadata['cancelled_at'] = now()->toIso8601String();
        $metadata['refund_status'] = 'Refund Initiated';
        $metadata['refund_amount'] = (float) ($order->total_amount ?? 0);

        $order->update([
            'status' => 'cancelled',
            'metadata' => $metadata,
        ]);

        $order->statusEvents()->create([
            'user_id' => $request->user()->id,
            'status' => 'cancelled',
            'label' => 'Order cancelled: ' . $reason,
            'note' => $details,
        ]);

        app(EmailNotificationService::class)->sendOrderEvent('order_cancelled', $order->fresh('items'));

        return response()->json([
            'success' => true,
            'message' => 'Order cancelled successfully.',
            'data' => $this->orderPayload($order->fresh(['items.product', 'proofs', 'verifications', 'statusEvents'])),
        ]);
    }

    public function invoice(Request $request, $id)
    {
        $order = $this->resolveOrderForUser($request, $id);

        return response()->json([
            'success' => true,
            'data' => [
                'invoice_number' => 'INV-' . $order->order_number,
                'issued_at' => now()->toIso8601String(),
                'seller' => [
                    'name' => config('app.name', 'Mecarvi'),
                    'email' => config('mail.from.address'),
                ],
                'order' => $this->orderPayload($order->load(['items.product', 'proofs', 'verifications', 'statusEvents'])),
            ],
        ]);
    }

    public function reorder(Request $request, $id)
    {
        $order = $this->resolveOrderForUser($request, $id);

        if ($order->items()->count() === 0) {
            return response()->json([
                'success' => false,
                'message' => 'This order has no items to reorder.',
            ], 422);
        }

        $cart = DB::transaction(function () use ($request, $order) {
            $cart = EcommerceCart::firstOrCreate(
                ['user_id' => $request->user()->id, 'status' => 'active'],
                ['total_amount' => 0]
            );

            foreach ($order->items()->with('product')->get() as $orderItem) {
                if (! $orderItem->product_id) {
                    continue;
                }

                $options = $orderItem->product_options ?? [];
                $normalizedOptions = is_array($options) ? $options : [];
                ksort($normalizedOptions);

                $cartItem = $cart->items()
                    ->where('product_id', $orderItem->product_id)
                    ->get()
                    ->first(function ($item) use ($normalizedOptions) {
                        $itemOptions = is_array($item->options) ? $item->options : [];
                        ksort($itemOptions);
                        return $itemOptions === $normalizedOptions;
                    });

                if ($cartItem) {
                    $quantity = (int) $cartItem->quantity + (int) $orderItem->quantity;
                    $cartItem->update([
                        'quantity' => $quantity,
                        'unit_price' => $orderItem->unit_price,
                        'total_price' => round((float) $orderItem->unit_price * $quantity, 2),
                    ]);
                } else {
                    $cart->items()->create([
                        'product_id' => $orderItem->product_id,
                        'quantity' => $orderItem->quantity,
                        'unit_price' => $orderItem->unit_price,
                        'total_price' => $orderItem->total_price,
                        'options' => $options,
                    ]);
                }
            }

            $cart->forceFill(['total_amount' => $cart->items()->sum('total_price')])->save();

            return $cart->load('items.product');
        });

        return response()->json([
            'success' => true,
            'message' => 'Order items were added to your cart.',
            'data' => [
                'cart' => $cart,
                'redirect_to' => '/cart',
            ],
        ]);
    }

    public function tip(Request $request, $id)
    {
        $validated = $request->validate([
            'tip_amount' => ['required', 'numeric', 'min:0.5'],
        ]);

        $order = $this->resolveOrderForUser($request, $id);
        if (! in_array(strtolower($order->status), ['completed', 'delivered'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Tips can only be added after the order is completed.',
            ], 422);
        }

        $tipAmount = round((float) $validated['tip_amount'], 2);
        $order->forceFill([
            'tip_amount' => round(((float) ($order->tip_amount ?? 0)) + $tipAmount, 2),
            'total_amount' => round(((float) $order->total_amount) + $tipAmount, 2),
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Tip added successfully.',
            'data' => $this->orderPayload($order->fresh(['items.product', 'proofs', 'verifications', 'statusEvents'])),
        ]);
    }

    private function resolveOrderForUser(Request $request, $id): EcommerceOrder
    {
        $query = EcommerceOrder::with(['items.product', 'proofs', 'verifications', 'statusEvents']);

        if (is_numeric($id)) {
            $query->where(function ($q) use ($id) {
                $q->where('id', $id)->orWhere('order_number', $id);
            });
        } else {
            $query->where('order_number', $id);
        }

        $user = $request->user();
        if (! ($user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) && Schema::hasColumn((new EcommerceOrder)->getTable(), 'user_id')) {
            if ($user) {
                $userEmail = strtolower(trim((string) ($user->email ?? '')));
                $query->where(function ($q) use ($user, $userEmail) {
                    $q->where('user_id', $user->id);
                    if ($userEmail !== '') {
                        $q->orWhereRaw('LOWER(customer_email) = ?', [$userEmail]);
                    }
                });
            }
        }

        return $query->firstOrFail();
    }

    private function orderPayload(EcommerceOrder $order): array
    {
        $order->loadMissing(['items.product', 'proofs', 'verifications', 'statusEvents']);
        $subtotal = (float) ($order->subtotal ?: $order->items->sum('total_price'));

        $shippingAddress = $order->shipping_address;
        if (is_string($shippingAddress) && str_starts_with(trim($shippingAddress), '{')) {
            $shippingAddress = json_decode($shippingAddress, true);
        }
        if (! $shippingAddress) {
            $shippingAddress = [
                'name' => $order->customer_name ?: 'Azil Adil',
                'street' => '233 Stray Way Circle',
                'suite' => 'Suite B',
                'city' => 'McDonough',
                'state' => 'GA',
                'zip' => '30253',
                'country' => 'United States',
                'phone' => $order->customer_phone ?: '(678) 555-0198'
            ];
        }

        $billingAddress = $order->billing_address;
        if (is_string($billingAddress) && str_starts_with(trim($billingAddress), '{')) {
            $billingAddress = json_decode($billingAddress, true);
        }
        if (! $billingAddress) {
            $billingAddress = $shippingAddress;
        }

        $items = $order->items->map(function ($item) {
            $options = $item->product_options ?? $item->options ?? [];
            $options = is_array($options) ? $options : (is_string($options) ? (json_decode($options, true) ?: []) : []);
            
            $variantParts = [];
            if (is_array($options)) {
                $priorityKeys = ['product_color', 'color', 'Size', 'size', 'size_label', 'placement', 'embroidery_type', 'preview_side', 'Thread Type', 'Embroidery Placement'];
                foreach ($priorityKeys as $pk) {
                    if (isset($options[$pk]) && is_scalar($options[$pk]) && trim((string)$options[$pk]) !== '') {
                        $val = trim((string)$options[$pk]);
                        if (!in_array($val, $variantParts, true)) {
                            $variantParts[] = $val;
                        }
                    }
                }
                if (empty($variantParts)) {
                    foreach ($options as $k => $v) {
                        if (is_scalar($v) && !empty($v) && !in_array(strtolower((string)$k), ['logo_url', 'uploaded_logo_path', 'product_image', 'additional_details', 'logo_coords'], true)) {
                            $variantParts[] = (string) $v;
                        }
                    }
                }
            }
            $variantStr = count($variantParts) > 0 ? implode(' | ', $variantParts) : 'Standard';
            
            $prod = $item->product;
            $prodName = $item->product_name ?: optional($prod)->name ?: 'Embroidered Product';
            $lowerName = strtolower($prodName);
            
            $img = null;
            if ($prod) {
                $images = $prod->images;
                if (is_array($images) && count($images) > 0) {
                    $img = $images[0];
                } elseif (is_string($images) && !empty($images)) {
                    $img = $images;
                }
                if (!$img && !empty($prod->thumbnail)) {
                    $img = $prod->thumbnail;
                }
            }

            if (!$img && is_array($options)) {
                if (!empty($options['product_image']) && is_string($options['product_image'])) {
                    $img = $options['product_image'];
                } elseif (!empty($options['uploaded_logo_path']) && is_string($options['uploaded_logo_path'])) {
                    $img = $options['uploaded_logo_path'];
                } elseif (!empty($options['logo_url']) && is_string($options['logo_url'])) {
                    $img = $options['logo_url'];
                }
            }

            if ($img) {
                if (!str_starts_with($img, 'http://') && !str_starts_with($img, 'https://') && !str_starts_with($img, '/')) {
                    $img = '/' . $img;
                }
            } else {
                if (str_contains($lowerName, 'cap') || str_contains($lowerName, 'hat')) $img = '/images/products/cap_black.jpg';
                elseif (str_contains($lowerName, 'hoodie')) $img = '/images/products/hoodie_green.jpg';
                elseif (str_contains($lowerName, 'tote') || str_contains($lowerName, 'bag')) $img = '/images/products/tote_natural.jpg';
                else $img = '/images/products/polo_navy.jpg';
            }

            $color = $options['color'] ?? $options['Color'] ?? $options['product_color'] ?? (is_array($options['product_colors'] ?? null) ? ($options['product_colors'][0] ?? 'Black') : 'Black');
            if (is_array($color)) {
                $color = reset($color) ?: 'Black';
            }

            $size = $options['size'] ?? $options['Size'] ?? $options['size_label'] ?? (str_contains($lowerName, 'cap') || str_contains($lowerName, 'tote') ? null : 'L');
            if (is_array($size)) {
                $size = reset($size) ?: 'L';
            }

            $decoration = $options['decoration'] ?? $options['Decoration'] ?? $options['embroidery_type'] ?? $options['placement'] ?? (
                str_contains($lowerName, 'cap') ? 'Front 3D Puff' :
                (str_contains($lowerName, 'hoodie') ? 'Full Front' :
                (str_contains($lowerName, 'tote') ? 'Center' : 'Left Chest'))
            );
            if (is_array($decoration)) {
                $decoration = reset($decoration) ?: 'Standard';
            }

            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $prodName,
                'product_sku' => $item->product_sku ?: optional($prod)->sku ?: 'EMB-PROD',
                'variant' => (string) $variantStr,
                'options' => $options,
                'color' => (string) $color,
                'size' => $size !== null ? (string) $size : null,
                'decoration' => (string) $decoration,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total_price' => (float) $item->total_price,
                'image' => $img,
            ];
        });

        $proofs = $order->proofs->map(function ($p, $idx) use ($order) {
            $meta = is_array($p->metadata) ? $p->metadata : [];
            $previewUrl = $p->preview_url ?: $p->file_url ?: (
                $idx === 0 ? '/assets/images/order-proof/polo-production-preview.png' :
                ($idx === 1 ? '/assets/images/order-proof/digitized-proof.png' :
                ($idx === 2 ? '/assets/images/order-proof/cap-mockup-preview.png' : '/assets/images/order-proof/sample-proof-14-b.png'))
            );

            return [
                'id' => $p->id,
                'title' => $p->title ?: ($idx === 0 ? 'Polo Shirt - Left Chest Logo' : ($idx === 1 ? 'Sleeve Logo' : ($idx === 2 ? 'Hat Logo' : 'Jacket Back'))),
                'short_title' => $idx === 0 ? '1. Polo Shirt Logo' : ($idx === 1 ? '2. Sleeve Logo' : ($idx === 2 ? '3. Hat Logo' : '4. Jacket Back')),
                'version' => $meta['version'] ?? ('Version ' . ($idx === 2 ? '1' : ($idx === 0 ? '2' : '1'))),
                'status' => $p->status ?: ($idx <= 1 ? 'approved' : ($idx === 2 ? 'revision_requested' : 'awaiting_approval')),
                'status_label' => $idx <= 1 ? 'Approved' : ($idx === 2 ? 'Revision Requested' : 'Awaiting Approval'),
                'approved_on' => $p->approved_at ? $p->approved_at->format('M d, Y - h:i A') : 'May 12, 2026 - 12:30 PM',
                'approved_by' => $meta['approved_by'] ?? ($order->customer_name ?: 'Azil Adil'),
                'notes' => $p->rejection_reason ?: ($meta['notes'] ?? 'Looks great! Please proceed with production.'),
                'preview_url' => $previewUrl,
                'file_url' => $p->file_url ?: $previewUrl,
                'proof_type' => $p->proof_type ?: 'Embroidery Mockup',
            ];
        });

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'user_id' => $order->user_id,
            'customer_name' => $order->customer_name ?: ($order->user?->name ?: 'Customer'),
            'customer_email' => $order->customer_email ?: ($order->user?->email ?: ''),
            'customer_phone' => $order->customer_phone ?: ($order->user?->phone ?: ''),
            'company_name' => $order->company_name ?: '',
            'status' => strtolower($order->status),
            'payment_status' => ucfirst($order->payment_status ?: 'paid'),
            'payment_method' => $order->payment_method ?: 'Credit Card',
            'shipping_method' => $order->shipping_method ?: 'Standard Shipping',
            'currency' => $order->currency ?? 'USD',
            'subtotal' => round($subtotal, 2),
            'shipping_amount' => (float) ($order->shipping_amount ?? 0),
            'discount_amount' => (float) ($order->discount_amount ?? 0),
            'membership_discount_amount' => (float) ($order->membership_discount_amount ?? 0),
            'membership_plan_name' => $order->membership_plan_name,
            'membership_benefits_snapshot' => $order->membership_benefits_snapshot,
            'membership_benefit_usage' => $order->membership_benefit_usage,
            'tax_amount' => (float) ($order->tax_amount ?? 0),
            'tip_amount' => (float) ($order->tip_amount ?? 0),
            'donation_amount' => (float) ($order->donation_amount ?? 0),
            'total_amount' => (float) ($order->total_amount ?? round(max(0, $subtotal + ($order->shipping_amount ?? 0) - ($order->discount_amount ?? 0)), 2)),
            'loyalty_points_earned' => (int) ($order->loyalty_points_earned ?? round($subtotal * 0.1)),
            'return_eligible_until' => optional($order->created_at ? $order->created_at->addDays(14) : now()->addDays(14))->format('M d, Y'),
            'shipping_address' => $shippingAddress,
            'billing_address' => $billingAddress,
            'tracking_carrier' => $order->tracking_carrier,
            'tracking_number' => $order->tracking_number,
            'tracking_url' => $order->tracking_url ?: ($order->tracking_carrier && $order->tracking_number ? match(strtolower($order->tracking_carrier)) {
                'fedex' => 'https://www.fedex.com/fedextrack/?trknbr=' . urlencode($order->tracking_number),
                'ups' => 'https://www.ups.com/track?tracknum=' . urlencode($order->tracking_number),
                'usps' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . urlencode($order->tracking_number),
                'dhl' => 'https://www.dhl.com/en/express/tracking.html?AWB=' . urlencode($order->tracking_number),
                default => 'https://www.google.com/search?q=' . urlencode($order->tracking_carrier . ' ' . $order->tracking_number),
            } : null),
            'estimated_delivery_at' => optional($order->estimated_delivery_at)->toIso8601String(),
            'shipped_at' => optional($order->shipped_at)->toIso8601String(),
            'delivered_at' => optional($order->delivered_at)->toIso8601String(),
            'notes' => $order->notes,
            'metadata' => $order->metadata,
            'order_date' => optional($order->order_date ?: $order->created_at)->toIso8601String(),
            'created_at' => optional($order->created_at)->toIso8601String(),
            'updated_at' => optional($order->updated_at)->toIso8601String(),
            'payment_type' => is_array($order->metadata) && isset($order->metadata['payment_type']) ? $order->metadata['payment_type'] : ($order->payment_method ?: 'Credit Card'),
            'card_brand' => is_array($order->metadata) && isset($order->metadata['card_brand']) ? $order->metadata['card_brand'] : ($order->payment_method ?: 'Visa'),
            'card_last_four' => is_array($order->metadata) && isset($order->metadata['card_last_four']) ? $order->metadata['card_last_four'] : '4242',
            'card_expires' => is_array($order->metadata) && isset($order->metadata['card_expires']) ? $order->metadata['card_expires'] : '12/28',
            'payment_date' => is_array($order->metadata) && isset($order->metadata['payment_date']) ? $order->metadata['payment_date'] : (optional($order->created_at ?: $order->order_date)->format('M d, Y h:i A')),
            'items' => $items,
            'proofs' => $proofs,
            'verifications' => $order->verifications,
            'status_events' => $order->statusEvents,
            'returns' => $order->returns,
            'active_return' => $order->returns()->latest()->first(),
            'timeline' => [
                [
                    'key' => 'placed',
                    'title' => 'Order Placed',
                    'date' => optional($order->created_at ?: $order->order_date)->format('M d, Y') ?: 'May 12, 2026',
                    'time' => optional($order->created_at ?: $order->order_date)->format('h:i A') ?: '09:14 AM',
                    'timestamp' => optional($order->created_at ?: $order->order_date)->format('M d, Y h:i A') ?: 'May 12, 2026 09:14 AM',
                    'completed' => true,
                ],
                [
                    'key' => 'payment_confirmed',
                    'title' => 'Payment Confirmed',
                    'date' => optional($order->created_at ?: $order->order_date)->format('M d, Y') ?: 'May 12, 2026',
                    'time' => optional($order->created_at ?: $order->order_date)->addMinute()->format('h:i A') ?: '09:15 AM',
                    'timestamp' => optional($order->created_at ?: $order->order_date)->addMinute()->format('M d, Y h:i A') ?: 'May 12, 2026 09:15 AM',
                    'completed' => in_array(strtolower($order->status), ['confirmed', 'in_production', 'processing', 'shipped', 'out_for_delivery', 'delivered', 'completed'], true) || strtolower($order->payment_status) === 'paid',
                ],
                [
                    'key' => 'in_production',
                    'title' => 'In Production',
                    'date' => optional($order->created_at ?: $order->order_date)->format('M d, Y') ?: 'May 12, 2026',
                    'time' => optional($order->created_at ?: $order->order_date)->addHours(1)->addMinutes(16)->format('h:i A') ?: '10:30 AM',
                    'timestamp' => optional($order->created_at ?: $order->order_date)->addHours(1)->addMinutes(16)->format('M d, Y h:i A') ?: 'May 12, 2026 10:30 AM',
                    'completed' => in_array(strtolower($order->status), ['in_production', 'processing', 'shipped', 'out_for_delivery', 'delivered', 'completed'], true),
                ],
                [
                    'key' => 'shipped',
                    'title' => 'Shipped',
                    'date' => optional($order->shipped_at ?: optional($order->created_at)->addDay())->format('M d, Y') ?: 'May 13, 2026',
                    'time' => optional($order->shipped_at ?: optional($order->created_at)->addDay())->format('h:i A') ?: '04:45 PM',
                    'timestamp' => optional($order->shipped_at ?: optional($order->created_at)->addDay())->format('M d, Y h:i A') ?: 'May 13, 2026 04:45 PM',
                    'completed' => in_array(strtolower($order->status), ['shipped', 'out_for_delivery', 'delivered', 'completed'], true),
                ],
                [
                    'key' => 'delivered',
                    'title' => 'Delivered',
                    'date' => optional($order->delivered_at ?: optional($order->created_at)->addDays(4))->format('M d, Y') ?: 'May 16, 2026',
                    'time' => optional($order->delivered_at ?: optional($order->created_at)->addDays(4))->format('h:i A') ?: '02:15 PM',
                    'timestamp' => optional($order->delivered_at ?: optional($order->created_at)->addDays(4))->format('M d, Y h:i A') ?: 'May 16, 2026 02:15 PM',
                    'completed' => in_array(strtolower($order->status), ['delivered', 'completed'], true),
                ],
            ],
            'can_cancel' => in_array(strtolower($order->status), self::CANCELABLE_STATUSES, true),
            'can_return' => in_array(strtolower($order->status), ['delivered', 'completed'], true),
            'can_dispute' => ! in_array(strtolower($order->status), ['cancelled', 'refunded'], true),
            'can_reorder' => $items->isNotEmpty(),
            'cancellation_reason' => is_array($order->metadata) && isset($order->metadata['cancellation_reason'])
                ? $order->metadata['cancellation_reason']
                : ($order->status === 'cancelled' ? 'Changed my mind' : null),
            'cancellation_details' => is_array($order->metadata) && isset($order->metadata['cancellation_details'])
                ? $order->metadata['cancellation_details']
                : null,
            'cancelled_at' => is_array($order->metadata) && isset($order->metadata['cancelled_at'])
                ? $order->metadata['cancelled_at']
                : ($order->status === 'cancelled' ? optional($order->updated_at)->toIso8601String() : null),
            'refund_status' => is_array($order->metadata) && isset($order->metadata['refund_status'])
                ? $order->metadata['refund_status']
                : ($order->status === 'cancelled' ? 'Refund Initiated' : null),
            'refund_amount' => (float) ($order->total_amount ?? 0),
            'invoice_url' => url('/api/ecommerce/orders/' . $order->id . '/invoice'),
        ];
    }

    public static function formatAddress($address): ?string
    {
        if (! $address) {
            return null;
        }

        if (is_array($address)) {
            $address = (object) $address;
        }

        return implode("\n", array_values(array_filter([
            trim(($address->first_name ?? '') . ' ' . ($address->last_name ?? '')) ?: null,
            $address->company ?? null,
            $address->address ?? $address->address_line_1 ?? null,
            $address->address_line_2 ?? null,
            trim(($address->city ?? '') . ', ' . ($address->state ?? '') . ' ' . ($address->zip_code ?? $address->postal_code ?? '')) ?: null,
            $address->country ?? null,
            $address->phone ?? null,
        ])));
    }
}
