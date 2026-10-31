<?php

namespace App\Services\Shipping\Carriers;

/**
 * UPS (developer.ups.com) - OAuth 2.0 client credentials + Rating API (Shop).
 * Credentials: accessKey (Client ID), secretKey (Client Secret), accountNumber (Shipper Number).
 */
class UpsAdapter extends CarrierAdapter
{
    /** Our service code => UPS service code */
    private const SERVICE_CODE = [
        'UPS_GROUND' => '03',
        'UPS_2DA' => '02',
        'UPS_NDA' => '01',
        'UPS_3DS' => '12',
    ];

    public function carrierId(): string
    {
        return 'ups';
    }

    private function base(): string
    {
        return $this->isProduction() ? 'https://onlinetools.ups.com' : 'https://wwwcie.ups.com';
    }

    private function token(bool $fresh = false): ?string
    {
        if (!$fresh && ($t = $this->cachedToken())) {
            return $t;
        }
        $id = $this->cred('accessKey');
        $secret = $this->cred('secretKey');
        if ($id === '' || $secret === '') {
            $this->lastError = 'Client ID and Client Secret are required.';
            return null;
        }
        $headers = [];
        if ($this->cred('accountNumber') !== '') {
            $headers['x-merchant-id'] = $this->cred('accountNumber');
        }
        $res = $this->http()->withBasicAuth($id, $secret)->withHeaders($headers)->asForm()
            ->post($this->base() . '/security/v1/oauth/token', ['grant_type' => 'client_credentials']);
        if (!$res->successful() || empty($res->json('access_token'))) {
            $this->lastError = 'Authentication failed (' . $this->errorFromResponse($res) . ')';
            return null;
        }
        $this->cacheToken($res->json('access_token'), (int) ($res->json('expires_in') ?? 14400));
        return $res->json('access_token');
    }

    public function testConnection(): bool
    {
        try {
            $this->forgetToken();
            return $this->token(true) !== null;
        } catch (\Throwable $e) {
            $this->lastError = 'Could not reach UPS: ' . $e->getMessage();
            return false;
        }
    }

    public function getRates(array $req): array
    {
        $rates = [];
        try {
            $token = $this->token();
            if (!$token) {
                return $this->fail($this->lastError ?? 'UPS authentication failed');
            }
            $account = $this->cred('accountNumber');
            $shipper = [
                'Name' => 'Shipper',
                'Address' => [
                    'PostalCode' => $req['origin']['zip'],
                    'City' => $req['origin']['city'] ?? '',
                    'StateProvinceCode' => $req['origin']['state'] ?? '',
                    'CountryCode' => $req['origin']['country'] ?? 'US',
                ],
            ];
            if ($account !== '') {
                $shipper['ShipperNumber'] = $account;
            }
            $dest = [
                'Name' => 'Recipient',
                'Address' => [
                    'PostalCode' => $req['destination']['zip'],
                    'City' => $req['destination']['city'] ?? '',
                    'StateProvinceCode' => $req['destination']['state'] ?? '',
                    'CountryCode' => $req['destination']['country'] ?? 'US',
                ],
            ];
            if (!empty($req['destination']['residential'])) {
                $dest['Address']['ResidentialAddressIndicator'] = '';
            }
            $shipment = [
                'Shipper' => $shipper,
                'ShipTo' => $dest,
                'ShipFrom' => $shipper,
                'Package' => [[
                    'PackagingType' => ['Code' => '02'],
                    'Dimensions' => [
                        'UnitOfMeasurement' => ['Code' => 'IN'],
                        'Length' => (string) max(1, round($req['length'])),
                        'Width' => (string) max(1, round($req['width'])),
                        'Height' => (string) max(1, round($req['height'])),
                    ],
                    'PackageWeight' => [
                        'UnitOfMeasurement' => ['Code' => 'LBS'],
                        'Weight' => (string) max(0.1, round($req['weightLbs'], 1)),
                    ],
                ]],
            ];
            if ($account !== '') {
                $shipment['ShipmentRatingOptions'] = ['NegotiatedRatesIndicator' => ''];
            }
            $body = ['RateRequest' => ['Request' => ['TransactionReference' => ['CustomerContext' => 'mecarvi']], 'Shipment' => $shipment]];
            $url = $this->base() . '/api/rating/v2409/Shop';

            $res = $this->http()->withToken($token)->asJson()->post($url, $body);
            if ($res->status() === 401) {
                $this->forgetToken();
                $token = $this->token(true);
                if (!$token) {
                    return $this->fail($this->lastError ?? 'UPS authentication failed');
                }
                $res = $this->http()->withToken($token)->asJson()->post($url, $body);
            }
            if (!$res->successful()) {
                return $this->fail('Rate request failed (' . $this->errorFromResponse($res) . ')');
            }

            $rated = $res->json('RateResponse.RatedShipment') ?? [];
            if (isset($rated['Service'])) {
                $rated = [$rated]; // single result is returned as an object
            }
            $byCode = [];
            foreach ($rated as $r) {
                $byCode[$r['Service']['Code'] ?? ''] = $r;
            }
            foreach ($req['services'] as $svc) {
                $code = self::SERVICE_CODE[$svc['code']] ?? null;
                if (!$code || !isset($byCode[$code])) {
                    continue; // carrier cannot ship with this service to that address
                }
                $r = $byCode[$code];
                $negotiated = $r['NegotiatedRateCharges']['TotalCharge']['MonetaryValue'] ?? null;
                $price = $negotiated ?? ($r['TotalCharges']['MonetaryValue'] ?? null);
                if ($price === null) {
                    continue;
                }
                $currency = $r['TotalCharges']['CurrencyCode'] ?? 'USD';
                $days = $r['GuaranteedDelivery']['BusinessDaysInTransit'] ?? null;
                $rates[] = $this->normalize($svc, (float) $price, $currency, $days ? $days . ' business days' : null, $negotiated !== null);
            }
        } catch (\Throwable $e) {
            return $this->fail('UPS error: ' . $e->getMessage());
        }
        return $rates;
    }
}
