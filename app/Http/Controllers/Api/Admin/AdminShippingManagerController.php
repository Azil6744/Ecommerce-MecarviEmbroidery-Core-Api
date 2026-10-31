<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Services\Shipping\ShippingRateService;

class AdminShippingManagerController extends Controller
{
    /**
     * Carrier definitions (credential fields per carrier). Values are never seeded:
     * the admin uploads real API credentials and verifies them with "Test Connection".
     */
    protected function defaultCarriers(): array
    {
        return [
            [
                'id' => 'usps',
                'name' => 'USPS',
                'fullName' => 'United States Postal Service',
                'connected' => false,
                'enabled' => false,
                'environment' => 'Production',
                'fields' => [
                    ['label' => 'Client ID (Consumer Key) *', 'key' => 'clientId', 'required' => true],
                    ['label' => 'Environment', 'key' => 'environment'],
                    ['label' => 'Client Secret (Consumer Secret) *', 'key' => 'clientSecret', 'required' => true, 'type' => 'password'],
                    ['label' => 'Account Name (Optional)', 'key' => 'accountName'],
                    ['label' => 'Account Number (Optional)', 'key' => 'accountNumber'],
                ],
                'values' => ['environment' => 'Production'],
                'lastSynced' => 'Never',
            ],
            [
                'id' => 'ups',
                'name' => 'UPS',
                'fullName' => 'United Parcel Service',
                'connected' => false,
                'enabled' => false,
                'environment' => 'Production',
                'fields' => [
                    ['label' => 'Client ID *', 'key' => 'accessKey', 'required' => true],
                    ['label' => 'Environment', 'key' => 'environment'],
                    ['label' => 'Client Secret *', 'key' => 'secretKey', 'required' => true, 'type' => 'password'],
                    ['label' => 'Account Name (Optional)', 'key' => 'accountName'],
                    ['label' => 'Account Number (Shipper Number)', 'key' => 'accountNumber'],
                ],
                'values' => ['environment' => 'Production'],
                'lastSynced' => 'Never',
            ],
            [
                'id' => 'fedex',
                'name' => 'FedEx',
                'fullName' => 'Federal Express',
                'connected' => false,
                'enabled' => false,
                'environment' => 'Production',
                'fields' => [
                    ['label' => 'API Key *', 'key' => 'apiKey', 'required' => true],
                    ['label' => 'Environment', 'key' => 'environment'],
                    ['label' => 'API Secret *', 'key' => 'apiSecret', 'required' => true, 'type' => 'password'],
                    ['label' => 'Account Name (Optional)', 'key' => 'accountName'],
                    ['label' => 'Account Number *', 'key' => 'accountNumber', 'required' => true],
                ],
                'values' => ['environment' => 'Production'],
                'lastSynced' => 'Never',
            ],
            [
                'id' => 'dhl',
                'name' => 'DHL',
                'fullName' => 'DHL Express',
                'connected' => false,
                'enabled' => false,
                'environment' => 'Production',
                'fields' => [
                    ['label' => 'API Key *', 'key' => 'apiKey', 'required' => true],
                    ['label' => 'Environment', 'key' => 'environment'],
                    ['label' => 'API Secret *', 'key' => 'apiSecret', 'required' => true, 'type' => 'password'],
                    ['label' => 'Account Name (Optional)', 'key' => 'accountName'],
                    ['label' => 'Account Number *', 'key' => 'accountNumber', 'required' => true],
                ],
                'values' => ['environment' => 'Production'],
                'lastSynced' => 'Never',
            ],
        ];
    }

