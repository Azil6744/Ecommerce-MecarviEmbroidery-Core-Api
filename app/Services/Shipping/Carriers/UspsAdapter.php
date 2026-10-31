<?php

namespace App\Services\Shipping\Carriers;

/**
 * USPS (developers.usps.com) - OAuth 2.0 client credentials + Domestic Prices API v3.
 * Credentials: clientId (Consumer Key), clientSecret (Consumer Secret).
 */
class UspsAdapter extends CarrierAdapter
{
    /** Our service code => USPS mailClass */
    private const MAIL_CLASS = [
        'GND' => 'USPS_GROUND_ADVANTAGE',
        'PRIORITY' => 'PRIORITY_MAIL',
        'PRIORITY_EXPRESS' => 'PRIORITY_MAIL_EXPRESS',
        'MEDIA' => 'MEDIA_MAIL',
        'RETAIL_GROUND' => 'USPS_GROUND_ADVANTAGE',
        'FIRST_CLASS' => 'USPS_GROUND_ADVANTAGE',
    ];

    public function carrierId(): string
    {
        return 'usps';
    }

    private function base(): string
    {
        return $this->isProduction() ? 'https://apis.usps.com' : 'https://apis-tem.usps.com';
    }

    private function token(bool $fresh = false): ?string
    {
        if (!$fresh && ($t = $this->cachedToken())) {
            return $t;
        }
        $id = $this->cred('clientId');
        $secret = $this->cred('clientSecret');
        if ($id === '' || $secret === '') {
            $this->lastError = 'Client ID and Client Secret are required.';
            return null;
        }
        $res = $this->http()->asJson()->post($this->base() . '/oauth2/v3/token', [
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
            $this->lastError = 'Could not reach USPS: ' . $e->getMessage();
            return false;
        }
    }

    public function getRates(array $req): array
    {
        $rates = [];
        try {
            $token = $this->token();
            if (!$token) {
                return $this->fail($this->lastError ?? 'USPS authentication failed');
            }
            if (($req['destination']['country'] ?? 'US') !== 'US') {
                return []; // USPS Domestic Prices API only
            }
            foreach ($req['services'] as $svc) {
                $mailClass = self::MAIL_CLASS[$svc['code']] ?? null;
                if (!$mailClass) {
                    continue;
                }
                $body = [
                    'originZIPCode' => substr(preg_replace('/\D/', '', $req['origin']['zip']), 0, 5),
                    'destinationZIPCode' => substr(preg_replace('/\D/', '', $req['destination']['zip']), 0, 5),
                    'weight' => max(0.1, round($req['weightLbs'], 2)),
                    'length' => max(1, round($req['length'], 1)),
                    'width' => max(1, round($req['width'], 1)),
                    'height' => max(0.5, round($req['height'], 1)),
                    'mailClass' => $mailClass,
                    'processingCategory' => 'MACHINABLE',
                    'rateIndicator' => 'SP',
                    'destinationEntryFacilityType' => 'NONE',
                    'priceType' => 'RETAIL',
                    'mailingDate' => date('Y-m-d'),
                ];
                $res = $this->http()->withToken($token)->asJson()->post($this->base() . '/prices/v3/base-rates/search', $body);
                if ($res->status() === 401) {
                    $this->forgetToken();
                    $token = $this->token(true);
                    if (!$token) {
                        break;
                    }
                    $res = $this->http()->withToken($token)->asJson()->post($this->base() . '/prices/v3/base-rates/search', $body);
                }
                if (!$res->successful()) {
                    $this->fail('Rate request failed for ' . $svc['name'] . ' (' . $this->errorFromResponse($res) . ')');
                    continue;
                }
                $price = $res->json('totalBasePrice') ?? ($res->json('rates.0.price') ?? null);
                if ($price === null) {
                    continue;
                }
                $rates[] = $this->normalize($svc, (float) $price);
            }
        } catch (\Throwable $e) {
            return $this->fail('USPS error: ' . $e->getMessage());
        }
        return $rates;
    }
}
