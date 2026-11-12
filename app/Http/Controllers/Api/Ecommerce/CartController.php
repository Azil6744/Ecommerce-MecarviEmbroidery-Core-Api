<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\EcommerceCart;
use App\Models\EcommerceCartItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CartController extends Controller
{
    private function activeCartFor(Request $request): EcommerceCart
    {
        $user = $request->user();

        if ($user) {
            $cart = EcommerceCart::firstOrCreate(
                ['user_id' => $user->id, 'status' => 'active'],
                ['total_amount' => 0]
            );

            // Merge guest cart if present
            $sessionId = $request->header('X-Session-ID') ?: $request->header('X-Guest-Session-ID');
            if ($sessionId) {
                $guestCart = EcommerceCart::where('session_id', $sessionId)
                    ->where('status', 'active')
                    ->whereNull('user_id')
                    ->first();
                if ($guestCart && $guestCart->id !== $cart->id) {
                    foreach ($guestCart->items as $gItem) {
                        $existing = $cart->items()->where('product_id', $gItem->product_id)->first();
                        if ($existing) {
                            $existing->increment('quantity', $gItem->quantity);
                            $existing->update(['total_price' => round($existing->unit_price * $existing->quantity, 2)]);
                        } else {
                            $gItem->update(['ecommerce_cart_id' => $cart->id]);
                        }
                    }
                    $guestCart->delete();
                }
            }

            return $cart;
        }

        $sessionId = $request->header('X-Session-ID') ?: $request->header('X-Guest-Session-ID');
        if (!$sessionId) {
            $sessionId = 'guest_' . Str::random(16);
        }

        return EcommerceCart::firstOrCreate(
            ['session_id' => $sessionId, 'status' => 'active'],
            ['total_amount' => 0]
        );
    }

    private function refreshCartTotals(EcommerceCart $cart): EcommerceCart
    {
        // Prune orphaned cart items where the product no longer exists in the database
        $cart->items()->whereDoesntHave('product')->delete();

        $total = $cart->items()->sum('total_price');
        $cart->forceFill(['total_amount' => $total])->save();

        return $cart->load('items.product');
    }

    /**
     * Get user's cart
     */
    public function index(Request $request)
    {
        $cart = $this->activeCartFor($request);
        return response()->json($this->refreshCartTotals($cart));
    }

    /**
     * Add item to cart
     */
    public function addItem(Request $request)
    {
        $request->validate([
            'product_id' => 'required',
            'quantity' => 'required|integer|min:1',
            'attributes' => 'nullable|array',
            'options' => 'nullable|array',
            'unit_price' => 'nullable|numeric',
        ]);

        $product = Product::find($request->product_id);
        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found or is no longer available.',
            ], 404);
        }
        $quantity = (int) $request->quantity;
        
        $options = $request->input('options', $request->input('attributes', []));
        // options.total_price is the configurator's grand total for the whole quantity being added,
        // so convert it to a per-piece price before storing it as unit_price.
        $customPrice = isset($options['total_price']) && (float) $options['total_price'] > 0
            ? round((float) $options['total_price'] / max(1, $quantity), 4)
            : ($request->filled('unit_price') ? (float) $request->input('unit_price') : null);

        $unitPrice = ($customPrice !== null && $customPrice > 0)
            ? $customPrice
            : ($product ? (float) ($product->sale_price ?? $product->price ?? 0) : (float) ($request->input('unit_price', 0)));
        $cart = $this->activeCartFor($request);

        $normalizedOptions = is_array($options) ? $options : [];
        ksort($normalizedOptions);

        $cartItem = $cart->items()
            ->where('product_id', $request->product_id)
            ->get()
            ->first(function ($item) use ($normalizedOptions) {
                $itemOptions = is_array($item->options) ? $item->options : [];
                ksort($itemOptions);
                return $itemOptions === $normalizedOptions;
            });

        if ($cartItem) {
            $nextQuantity = $cartItem->quantity + $quantity;
            if ($product && !$product->is_digital && strtolower((string) $product->product_type) !== 'quotation' && $product->stock_quantity !== null && (int) $product->stock_quantity < $nextQuantity) {
                return response()->json([
                    'success' => false,
                    'message' => (int) $product->stock_quantity <= 0
                        ? "Sorry, \"{$product->name}\" is out of stock."
                        : "Only {$product->stock_quantity} available in stock for \"{$product->name}\".",
                ], 422);
            }
            $cartItem->update([
                'quantity' => $nextQuantity,
                'unit_price' => $unitPrice,
                'total_price' => round($unitPrice * $nextQuantity, 2),
            ]);
        } else {
            if ($product && !$product->is_digital && strtolower((string) $product->product_type) !== 'quotation' && $product->stock_quantity !== null && (int) $product->stock_quantity < $quantity) {
                return response()->json([
                    'success' => false,
                    'message' => (int) $product->stock_quantity <= 0
                        ? "Sorry, \"{$product->name}\" is out of stock."
                        : "Only {$product->stock_quantity} available in stock for \"{$product->name}\".",
                ], 422);
            }
            $cart->items()->create([
                'product_id' => $request->product_id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => round($unitPrice * $quantity, 2),
                'options' => $options,
            ]);
        }

        return response()->json($this->refreshCartTotals($cart), 201);
    }

    /**
     * Update cart item quantity
     */
    public function updateItem(Request $request, $itemId)
    {
        $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $cart = $this->activeCartFor($request);
        $cartItem = $cart->items()->find($itemId);

        if (!$cartItem) {
            $cartItem = $cart->items()->where('product_id', $itemId)->first();
        }

        if (!$cartItem) {
            return response()->json(['message' => 'Cart item not found'], 404);
        }

        $quantity = (int) $request->quantity;
        $product = $cartItem->product;
        if ($product && !$product->is_digital && strtolower((string) $product->product_type) !== 'quotation' && $product->stock_quantity !== null && (int) $product->stock_quantity < $quantity) {
            return response()->json([
                'success' => false,
                'message' => (int) $product->stock_quantity <= 0
                    ? "Sorry, \"{$product->name}\" is out of stock."
                    : "Only {$product->stock_quantity} available in stock for \"{$product->name}\".",
            ], 422);
        }
        $options = is_array($cartItem->options) ? $cartItem->options : (json_decode($cartItem->options, true) ?: []);
        $tiers = $options['wholesale_tiers'] ?? ($product?->attributes['quantityPricing'] ?? ($product?->attributes['quantity_pricing'] ?? null));
        
        $unitPrice = (float) $cartItem->unit_price;
        if (!empty($tiers) && is_array($tiers)) {
            $matchedTierPrice = null;
            $highestTierPrice = null;
            $maxUpper = -1;

            foreach ($tiers as $tier) {
                if (empty($tier['range']) || !isset($tier['price'])) continue;
                $p = (float) $tier['price'];
                if ($p <= 0) continue;
                
                $rangeStr = (string) $tier['range'];
                $min = 1; $max = PHP_FLOAT_MAX;
                if (preg_match('/(\d+)\s*[-–to]+\s*(\d+)/i', $rangeStr, $m)) {
                    $min = (int) $m[1];
                    $max = (int) $m[2];
                } elseif (preg_match('/(\d+)\s*\+/', $rangeStr, $m)) {
                    $min = (int) $m[1];
                    $max = PHP_FLOAT_MAX;
                } elseif (preg_match('/(\d+)/', $rangeStr, $m)) {
                    $min = (int) $m[1];
                    $max = (int) $m[1];
                }

                if ($quantity >= $min && $quantity <= $max) {
                    $matchedTierPrice = $p;
                    break;
                }

                $upper = $max === PHP_FLOAT_MAX ? $min : $max;
                if ($upper > $maxUpper) {
                    $maxUpper = $upper;
                    if ($quantity >= $min) {
                        $highestTierPrice = $p;
                    }
                }
            }

            $activeTierPrice = $matchedTierPrice ?? $highestTierPrice;
            if ($activeTierPrice !== null) {
                $perItemExtra = isset($options['per_item_extra']) ? (float) $options['per_item_extra'] : 0;
                $wholeOrderExtra = isset($options['whole_order_extra']) ? (float) $options['whole_order_extra'] : 0;
                if ($perItemExtra > 0 || $wholeOrderExtra > 0) {
                    $unitPrice = round($activeTierPrice + $perItemExtra + ($wholeOrderExtra / max(1, $quantity)), 4);
                } else {
                    $unitPrice = $activeTierPrice;
                }
            }
        }

        $cartItem->update([
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_price' => round($unitPrice * $quantity, 2),
        ]);

        $this->refreshCartTotals($cart);

        return response()->json($cartItem->load('product'));
    }

    /**
     * Remove item from cart
     */
    public function removeItem(Request $request, $itemId)
    {
        $cart = $this->activeCartFor($request);
        $cartItem = $cart->items()->find($itemId);

        if (!$cartItem) {
            $cartItem = $cart->items()->where('product_id', $itemId)->first();
        }

        if ($cartItem) {
            $cartItem->delete();
        }

        return response()->json($this->refreshCartTotals($cart));
    }

    /**
     * Clear entire cart
     */
    public function clear(Request $request)
    {
        $cart = $this->activeCartFor($request);
        $cart->items()->delete();
        $cart->forceFill(['total_amount' => 0])->save();

        return response()->json(['message' => 'Cart cleared successfully']);
    }
}
