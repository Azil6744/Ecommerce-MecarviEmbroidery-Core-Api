<?php

namespace App\Services\Shipping\Carriers;

/**
 * DHL Express (developer.dhl.com) - MyDHL API, Basic auth (API key + secret) + Rating (GET /rates).
 * Credentials: apiKey, apiSecret, accountNumber (DHL Express account, required).
 */
class DhlAdapter extends CarrierAdapter
{
    /** Our service code => DHL product code */
    private const PRODUCT_CODE = [
        'EXPRESS_WORLDWIDE' => 'P',
        'DHL_ECONOMY' => 'H',
    ];

    public function carrierId(): string
    {
        return 'dhl';
    }

    private function base(): string
    {
        return $this->isProduction()
            ? 'https://express.api.dhl.com/mydhlapi'
            : 'https://express.api.dhl.com/mydhlapi/test';
    }

    private function query(array $req): array
    {
        return [
            'accountNumber' => $this->cred('accountNumber'),
            'originCountryCode' => $req['origin']['country'] ?? 'US',
            'originPostalCode' => $req['origin']['zip'],
            'originCityName' => $req['origin']['city'] ?? '',
            'destinationCountryCode' => $req['destination']['country'] ?? 'US',
            'destinationPostalCode' => $req['destination']['zip'],
            'destinationCityName' => $req['destination']['city'] ?? '',
            'weight' => max(0.1, round($req['weightLbs'] * 0.45359237, 2)),
            'length' => max(1, round($req['length'] * 2.54, 1)),
            'width' => max(1, round($req['width'] * 2.54, 1)),
            'height' => max(1, round($req['height'] * 2.54, 1)),
            'plannedShippingDate' => date('Y-m-d', strtotime('+1 weekday')),
            'isCustomsDeclarable' => (($req['origin']['country'] ?? 'US') !== ($req['destination']['country'] ?? 'US')) ? 'true' : 'false',
            'unitOfMeasurement' => 'metric',
        ];
    }

    private function request(array $req)
    {
        return $this->http()
            ->withBasicAuth($this->cred('apiKey'), $this->cred('apiSecret'))
            ->get($this->base() . '/rates', $this->query($req));
    }

    public function testConnection(): bool
    {
        if ($this->cred('apiKey') === '' || $this->cred('apiSecret') === '') {
            $this->lastError = 'API Key and API Secret are required.';
            return false;
        }
        if ($this->cred('accountNumber') === '') {
            $this->lastError = 'DHL Express account number is required.';
            return false;
        }
        try {
            // DHL has no token endpoint: verify credentials with a minimal real rate request.
            $res = $this->request([
                'origin' => ['zip' => '30331', 'city' => 'Atlanta', 'country' => 'US'],
                'destination' => ['zip' => '10001', 'city' => 'New York', 'country' => 'US'],
                'weightLbs' => 1, 'length' => 10, 'width' => 8, 'height' => 4,
            ]);
            if ($res->status() === 401 || $res->status() === 403) {
                $this->lastError = 'Authentication failed (' . $this->errorFromResponse($res) . ')';
                return false;
            }
            // 200 = fine; 400/404 for unsupported lane still proves the credentials were accepted.
            return true;
        } catch (\Throwable $e) {
            $this->lastError = 'Could not reach DHL: ' . $e->getMessage();
            return false;
        }
    }

    public function getRates(array $req): array
    {
        $rates = [];
        try {
            $res = $this->request($req);
            if (!$res->successful()) {
                return $this->fail('Rate request failed (' . $this->errorFromResponse($res) . ')');
            }
            $byCode = [];
            foreach (($res->json('products') ?? []) as $p) {
                $byCode[$p['productCode'] ?? ''] = $p;
            }
            foreach ($req['services'] as $svc) {
                $code = self::PRODUCT_CODE[$svc['code']] ?? null;
                if (!$code || !isset($byCode[$code])) {
                    continue; // DHL cannot ship with this service to that address
                }
                $p = $byCode[$code];
                $price = null;
                $currency = 'USD';
                foreach (($p['totalPrice'] ?? []) as $tp) {
                    if (($tp['currencyType'] ?? '') === 'BILLC') { // billing currency
                        $price = $tp['price'] ?? null;
                        $currency = $tp['priceCurrency'] ?? 'USD';
                        break;
                    }
                }
                if ($price === null) {
                    $price = $p['totalPrice'][0]['price'] ?? null;
                    $currency = $p['totalPrice'][0]['priceCurrency'] ?? 'USD';
                }
                if ($price === null) {
                    continue;
                }
                $est = $p['deliveryCapabilities']['estimatedDeliveryDateAndTime'] ?? null;
                $rates[] = $this->normalize($svc, (float) $price, $currency, $est ? date('D, M j', strtotime($est)) : null, true);
            }
        } catch (\Throwable $e) {
            return $this->fail('DHL error: ' . $e->getMessage());
        }
        return $rates;
    }
}