    /**
     * Merge stored carrier rows with the current definitions: field lists always come from the
     * definitions, legacy/fake seeded credential values are dropped, and "connected" is only
     * true when a real Test Connection succeeded (verifiedAt).
     */
    protected function normalizeCarriers(array $storedCarriers): array
    {
        $storedById = [];
        foreach ($storedCarriers as $c) {
            if (isset($c['id'])) {
                $storedById[$c['id']] = $c;
            }
        }

        $out = [];
        foreach ($this->defaultCarriers() as $def) {
            $s = $storedById[$def['id']] ?? null;
            if ($s) {
                $allowed = array_column($def['fields'], 'key');
                $values = [];
                foreach (($s['values'] ?? []) as $k => $v) {
                    if (!in_array($k, $allowed, true) || !is_string($v)) {
                        continue;
                    }
                    // Seeded demo values were masks/dummy numbers: drop anything with no letters/digits.
                    if ($k !== 'environment' && strlen($v) >= 6 && !preg_match('/[A-Za-z0-9]/', $v)) {
                        continue;
                    }
                    $values[$k] = $v;
                }
                $def['values'] = array_merge($def['values'], $values);
                $def['enabled'] = !empty($s['enabled']);
                $def['environment'] = $s['environment'] ?? $def['values']['environment'] ?? 'Production';
                $def['verifiedAt'] = $s['verifiedAt'] ?? null;
                $def['lastError'] = $s['lastError'] ?? null;
            }
            $def['connected'] = !empty($def['verifiedAt']);
            $def['lastSynced'] = !empty($def['verifiedAt'])
                ? \Carbon\Carbon::parse($def['verifiedAt'])->diffForHumans()
                : 'Never';
            $out[] = $def;
        }
        return $out;
    }

    /** Never send secrets to the browser: show only a masked tail. */
    protected function maskCarrier(array $carrier): array
    {
        foreach (($carrier['values'] ?? []) as $key => $value) {
            if (ShippingRateService::isSecretKey($key) && is_string($value) && $value !== '') {
                $plain = ShippingRateService::decryptSecret($value);
                $carrier['values'][$key] = '••••••••' . substr($plain, -4);
            }
        }
        unset($carrier['lastError']);
        $carrier['hasError'] = false;
        return $carrier;
    }

    /** Apply submitted credential values (encrypt secrets, ignore masked placeholders). Returns true if credentials changed. */
    protected function applyCarrierValues(array &$carrier, array $incoming): bool
    {
        $allowed = array_column($carrier['fields'], 'key');
        $changed = false;
        foreach ($incoming as $key => $value) {
            if (!in_array($key, $allowed, true) || !is_string($value)) {
                continue;
            }
            $value = trim($value);
            if (str_starts_with($value, '••')) {
                continue; // masked placeholder = unchanged
            }
            if ($key !== 'environment' && $value === '' && ShippingRateService::isSecretKey($key)) {
                continue; // empty secret = keep existing
            }
            $stored = ShippingRateService::isSecretKey($key) ? ShippingRateService::encryptSecret($value) : $value;
            if (($carrier['values'][$key] ?? null) !== $stored) {
                $carrier['values'][$key] = $stored;
                $changed = true;
            }
        }
        if (isset($carrier['values']['environment'])) {
            $carrier['environment'] = $carrier['values']['environment'];
        }
        return $changed;
    }

