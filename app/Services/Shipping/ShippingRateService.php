<?php

namespace App\Services\Shipping;

use App\Services\Shipping\Carriers\CarrierAdapter;
use App\Services\Shipping\Carriers\DhlAdapter;
use App\Services\Shipping\Carriers\FedexAdapter;
use App\Services\Shipping\Carriers\UpsAdapter;
use App\Services\Shipping\Carriers\UspsAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Real-time carrier rate shopping using the credentials the admin uploaded.
 * Only carriers that are enabled AND have credentials are called; only services the admin
 * switched on are returned; only carriers that can actually ship to the address return rates.
 */
class ShippingRateService
{
    /** Credential field keys that are secrets: encrypted at rest and never returned to the browser. */
    public const SECRET_KEYS = ['password', 'secretKey', 'apiSecret', 'clientSecret'];

    private const ENC_PREFIX = 'enc:';
    private const RATE_TOKEN_TTL_MINUTES = 30;

    public static function isSecretKey(string $key): bool
    {
        return in_array($key, self::SECRET_KEYS, true);
    }

    public static function encryptSecret(string $value): string
    {
        if ($value === '' || str_starts_with($value, self::ENC_PREFIX)) {
            return $value;
        }
        return self::ENC_PREFIX . Crypt::encryptString($value);
    }

    public static function decryptSecret(string $value): string
    {
        if (!str_starts_with($value, self::ENC_PREFIX)) {
            return $value; // legacy plaintext
        }
        try {
            return Crypt::decryptString(substr($value, strlen(self::ENC_PREFIX)));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** Decrypted credential values for a stored carrier row. */
    public static function credentials(array $carrier): array
    {
        $out = [];
        foreach (($carrier['values'] ?? []) as $key => $value) {
            $value = is_string($value) ? $value : (string) $value;
            $out[$key] = self::isSecretKey($key) ? self::decryptSecret($value) : $value;
        }
        $out['environment'] = $out['environment'] ?? ($carrier['environment'] ?? 'Production');
        return $out;
    }

    public static function adapterFor(array $carrier): ?CarrierAdapter
    {
        $creds = self::credentials($carrier);
        return match ($carrier['id'] ?? '') {
            'usps' => new UspsAdapter($carrier, $creds),
            'ups' => new UpsAdapter($carrier, $creds),
            'fedex' => new FedexAdapter($carrier, $creds),
            'dhl' => new DhlAdapter($carrier, $creds),
            default => null,
        };
    }

    /** Required credential keys per carrier (a carrier without them is skipped). */
    private static function requiredKeys(string $carrierId): array
    {
        return match ($carrierId) {
            'usps' => ['clientId', 'clientSecret'],
            'ups' => ['accessKey', 'secretKey'],
            'fedex' => ['apiKey', 'apiSecret', 'accountNumber'],
            'dhl' => ['apiKey', 'apiSecret', 'accountNumber'],
            default => [],
        };
    }

    private static function hasCredentials(array $carrier): bool
    {
        $creds = self::credentials($carrier);
        foreach (self::requiredKeys($carrier['id'] ?? '') as $key) {
            if (trim((string) ($creds[$key] ?? '')) === '') {
                return false;
            }
        }
        return true;
    }

    public static function normalizeCountry(?string $country): string
    {
        $c = strtoupper(trim((string) $country));
        if ($c === '' || in_array($c, ['US', 'USA', 'UNITED STATES', 'UNITED STATES OF AMERICA'], true)) {
            return 'US';
        }
        $map = ['CANADA' => 'CA', 'UNITED KINGDOM' => 'GB', 'UK' => 'GB', 'AUSTRALIA' => 'AU', 'MEXICO' => 'MX'];
        if (isset($map[$c])) {
            return $map[$c];
        }
        return strlen($c) === 2 ? $c : 'US';
    }

    /**
     * Quote one shipment group (one destination address, one package).
     *
     * @param array $stored  Stored shipping settings (carriers, services, locations, rateSettings)
     * @param array $params  weightLbs, length, width, height, destination[zip,city,state,country,residential], originLocationId?
     * @return array{rates: array, errors: array, carriersQueried: int}
     */
    public function quote(array $stored, array $params): array
    {
        $origin = $this->resolveOrigin($stored['locations'] ?? [], $params['originLocationId'] ?? null);
        $dest = $params['destination'];
        $dest['country'] = self::normalizeCountry($dest['country'] ?? 'US');

        if ($origin === null || empty($origin['zip']) || empty($dest['zip'])) {
            return ['rates' => [], 'errors' => ['request' => 'Origin or destination postal code is missing.'], 'carriersQueried' => 0];
        }

        $cacheKey = 'shipping_quote_' . md5(json_encode([$origin, $dest, $params['weightLbs'], $params['length'], $params['width'], $params['height'], $this->fingerprint($stored)]));
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $req = [
            'origin' => [
                'zip' => $origin['zip'], 'city' => $origin['city'] ?? '', 'state' => $origin['state'] ?? '',
                'country' => self::normalizeCountry($origin['country'] ?? 'US'),
            ],
            'destination' => $dest,
            'weightLbs' => (float) $params['weightLbs'],
            'length' => (float) $params['length'],
            'width' => (float) $params['width'],
            'height' => (float) $params['height'],
        ];

        $rates = [];
        $errors = [];
        $queried = 0;
        foreach (($stored['carriers'] ?? []) as $carrier) {
            if (empty($carrier['enabled']) || !self::hasCredentials($carrier)) {
                continue;
            }
            $services = array_values(array_filter(
                $stored['services'] ?? [],
                fn($s) => ($s['carrierId'] ?? '') === $carrier['id'] && !empty($s['enabled'])
            ));
            if (empty($services)) {
                continue;
            }
            $adapter = self::adapterFor($carrier);
            if (!$adapter) {
                continue;
            }
            $queried++;
            $got = $adapter->getRates($req + ['services' => $services]);
            if (empty($got) && $adapter->lastError) {
                $errors[$carrier['id']] = $adapter->lastError;
            }
            array_push($rates, ...$got);
        }

        $rates = $this->applyAdminRules($rates, $stored['rateSettings'] ?? []);
        usort($rates, fn($a, $b) => (float) $a['price'] <=> (float) $b['price']);

        $destKey = $dest['zip'] . '|' . $dest['country'];
        foreach ($rates as &$r) {
            $r['rateToken'] = Crypt::encryptString(json_encode([
                'serviceId' => $r['serviceId'], 'carrierId' => $r['carrierId'], 'price' => $r['price'],
                'dest' => $destKey, 'exp' => now()->addMinutes(self::RATE_TOKEN_TTL_MINUTES)->timestamp,
            ]));
        }
        unset($r);

        $result = ['rates' => $rates, 'errors' => $errors, 'carriersQueried' => $queried];
        if (!empty($rates)) {
            Cache::put($cacheKey, $result, 300); // short cache; never cache "no rates" / errors
        }
        return $result;
    }

    /** Validate a rate token issued by quote() (used when the order is placed). Returns the verified rate or null. */
    public static function verifyRateToken(string $token, string $zip, string $country = 'US'): ?array
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($data) || ($data['exp'] ?? 0) < now()->timestamp) {
            return null;
        }
        if (($data['dest'] ?? '') !== $zip . '|' . self::normalizeCountry($country)) {
            return null;
        }
        return $data;
    }

