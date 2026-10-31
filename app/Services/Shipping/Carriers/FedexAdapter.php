<?php

namespace App\Services\Shipping\Carriers;

/**
 * FedEx (developer.fedex.com) - OAuth 2.0 client credentials + Rates and Transit Times API.
 * Credentials: apiKey (Client ID), apiSecret (Client Secret), accountNumber.
 */
class FedexAdapter extends CarrierAdapter
{
    /** Our service code => FedEx serviceType */
    private const SERVICE_TYPE = [
        'FEDEX_HOME' => 'GROUND_HOME_DELIVERY',
        'FEDEX_GROUND' => 'FEDEX_GROUND',
        'FEDEX_2DAY' => 'FEDEX_2_DAY',
        'FEDEX_OVERNIGHT' => 'PRIORITY_OVERNIGHT',
    ];

    public function carrierId(): string
    {
        return 'fedex';
    }

    private function base(): string
    {
        return $this->isProduction() ? 'https://apis.fedex.com' : 'https://apis-sandbox.fedex.com';
    }

    private function token(bool $fresh = false): ?string
    {
        if (!$fresh && ($t = $this->cachedToken())) {
            return $t;
        }
        $id = $this->cred('apiKey');
        $secret = $this->cred('apiSecret');
        if ($id === '' || $secret === '') {
            $this->lastError = 'API Key and API Secret are required.';
            return null;
        }
        $res = $this->http()->asForm()->post($this->base() . '/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $id,
            'client_secret' => $secret,
        ]);
        if (!$res->successful() || empty($res->json('access_token'))) {
            $this->lastError = 'Authentication failed (' . $this->errorFromResponse($res) . ')';
            return null;
        }
        $this->cacheToken($res->json('access_token'), (int) ($res->json('expires_in') ?? 3600));
        return $res->json('access_token');
    }

    public function testConnection(): bool
    {
        try {
            $this->forgetToken();
            return $this->token(true) !== null;
        } catch (\Throwable $e) {
            $this->lastError = 'Could not reach FedEx: ' . $e->getMessage();
            return false;
        }
    }

    public function getRates(array $req): array
    {
        $rates = [];
        try {
            $token = $this->token();
            if (!$token) {
                return $this->fail($this->lastError ?? 'FedEx authentication failed');
            }
            $body = [
                'accountNumber' => ['value' => $this->cred('accountNumber')],
                'requestedShipment' => [
                    'shipper' => ['address' => [
                        'postalCode' => $req['origin']['zip'],
                        'stateOrProvinceCode' => $req['origin']['state'] ?? '',
                        'countryCode' => $req['origin']['country'] ?? 'US',
                    ]],
                    'recipient' => ['address' => [
                        'postalCode' => $req['destination']['zip'],
                        'stateOrProvinceCode' => $req['destination']['state'] ?? '',
                        'countryCode' => $req['destination']['country'] ?? 'US',
                        'residential' => !empty($req['destination']['residential']),
                    ]],
                    'pickupType' => 'DROPOFF_AT_FEDEX_LOCATION',
                    'rateRequestType' => ['ACCOUNT', 'LIST'],
                    'requestedPackageLineItems' => [[
                        'weight' => ['units' => 'LB', 'value' => max(0.1, round($req['weightLbs'], 1))],
                        'dimensions' => [
                            'length' => (int) max(1, round($req['length'])),
                            'width' => (int) max(1, round($req['width'])),
                            'height' => (int) max(1, round($req['height'])),
                            'units' => 'IN',
                        ],
                    ]],
                ],
            ];
            $url = $this->base() . '/rate/v1/rates/quotes';
            $res = $this->http()->withToken($token)->withHeaders(['X-locale' => 'en_US'])->asJson()->post($url, $body);
            if ($res->status() === 401) {
                $this->forgetToken();
                $token = $this->token(true);
                if (!$token) {
                    return $this->fail($this->lastError ?? 'FedEx authentication failed');
                }
                $res = $this->http()->withToken($token)->withHeaders(['X-locale' => 'en_US'])->asJson()->post($url, $body);
            }
            if (!$res->successful()) {
                return $this->fail('Rate request failed (' . $this->errorFromResponse($res) . ')');
            }

            $byType = [];
            foreach (($res->json('output.rateReplyDetails') ?? []) as $d) {
                $byType[$d['serviceType'] ?? ''] = $d;
            }
            foreach ($req['services'] as $svc) {
                $type = self::SERVICE_TYPE[$svc['code']] ?? null;
                if (!$type || !isset($byType[$type])) {
                    continue; // FedEx cannot ship with this service to that address
                }
                $d = $byType[$type];
                // Prefer ACCOUNT (negotiated) rate, fall back to LIST
                $chosen = null;
                $negotiated = false;
                foreach (($d['ratedShipmentDetails'] ?? []) as $rsd) {
                    if (($rsd['rateType'] ?? '') === 'ACCOUNT') {
                        $chosen = $rsd;
                        $negotiated = true;
                        break;
                    }
                    $chosen ??= $rsd;
                }
                $price = $chosen['totalNetCharge'] ?? ($chosen['totalNetFedExCharge'] ?? null);
                if ($price === null) {
                    continue;
                }
                $est = $d['commit']['dateDetail']['dayFormat'] ?? null;
                $rates[] = $this->normalize($svc, (float) $price, $chosen['currency'] ?? 'USD', $est ? date('D, M j', strtotime($est)) : null, $negotiated);
            }
        } catch (\Throwable $e) {
            return $this->fail('FedEx error: ' . $e->getMessage());
        }
        return $rates;
    }
}