    /**
     * Default services list.
     */
    protected function defaultServices(): array
    {
        return [
            // USPS
            ['id' => 's1', 'carrierId' => 'usps', 'show' => true, 'enabled' => true, 'name' => 'USPS Ground Advantage', 'code' => 'GND', 'type' => 'Standard', 'estDelivery' => '2–5 business days'],
            ['id' => 's2', 'carrierId' => 'usps', 'show' => true, 'enabled' => true, 'name' => 'USPS Priority Mail', 'code' => 'PRIORITY', 'type' => 'Express', 'estDelivery' => '1–3 business days'],
            ['id' => 's3', 'carrierId' => 'usps', 'show' => true, 'enabled' => true, 'name' => 'USPS Priority Mail Express', 'code' => 'PRIORITY_EXPRESS', 'type' => 'Express', 'estDelivery' => '1–2 business days'],
            ['id' => 's4', 'carrierId' => 'usps', 'show' => false, 'enabled' => false, 'name' => 'USPS Media Mail', 'code' => 'MEDIA', 'type' => 'Standard', 'estDelivery' => '2–8 business days'],
            ['id' => 's5', 'carrierId' => 'usps', 'show' => false, 'enabled' => false, 'name' => 'USPS Retail Ground', 'code' => 'RETAIL_GROUND', 'type' => 'Standard', 'estDelivery' => '2–8 business days'],
            ['id' => 's6', 'carrierId' => 'usps', 'show' => true, 'enabled' => true, 'name' => 'USPS First Class Package', 'code' => 'FIRST_CLASS', 'type' => 'Standard', 'estDelivery' => '2–5 business days'],
            // UPS
            ['id' => 's7', 'carrierId' => 'ups', 'show' => true, 'enabled' => true, 'name' => 'UPS Ground', 'code' => 'UPS_GROUND', 'type' => 'Standard', 'estDelivery' => '1–5 business days'],
            ['id' => 's8', 'carrierId' => 'ups', 'show' => true, 'enabled' => true, 'name' => 'UPS 2nd Day Air', 'code' => 'UPS_2DA', 'type' => 'Express', 'estDelivery' => '2 business days'],
            ['id' => 's9', 'carrierId' => 'ups', 'show' => true, 'enabled' => true, 'name' => 'UPS Next Day Air', 'code' => 'UPS_NDA', 'type' => 'Express', 'estDelivery' => '1 business day'],
            ['id' => 's14', 'carrierId' => 'ups', 'show' => true, 'enabled' => true, 'name' => 'UPS 3 Day Select', 'code' => 'UPS_3DS', 'type' => 'Standard', 'estDelivery' => '3 business days'],
            // FedEx
            ['id' => 's10', 'carrierId' => 'fedex', 'show' => true, 'enabled' => true, 'name' => 'FedEx Home Delivery', 'code' => 'FEDEX_HOME', 'type' => 'Standard', 'estDelivery' => '1–5 business days'],
            ['id' => 's15', 'carrierId' => 'fedex', 'show' => true, 'enabled' => true, 'name' => 'FedEx Ground', 'code' => 'FEDEX_GROUND', 'type' => 'Standard', 'estDelivery' => '1–5 business days'],
            ['id' => 's11', 'carrierId' => 'fedex', 'show' => true, 'enabled' => true, 'name' => 'FedEx 2Day', 'code' => 'FEDEX_2DAY', 'type' => 'Express', 'estDelivery' => '2 business days'],
            ['id' => 's12', 'carrierId' => 'fedex', 'show' => true, 'enabled' => true, 'name' => 'FedEx Priority Overnight', 'code' => 'FEDEX_OVERNIGHT', 'type' => 'Express', 'estDelivery' => '1 business day'],
            // DHL
            ['id' => 's13', 'carrierId' => 'dhl', 'show' => true, 'enabled' => true, 'name' => 'DHL Express Worldwide', 'code' => 'EXPRESS_WORLDWIDE', 'type' => 'Express', 'estDelivery' => '1–3 business days'],
            ['id' => 's16', 'carrierId' => 'dhl', 'show' => true, 'enabled' => true, 'name' => 'DHL Economy', 'code' => 'DHL_ECONOMY', 'type' => 'Standard', 'estDelivery' => '4–7 business days'],
        ];
    }

    /**
     * Default package profiles.
     */
    protected function defaultPackages(): array
    {
        return [
            ['id' => 'p1', 'name' => 'Poly Mailer (Small)', 'type' => 'Poly Mailer', 'length' => '12', 'width' => '9', 'height' => '2', 'maxWeight' => '2', 'status' => 'Active'],
            ['id' => 'p2', 'name' => 'Poly Mailer (Large)', 'type' => 'Poly Mailer', 'length' => '14', 'width' => '11', 'height' => '2', 'maxWeight' => '5', 'status' => 'Active'],
            ['id' => 'p3', 'name' => 'Small Box', 'type' => 'Box', 'length' => '12', 'width' => '8', 'height' => '6', 'maxWeight' => '10', 'status' => 'Active'],
            ['id' => 'p4', 'name' => 'Medium Box', 'type' => 'Box', 'length' => '16', 'width' => '12', 'height' => '8', 'maxWeight' => '20', 'status' => 'Active'],
            ['id' => 'p5', 'name' => 'Large Box', 'type' => 'Box', 'length' => '20', 'width' => '16', 'height' => '12', 'maxWeight' => '40', 'status' => 'Active'],
        ];
    }

