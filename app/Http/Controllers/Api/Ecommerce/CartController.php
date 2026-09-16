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
        $quantity = (int) $request->quantity;
        $unitPrice = $product
            ? (float) ($product->sale_price ?? $product->price ?? 0)
            : (float) ($request->input('unit_price', 0));

        $options = $request->input('options', $request->input('attributes', []));
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
            $cartItem->update([
                'quantity' => $nextQuantity,
                'unit_price' => $unitPrice,
                'total_price' => round($unitPrice * $nextQuantity, 2),
            ]);
        } else {
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
        $cartItem->update([
            'quantity' => $quantity,
            'total_price' => round((float) $cartItem->unit_price * $quantity, 2),
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
