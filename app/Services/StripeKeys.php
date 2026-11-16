<?php

namespace App\Services;

use App\Models\PaymentGateway;
use Illuminate\Support\Facades\Log;

/**
 * Which Stripe keys the store uses (checkout, memberships, saved cards): one rule everywhere.
 *
 *  1. Active "stripe" rows in Admin → Payment Gateways, most recently updated first, whose keys
 *     are real (not placeholders like "sk_live_xxxx" or the seeded sample keys) and whose
 *     publishable and secret keys are both test or both live.
 *  2. Otherwise STRIPE_KEY / STRIPE_SECRET from the server's .env, under the same checks.
 *
 * Before this, only the first Stripe row was read; an older or inactive row there made the store
 * fall back to a placeholder .env key and Stripe answered "Invalid API Key provided".
 */
class StripeKeys
{
    /** @return array{secret: string, publishable: string, source: string, mode: ?string} */
    public static function resolve(): array
    {
        try {
            $rows = PaymentGateway::where('provider', 'stripe')
                ->where('is_active', true)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->get();
        } catch (\Throwable) {
            $rows = collect();
        }

        $candidates = [];
        foreach ($rows as $row) {
            if ($pair = self::pair((string) $row->secret_key, (string) $row->public_key, "payment_gateway #{$row->id}")) {
                $candidates[] = $pair;
            }
        }
        if ($pair = self::pair((string) config('services.stripe.secret'), (string) config('services.stripe.key'), '.env')) {
            $candidates[] = $pair;
        }

        // Card forms need both keys: prefer a complete pair, else a secret key alone (server-side charges).
        foreach ($candidates as $pair) {
            if ($pair['publishable'] !== '') {
                return $pair;
            }
        }

        return $candidates[0] ?? ['secret' => '', 'publishable' => '', 'source' => 'none', 'mode' => null];
    }

    public static function secret(): string
    {
        return self::resolve()['secret'];
    }

    public static function publishable(): string
    {
        return self::resolve()['publishable'];
    }

    /** A real-looking key: right prefix, no placeholder text. */
    public static function looksReal(string $key, string $prefix): bool
    {
        $key = trim($key);
        if (! str_starts_with($key, $prefix . '_test_') && ! str_starts_with($key, $prefix . '_live_')) {
            return false;
        }
        $lower = strtolower($key);
        foreach (['xxxx', 'sample', '...', '•', '*', 'your_', 'changeme', 'placeholder'] as $marker) {
            if (str_contains($lower, $marker)) {
                return false;
            }
        }

        return strlen($key) >= 20;
    }

    /** @return array{secret: string, publishable: string, source: string, mode: string}|null */
    private static function pair(string $secret, string $publishable, string $source): ?array
    {
        $secret = trim($secret);
        $publishable = trim($publishable);
        // Secret (sk_) or restricted (rk_) key.
        $secretPrefix = str_starts_with($secret, 'rk_') ? 'rk' : 'sk';
        if (! self::looksReal($secret, $secretPrefix)) {
            return null;
        }

        $mode = str_starts_with($secret, $secretPrefix . '_test_') ? 'test' : 'live';
        if ($publishable !== '') {
            if (! self::looksReal($publishable, 'pk')) {
                Log::warning("Stripe keys from {$source}: the publishable key is missing or a placeholder; these keys are skipped.");

                return null;
            }
            $pkMode = str_starts_with($publishable, 'pk_test_') ? 'test' : 'live';
            if ($pkMode !== $mode) {
                Log::warning("Stripe keys from {$source}: publishable key is {$pkMode} but secret key is {$mode}; these keys are skipped.");

                return null;
            }
        }

        return ['secret' => $secret, 'publishable' => $publishable, 'source' => $source, 'mode' => $mode];
    }
}