    /**
     * Default ship-from locations.
     */
    protected function defaultLocations(): array
    {
        return [
            ['id' => 'l1', 'name' => 'Mecarvi Embroidery (Main)', 'address' => '1234 Commerce Blvd', 'city' => 'Atlanta', 'state' => 'GA', 'zip' => '30331', 'isDefault' => true, 'status' => 'Active'],
            ['id' => 'l2', 'name' => 'Mecarvi Store South', 'address' => '5678 Fairburn Rd', 'city' => 'South Fulton', 'state' => 'GA', 'zip' => '30213', 'isDefault' => false, 'status' => 'Active'],
            ['id' => 'l3', 'name' => 'Mecarvi Store North', 'address' => '9101 Alpharetta Hwy', 'city' => 'Alpharetta', 'state' => 'GA', 'zip' => '30009', 'isDefault' => false, 'status' => 'Active'],
        ];
    }

    /**
     * Default rate settings.
     */
    protected function defaultRateSettings(): array
    {
        return [
            'defaultPackageType' => 'Auto-select based on cart items',
            'markupValue' => '0.00',
            'markupType' => '%',
            'handlingFee' => '0.00',
            'handlingCurrency' => 'USD',
            'allowedCountries' => ['United States', 'Canada', 'United Kingdom', 'Australia'],
        ];
    }

    /**
     * Helper to load all stored shipping settings.
     */
    protected function getStoredSettings(): array
    {
        $setting = SiteSetting::firstOrCreate([]);
        $raw = $setting->shipping_config_settings;
        $data = [];
        if (!empty($raw)) {
            $data = is_array($raw) ? $raw : json_decode($raw, true);
        }
        if (!is_array($data)) {
            $data = [];
        }

        return [
            'carriers' => $this->normalizeCarriers(!empty($data['carriers']) && is_array($data['carriers']) ? $data['carriers'] : []),
            'services' => !empty($data['services']) && is_array($data['services']) ? $data['services'] : $this->defaultServices(),
            'packages' => !empty($data['packages']) && is_array($data['packages']) ? $data['packages'] : $this->defaultPackages(),
            'locations' => !empty($data['locations']) && is_array($data['locations']) ? $data['locations'] : $this->defaultLocations(),
            'rateSettings' => !empty($data['rateSettings']) && is_array($data['rateSettings']) ? array_merge($this->defaultRateSettings(), $data['rateSettings']) : $this->defaultRateSettings(),
        ];
    }

    /**
     * Helper to save shipping settings.
     */
    protected function saveStoredSettings(array $settings): void
    {
        $setting = SiteSetting::firstOrCreate([]);
        $setting->shipping_config_settings = json_encode($settings);
        $setting->save();
    }

    // ── Carriers ─────────────────────────────────────────────────────────────

    public function getCarriers()
    {
        $stored = $this->getStoredSettings();
        return response()->json([
            'success' => true,
            'data' => array_map(fn($c) => $this->maskCarrier($c), $stored['carriers']),
        ]);
    }

    public function updateCarrier(Request $request, string $id)
    {
        $stored = $this->getStoredSettings();
        $payload = $request->all();

        $updated = false;
        foreach ($stored['carriers'] as &$carrier) {
            if ($carrier['id'] === $id) {
                if (isset($payload['enabled'])) $carrier['enabled'] = (bool) $payload['enabled'];
                if (isset($payload['environment']) && is_string($payload['environment'])) {
                    $carrier['values']['environment'] = $payload['environment'];
                }
                $credsChanged = false;
                if (isset($payload['values']) && is_array($payload['values'])) {
                    $credsChanged = $this->applyCarrierValues($carrier, $payload['values']);
                }
                if (isset($payload['environment'])) {
                    $carrier['environment'] = $payload['environment'];
                    $credsChanged = true;
                }
                if ($credsChanged) {
                    // Credentials changed: must be verified again with Test Connection.
                    $carrier['verifiedAt'] = null;
                }
                $updated = true;
                break;
            }
        }
        unset($carrier);

        if ($updated) {
            $this->saveStoredSettings($stored);
        }

        return response()->json([
            'success' => true,
            'message' => 'Carrier updated successfully.',
            'data' => array_map(fn($c) => $this->maskCarrier($c), $stored['carriers']),
        ]);
    }