    private function resolveOrigin(array $locations, ?string $id): ?array
    {
        $active = array_values(array_filter($locations, fn($l) => ($l['status'] ?? 'Active') === 'Active'));
        if ($id) {
            foreach ($active as $l) {
                if ((string) $l['id'] === (string) $id) {
                    return $l;
                }
            }
        }
        foreach ($active as $l) {
            if (!empty($l['isDefault'])) {
                return $l;
            }
        }
        return $active[0] ?? null;
    }

    /** Admin markup + handling fee (settings from Shipping Options > Rate settings). */
    private function applyAdminRules(array $rates, array $settings): array
    {
        $markup = (float) ($settings['markupValue'] ?? 0);
        $type = (string) ($settings['markupType'] ?? '%');
        $handling = (float) ($settings['handlingFee'] ?? 0);
        foreach ($rates as &$r) {
            $price = (float) $r['price'];
            $price = $type === '%' ? $price * (1 + $markup / 100) : $price + $markup;
            $price += $handling;
            $r['price'] = number_format(round($price, 2), 2, '.', '');
        }
        unset($r);
        return $rates;
    }

    /** Cache fingerprint of settings that influence quotes (no secrets). */
    private function fingerprint(array $stored): string
    {
        $parts = [];
        foreach (($stored['carriers'] ?? []) as $c) {
            $parts[] = [$c['id'] ?? '', !empty($c['enabled']), $c['environment'] ?? ''];
        }
        foreach (($stored['services'] ?? []) as $s) {
            $parts[] = [$s['id'] ?? '', !empty($s['enabled'])];
        }
        $parts[] = $stored['rateSettings'] ?? [];
        return md5(json_encode($parts));
    }
}
