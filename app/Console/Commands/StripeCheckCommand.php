<?php

namespace App\Console\Commands;

use App\Models\PaymentGateway;
use App\Services\StripeKeys;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * php artisan stripe:check
 *
 * Shows which Stripe keys the store will use (and why the others are skipped), then asks Stripe
 * whether the chosen secret key works. Keys are never printed in full.
 */
class StripeCheckCommand extends Command
{
    protected $signature = 'stripe:check';

    protected $description = 'Show which Stripe keys checkout and memberships use, and test them with Stripe';

    public function handle(): int
    {
        $this->line('<options=bold>Admin → Payment Gateways (provider "stripe")</>');
        $rows = PaymentGateway::where('provider', 'stripe')->orderByDesc('updated_at')->get();
        if ($rows->isEmpty()) {
            $this->line('  none');
        }
        foreach ($rows as $row) {
            $this->line(sprintf(
                '  #%d %-20s active=%s  secret=%s  publishable=%s  updated=%s',
                $row->id,
                mb_strimwidth((string) $row->name, 0, 20, '…'),
                $row->is_active ? 'yes' : 'NO',
                $this->describe((string) $row->secret_key, str_starts_with(trim((string) $row->secret_key), 'rk_') ? 'rk' : 'sk'),
                $this->describe((string) $row->public_key, 'pk'),
                $row->updated_at
            ));
        }

        $this->newLine();
        $this->line('<options=bold>.env</>');
        $envSecret = (string) config('services.stripe.secret');
        $this->line('  STRIPE_SECRET=' . $this->describe($envSecret, str_starts_with(trim($envSecret), 'rk_') ? 'rk' : 'sk'));
        $this->line('  STRIPE_KEY=' . $this->describe((string) config('services.stripe.key'), 'pk'));

        $keys = StripeKeys::resolve();
        $this->newLine();
        if ($keys['secret'] === '') {
            $this->error('No usable Stripe keys: add real test or live keys to an ACTIVE Stripe row in Payment Gateways (or to .env).');

            return self::FAILURE;
        }
        $this->info("Using: {$keys['source']} ({$keys['mode']} mode)");
        if ($keys['publishable'] === '') {
            $this->warn('No publishable key with these keys: card forms cannot load. Add the matching pk_ key.');
        }

        $response = Http::withBasicAuth($keys['secret'], '')->timeout(15)->get('https://api.stripe.com/v1/balance');
        if ($response->successful()) {
            $this->info('Stripe accepted the secret key.');

            return self::SUCCESS;
        }
        $this->error('Stripe rejected the secret key: ' . ($response->json('error.message') ?: 'HTTP ' . $response->status()));

        return self::FAILURE;
    }

    private function describe(string $key, string $prefix): string
    {
        $key = trim($key);
        if ($key === '') {
            return 'empty';
        }
        $mode = str_starts_with($key, $prefix . '_test_') ? 'test' : (str_starts_with($key, $prefix . '_live_') ? 'live' : 'unknown type');
        $ok = StripeKeys::looksReal($key, $prefix) ? 'ok' : 'PLACEHOLDER/INVALID';

        return "{$mode}, …" . substr($key, -4) . " ({$ok})";
    }
}