    /**
     * Save any submitted credentials, then verify them against the real carrier API
     * (OAuth token request; DHL minimal rate call). Connected status comes only from this result.
     */
    public function testCarrierConnection(Request $request, string $id)
    {
        $stored = $this->getStoredSettings();
        $index = null;
        foreach ($stored['carriers'] as $i => $c) {
            if ($c['id'] === $id) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return response()->json(['success' => false, 'message' => 'Unknown carrier.'], 404);
        }

        $carrier = &$stored['carriers'][$index];
        if ($request->has('environment') && is_string($request->input('environment'))) {
            $carrier['values']['environment'] = $request->input('environment');
            $carrier['environment'] = $request->input('environment');
        }
        if (is_array($request->input('values'))) {
            $this->applyCarrierValues($carrier, $request->input('values'));
        }

        $adapter = ShippingRateService::adapterFor($carrier);
        $ok = false;
        $message = 'Unsupported carrier.';
        if ($adapter) {
            $ok = $adapter->testConnection();
            $message = $ok ? 'Connection successful. Credentials verified with ' . $carrier['name'] . '.' : ($adapter->lastError ?? 'Connection failed.');
        }

        $carrier['verifiedAt'] = $ok ? now()->toIso8601String() : null;
        $carrier['lastError'] = $ok ? null : $message;
        $this->saveStoredSettings($stored);
        $masked = $this->maskCarrier($carrier);
        unset($carrier);

        return response()->json([
            'success' => $ok,
            'message' => $message,
            'carrier' => $id,
            'connected' => $ok,
            'data' => $masked,
        ], $ok ? 200 : 422);
    }

    // ── Services ────────────────────────────────────────────────────────────

    public function getServices(Request $request)
    {
        $stored = $this->getStoredSettings();
        $carrierFilter = $request->query('carrier');
        $services = $stored['services'];

        if ($carrierFilter) {
            $services = array_values(array_filter($services, fn($s) => strtolower($s['carrierId']) === strtolower($carrierFilter)));
        }

        return response()->json(['success' => true, 'data' => $services]);
    }

    public function refreshServices(Request $request)
    {
        $stored = $this->getStoredSettings();
        return response()->json([
            'success' => true,
            'message' => 'Carrier services refreshed successfully.',
            'data' => $stored['services'],
        ]);
    }

    public function updateService(Request $request, string $id)
    {
        $stored = $this->getStoredSettings();
        $payload = $request->all();

        $updated = false;
        foreach ($stored['services'] as &$svc) {
            if ($svc['id'] === $id) {
                if (isset($payload['enabled'])) $svc['enabled'] = (bool) $payload['enabled'];
                if (isset($payload['show'])) $svc['show'] = (bool) $payload['show'];
                if (isset($payload['name'])) $svc['name'] = $payload['name'];
                $updated = true;
                break;
            }
        }

        if ($updated) {
            $this->saveStoredSettings($stored);
        }

        return response()->json(['success' => true, 'message' => 'Service updated.', 'data' => $stored['services']]);
    }

    // ── Packages ────────────────────────────────────────────────────────────

    public function getPackages()
    {
        $stored = $this->getStoredSettings();
        return response()->json(['success' => true, 'data' => $stored['packages']]);
    }

    public function createPackage(Request $request)
    {
        $stored = $this->getStoredSettings();
        $newPkg = [
            'id' => 'p_' . time() . '_' . Str::random(4),
            'name' => $request->input('name', 'New Package'),
            'type' => $request->input('type', 'Box'),
            'length' => (string) $request->input('length', '12'),
            'width' => (string) $request->input('width', '10'),
            'height' => (string) $request->input('height', '4'),
            'maxWeight' => (string) $request->input('maxWeight', '10'),
            'status' => $request->input('status', 'Active'),
        ];

        $stored['packages'][] = $newPkg;
        $this->saveStoredSettings($stored);

        return response()->json(['success' => true, 'data' => $newPkg], 201);
    }

