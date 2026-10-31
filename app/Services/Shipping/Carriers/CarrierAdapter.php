<?php

namespace App\Services\Shipping\Carriers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Base class for live carrier rate adapters (USPS, UPS, FedEx, DHL).
 *
 * Every adapter:
 *  - authenticates with the credentials the admin uploaded (OAuth token cached until expiry),
 *  - maps our normalized package into the carrier's rate request,
 *  - returns normalized rates and NEVER throws to the customer (errors are logged, no rates returned).
 *
 * Normalized rate request ($req):
 *  origin:      [zip, city, state, country]
 *  destination: [zip, city, state, country, residential(bool)]
 *  weightLbs, length, width, height (inches), declaredValue
 *  services:    list of admin-enabled service rows for this carrier (id, name, code, type, estDelivery)
 *
 * Normalized rate: serviceId, carrierId, name, code, type, estDelivery, price, currency, negotiated
 */
abstract class CarrierAdapter
{
    protected const TIMEOUT_SECONDS = 8;

    /** Last error message (shown by Test Connection / logged). */
    public ?string $lastError = null;

    /**
     * @param array $carrier Stored carrier row (id, environment, ...)
     * @param array $creds   Decrypted credential values keyed by field key
     */
    public function __construct(protected array $carrier, protected array $creds)
    {
    }

    abstract public function carrierId(): string;

    /** Verify credentials against the carrier. Returns true when authentication succeeds. */
    abstract public function testConnection(): bool;

    /** @return array<int,array> normalized rates (empty when carrier cannot ship / on error) */
    abstract public function getRates(array $req): array;

    protected function isProduction(): bool
    {
        $env = strtolower((string) ($this->creds['environment'] ?? $this->carrier['environment'] ?? 'production'));
        return $env === 'production' || $env === 'live';
    }

    protected function cred(string $key): string
    {
        return trim((string) ($this->creds[$key] ?? ''));
    }

    protected function http(): PendingRequest
    {
        return Http::timeout(self::TIMEOUT_SECONDS)->connectTimeout(5)->acceptJson();
    }

    protected function tokenCacheKey(): string
    {
        return 'shipping_token_' . $this->carrierId() . '_' . ($this->isProduction() ? 'prod' : 'sbx') . '_' . md5(json_encode($this->creds));
    }

    protected function cacheToken(string $token, int $expiresIn): void
    {
        Cache::put($this->tokenCacheKey(), $token, max(60, $expiresIn - 120));
    }

    protected function cachedToken(): ?string
    {
        return Cache::get($this->tokenCacheKey());
    }

    protected function forgetToken(): void
    {
        Cache::forget($this->tokenCacheKey());
    }

    protected function fail(string $message, array $context = []): array
    {
        $this->lastError = $message;
        Log::warning('[Shipping:' . $this->carrierId() . '] ' . $message, $context);
        return [];
    }

    /** Pull a readable error from a failed carrier response (never includes credentials). */
    protected function errorFromResponse($response): string
    {
        $json = $response->json();
        $msg = null;
        if (is_array($json)) {
            $msg = $json['error_description']
                ?? $json['message']
                ?? $json['detail']
                ?? ($json['errors'][0]['message'] ?? null)
                ?? ($json['error']['message'] ?? null)
                ?? ($json['response']['errors'][0]['message'] ?? null)
                ?? (is_string($json['error'] ?? null) ? $json['error'] : null);
        }
        return 'HTTP ' . $response->status() . ($msg ? ': ' . (is_string($msg) ? $msg : json_encode($msg)) : '');
    }

    protected function normalize(array $svc, float $price, string $currency = 'USD', ?string $est = null, bool $negotiated = false): array
    {
        return [
            'serviceId' => $svc['id'],
            'carrierId' => $this->carrierId(),
            'name' => $svc['name'],
            'code' => $svc['code'],
            'type' => $svc['type'] ?? 'Standard',
            'estDelivery' => $est ?: ($svc['estDelivery'] ?? ''),
            'price' => number_format(round($price, 2), 2, '.', ''),
            'currency' => $currency,
            'negotiated' => $negotiated,
        ];
    }
}
