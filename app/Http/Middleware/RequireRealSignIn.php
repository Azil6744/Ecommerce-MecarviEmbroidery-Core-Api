<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * On a local machine CentralAuthTokenMiddleware signs requests without a valid token in as a
 * development admin, so admin screens work without Central. Buying or changing a membership
 * must never run on that stand-in account: it would skip the PIN, the payment and Central.
 */
class RequireRealSignIn
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->attributes->get('central_auth_dev_fallback') === true) {
            return response()->json([
                'success' => false,
                'message' => 'Please sign in to continue.',
            ], 401);
        }

        return $next($request);
    }
}