    public function updatePackage(Request $request, string $id)
    {
        $stored = $this->getStoredSettings();
        $payload = $request->all();

        foreach ($stored['packages'] as &$pkg) {
            if ($pkg['id'] === $id) {
                $pkg = array_merge($pkg, $payload);
                break;
            }
        }

        $this->saveStoredSettings($stored);
        return response()->json(['success' => true, 'message' => 'Package updated.', 'data' => $stored['packages']]);
    }

    public function deletePackage(string $id)
    {
        $stored = $this->getStoredSettings();
        $stored['packages'] = array_values(array_filter($stored['packages'], fn($p) => $p['id'] !== $id));
        $this->saveStoredSettings($stored);

        return response()->json(['success' => true, 'message' => 'Package deleted.']);
    }

    // ── Ship-From Locations ──────────────────────────────────────────────────

    public function getShipFromLocations()
    {
        $stored = $this->getStoredSettings();
        return response()->json(['success' => true, 'data' => $stored['locations']]);
    }

    public function createShipFromLocation(Request $request)
    {
        $stored = $this->getStoredSettings();
        $newLoc = [
            'id' => 'l_' . time() . '_' . Str::random(4),
            'name' => $request->input('name', 'Warehouse'),
            'address' => $request->input('address', ''),
            'city' => $request->input('city', ''),
            'state' => $request->input('state', ''),
            'zip' => $request->input('zip', ''),
            'isDefault' => (bool) $request->input('isDefault', false),
            'status' => $request->input('status', 'Active'),
        ];

        if ($newLoc['isDefault']) {
            foreach ($stored['locations'] as &$loc) {
                $loc['isDefault'] = false;
            }
        }

        $stored['locations'][] = $newLoc;
        $this->saveStoredSettings($stored);

        return response()->json(['success' => true, 'data' => $newLoc], 201);
    }

    public function updateShipFromLocation(Request $request, string $id)
    {
        $stored = $this->getStoredSettings();
        $payload = $request->all();

        if (!empty($payload['isDefault'])) {
            foreach ($stored['locations'] as &$loc) {
                $loc['isDefault'] = false;
            }
        }

        foreach ($stored['locations'] as &$loc) {
            if ($loc['id'] === $id) {
                $loc = array_merge($loc, $payload);
                break;
            }
        }

        $this->saveStoredSettings($stored);
        return response()->json(['success' => true, 'message' => 'Location updated.', 'data' => $stored['locations']]);
    }

    public function deleteShipFromLocation(string $id)
    {
        $stored = $this->getStoredSettings();
        $stored['locations'] = array_values(array_filter($stored['locations'], fn($l) => $l['id'] !== $id));
        $this->saveStoredSettings($stored);

        return response()->json(['success' => true, 'message' => 'Location deleted.']);
    }

    public function setDefaultLocation(string $id)
    {
        $stored = $this->getStoredSettings();
        foreach ($stored['locations'] as &$loc) {
            $loc['isDefault'] = ($loc['id'] === $id);
        }
        $this->saveStoredSettings($stored);

        return response()->json(['success' => true, 'message' => 'Default location set.']);
    }

    // ── Rate Calculation Settings ───────────────────────────────────────────

    public function getRateSettings()
    {
        $stored = $this->getStoredSettings();
        return response()->json(['success' => true, 'data' => $stored['rateSettings']]);
    }

    public function updateRateSettings(Request $request)
    {
        $stored = $this->getStoredSettings();
        $stored['rateSettings'] = array_merge($stored['rateSettings'], $request->all());
        $this->saveStoredSettings($stored);

        return response()->json(['success' => true, 'message' => 'Rate settings updated.', 'data' => $stored['rateSettings']]);
    }

    // ── Calculate Live Rates ─────────────────────────────────────────────────

    /**
     * Live rate shopping across the enabled carriers using the credentials uploaded by the admin.
     * Only carriers that can actually ship to the destination return rates; when none can, `data` is empty
     * (shipping is blocked for that address). Prices come from the carrier APIs - nothing is estimated.
     *
     * Accepts: weight + weightUnit, packageProfileId | dimensions{length,width,height},
     *          shipFromId, zip, city, state, country|destination, residential
     */
    public function calculateRates(Request $request)
    {
        $stored = $this->getStoredSettings();
        $packages = $stored['packages'];

        $rawWeight = (float) $request->input('weight', 0.5);
        $weightUnit = strtolower((string) $request->input('weightUnit', 'lb'));
        $weightInLbs = $rawWeight;
        if ($weightUnit === 'oz') {
            $weightInLbs = $rawWeight / 16.0;
        } elseif ($weightUnit === 'kg') {
            $weightInLbs = $rawWeight * 2.20462;
        }
        $weightInLbs = max(0.1, round($weightInLbs, 2));

        $length = 12.0;
        $width = 10.0;
        $height = 2.0;
        $packageProfileId = $request->input('packageProfileId');
        if ($packageProfileId) {
            foreach ($packages as $pkg) {
                if ($pkg['id'] === $packageProfileId) {
                    $length = (float) ($pkg['length'] ?? 12);
                    $width = (float) ($pkg['width'] ?? 10);
                    $height = (float) ($pkg['height'] ?? 2);
                    break;
                }
            }
        } elseif (is_array($request->input('dimensions'))) {
            $dims = $request->input('dimensions');
            $length = max(1.0, (float) ($dims['length'] ?? 12));
            $width = max(1.0, (float) ($dims['width'] ?? 10));
            $height = max(0.5, (float) ($dims['height'] ?? 2));
        }

        $dimWeight = ($length * $width * $height) / 139.0;
        $billableWeight = max($weightInLbs, $dimWeight);

        $destination = $request->input('destination');
        $country = $request->input('country', is_string($destination) ? $destination : 'United States');
        if (is_array($destination)) {
            $country = $destination['country'] ?? $country;
        }
        $destArr = is_array($destination) ? $destination : [];
        $dest = [
            'zip' => trim((string) ($destArr['zip'] ?? $request->input('zip', ''))),
            'city' => trim((string) ($destArr['city'] ?? $request->input('city', ''))),
            'state' => trim((string) ($destArr['state'] ?? $request->input('state', ''))),
            'country' => $country,
            'residential' => (bool) ($destArr['residential'] ?? $request->input('residential', true)),
        ];

        if ($dest['zip'] === '') {
            return response()->json(['success' => false, 'message' => 'Destination postal code is required.', 'data' => []], 422);
        }

        $result = app(ShippingRateService::class)->quote($stored, [
            'weightLbs' => $billableWeight,
            'length' => $length,
            'width' => $width,
            'height' => $height,
            'destination' => $dest,
            'originLocationId' => $request->input('shipFromId'),
        ]);

        $rates = array_map(function ($r) use ($billableWeight, $dimWeight, $weightInLbs) {
            return $r + [
                'billableWeight' => round($billableWeight, 2),
                'dimWeight' => round($dimWeight, 2),
                'actualWeight' => round($weightInLbs, 2),
            ];
        }, $result['rates']);

        $noRates = empty($rates);
        return response()->json([
            'success' => true,
            'data' => $rates,
            'available' => !$noRates,
            'message' => $noRates
                ? ($result['carriersQueried'] === 0
                    ? 'No carrier is connected. Add and enable carrier credentials in Shipping Options.'
                    : 'Shipping is not available to this address.')
                : null,
            'summary' => [
                'actualWeightLbs' => round($weightInLbs, 2),
                'dimWeightLbs' => round($dimWeight, 2),
                'billableWeightLbs' => round($billableWeight, 2),
                'dimensions' => ['length' => $length, 'width' => $width, 'height' => $height],
                'carrierCount' => $result['carriersQueried'],
            ],
        ]);
    }
}
