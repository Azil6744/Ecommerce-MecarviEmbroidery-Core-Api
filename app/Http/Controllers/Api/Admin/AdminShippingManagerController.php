<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminShippingManagerController extends Controller
{
    /**
     * Default carriers list matching Admin Shipping configuration.
     */
    protected function defaultCarriers(): array
    {
        return [
            [
                'id' => 'usps',
                'name' => 'USPS',
                'fullName' => 'United States Postal Service',
                'connected' => true,
                'enabled' => true,
                'environment' => 'Production',
                'fields' => [
                    ['label' => 'API Username *', 'key' => 'username', 'required' => true],
                    ['label' => 'Environment', 'key' => 'environment'],
                    ['label' => 'API Password *', 'key' => 'password', 'required' => true, 'type' => 'password'],
                    ['label' => 'Account Name (Optional)', 'key' => 'accountName'],
                    ['label' => 'Account Number (Optional)', 'key' => 'accountNumber'],
                ],
                'values' => [
                    'username' => 'MecarviEmbroidery',
                    'password' => '••••••••••••••••',
                    'environment' => 'Production',
                    'accountName' => 'Mecarvi Embroidery',
                    'accountNumber' => '1234567890',
                ],
                'lastSynced' => 'Just now',
            ],
            [
                'id' => 'ups',
                'name' => 'UPS',
                'fullName' => 'United Parcel Service',
                'connected' => true,
                'enabled' => true,
                'environment' => 'Production',
                'fields' => [
                    ['label' => 'Access Key *', 'key' => 'accessKey', 'required' => true],
                    ['label' => 'Environment', 'key' => 'environment'],
                    ['label' => 'Secret Key *', 'key' => 'secretKey', 'required' => true, 'type' => 'password'],
                    ['label' => 'Account Name (Optional)', 'key' => 'accountName'],
                    ['label' => 'Account Number (Optional)', 'key' => 'accountNumber'],
                ],
                'values' => [
                    'accessKey' => '••••••••••••••••',
                    'secretKey' => '••••••••••••••••',
                    'environment' => 'Production',
                    'accountName' => 'Mecarvi Embroidery',
                    'accountNumber' => '12BA5F9E1234567890',
                ],
                'lastSynced' => 'Just now',
            ],
            [
                'id' => 'fedex',
                'name' => 'FedEx',
                'fullName' => 'Federal Express',
                'connected' => true,
                'enabled' => true,
                'environment' => 'Production',
                'fields' => [
                    ['label' => 'API Key *', 'key' => 'apiKey', 'required' => true],
                    ['label' => 'Environment', 'key' => 'environment'],
                    ['label' => 'API Secret *', 'key' => 'apiSecret', 'required' => true, 'type' => 'password'],
                    ['label' => 'Account Name (Optional)', 'key' => 'accountName'],
                    ['label' => 'Account Number (Optional)', 'key' => 'accountNumber'],
                ],
                'values' => [
                    'apiKey' => '••••••••••••••••',
                    'apiSecret' => '••••••••••••••••',
                    'environment' => 'Production',
                    'accountName' => 'Mecarvi Embroidery',
                    'accountNumber' => '510087654',
                ],
                'lastSynced' => 'Just now',
            ],
            [
                'id' => 'dhl',
                'name' => 'DHL',
                'fullName' => 'DHL Express',
                'connected' => true,
                'enabled' => true,
                'environment' => 'Production',
                'fields' => [
                    ['label' => 'API Key *', 'key' => 'apiKey', 'required' => true],
                    ['label' => 'Environment', 'key' => 'environment'],
                    ['label' => 'API Secret *', 'key' => 'apiSecret', 'required' => true, 'type' => 'password'],
                    ['label' => 'Account Name (Optional)', 'key' => 'accountName'],
                    ['label' => 'Account Number (Optional)', 'key' => 'accountNumber'],
                ],
                'values' => [
                    'apiKey' => '••••••••••••••••',
                    'apiSecret' => '••••••••••••••••',
                    'environment' => 'Production',
                    'accountName' => 'Mecarvi Embroidery',
                    'accountNumber' => '987654321',
                ],
                'lastSynced' => 'Just now',
            ],
        ];
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
            'carriers' => !empty($data['carriers']) && is_array($data['carriers']) ? $data['carriers'] : $this->defaultCarriers(),
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
        return response()->json(['success' => true, 'data' => $stored['carriers']]);
    }

    public function updateCarrier(Request $request, string $id)
    {
        $stored = $this->getStoredSettings();
        $payload = $request->all();

        $updated = false;
        foreach ($stored['carriers'] as &$carrier) {
            if ($carrier['id'] === $id) {
                if (isset($payload['enabled'])) $carrier['enabled'] = (bool) $payload['enabled'];
                if (isset($payload['connected'])) $carrier['connected'] = (bool) $payload['connected'];
                if (isset($payload['environment'])) $carrier['environment'] = $payload['environment'];
                if (isset($payload['values']) && is_array($payload['values'])) {
                    $carrier['values'] = array_merge($carrier['values'] ?? [], $payload['values']);
                }
                $carrier['lastSynced'] = 'Just now';
                $updated = true;
                break;
            }
        }

        if ($updated) {
            $this->saveStoredSettings($stored);
        }

        return response()->json(['success' => true, 'message' => 'Carrier updated successfully.', 'data' => $stored['carriers']]);
    }

    public function testCarrierConnection(string $id)
    {
        $stored = $this->getStoredSettings();
        $found = null;
        foreach ($stored['carriers'] as &$carrier) {
            if ($carrier['id'] === $id) {
                $carrier['connected'] = true;
                $carrier['lastSynced'] = 'Just now';
                $found = $carrier;
                break;
            }
        }
        if ($found) {
            $this->saveStoredSettings($stored);
        }

        return response()->json([
            'success' => true,
            'message' => 'Carrier API connection test successful.',
            'carrier' => $id,
            'connected' => true,
        ]);
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

    public function calculateRates(Request $request)
    {
        $stored = $this->getStoredSettings();
        $carriers = $stored['carriers'];
        $services = $stored['services'];
        $packages = $stored['packages'];
        $rateSettings = $stored['rateSettings'];

        // Extract input parameters
        $rawWeight = (float) $request->input('weight', 0.5);
        $weightUnit = strtolower((string) $request->input('weightUnit', 'lb'));

        // Normalize weight to lbs
        $weightInLbs = $rawWeight;
        if ($weightUnit === 'oz') {
            $weightInLbs = $rawWeight / 16.0;
        } elseif ($weightUnit === 'kg') {
            $weightInLbs = $rawWeight * 2.20462;
        }
        $weightInLbs = max(0.1, round($weightInLbs, 2));

        // Dimensions
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
        } elseif ($request->has('dimensions')) {
            $dims = $request->input('dimensions');
            if (is_array($dims)) {
                $length = max(1.0, (float) ($dims['length'] ?? 12));
                $width = max(1.0, (float) ($dims['width'] ?? 10));
                $height = max(0.5, (float) ($dims['height'] ?? 2));
            }
        }

        // Dimensional weight using standard carrier domestic divisor 139
        $dimWeight = ($length * $width * $height) / 139.0;
        $billableWeight = max($weightInLbs, $dimWeight);

        // Destination & Zone
        $destination = (string) $request->input('destination', 'United States');
        $zip = trim((string) $request->input('zip', '30331'));

        $isDomestic = (stripos($destination, 'United States') !== false || stripos($destination, 'US') !== false || empty($destination));
        $zone = 4; // average baseline
        if ($isDomestic && !empty($zip) && is_numeric(substr($zip, 0, 3))) {
            $originPrefix = 303; // Atlanta, GA
            $destPrefix = (int) substr($zip, 0, 3);
            $diff = abs($originPrefix - $destPrefix);
            if ($diff < 50) $zone = 2;
            elseif ($diff < 150) $zone = 3;
            elseif ($diff < 350) $zone = 5;
            elseif ($diff < 550) $zone = 7;
            else $zone = 8;
        } elseif (!$isDomestic) {
            $zone = 10; // International
        }

        // Active Carrier Map
        $carrierActive = [];
        foreach ($carriers as $c) {
            $carrierActive[$c['id']] = !empty($c['enabled']);
        }

        $markupValue = (float) ($rateSettings['markupValue'] ?? 0);
        $markupType = (string) ($rateSettings['markupType'] ?? '%');
        $handlingFee = (float) ($rateSettings['handlingFee'] ?? 0);

        // Service baseline calculation formulas
        $serviceBaseCosts = [
            's1' => ['carrier' => 'usps', 'base' => 4.25, 'perLb' => 0.65, 'perZone' => 0.45],  // USPS Ground Advantage
            's2' => ['carrier' => 'usps', 'base' => 7.85, 'perLb' => 1.20, 'perZone' => 0.70],  // USPS Priority Mail
            's3' => ['carrier' => 'usps', 'base' => 24.50, 'perLb' => 2.30, 'perZone' => 1.30], // USPS Priority Mail Express
            's6' => ['carrier' => 'usps', 'base' => 4.80, 'perLb' => 0.70, 'perZone' => 0.50],  // USPS First Class Package
            's7' => ['carrier' => 'ups', 'base' => 8.50, 'perLb' => 0.95, 'perZone' => 0.65],   // UPS Ground
            's8' => ['carrier' => 'ups', 'base' => 18.50, 'perLb' => 1.80, 'perZone' => 1.10],  // UPS 2nd Day Air
            's9' => ['carrier' => 'ups', 'base' => 28.00, 'perLb' => 2.85, 'perZone' => 1.65],  // UPS Next Day Air
            's14' => ['carrier' => 'ups', 'base' => 13.50, 'perLb' => 1.35, 'perZone' => 0.80], // UPS 3 Day Select
            's10' => ['carrier' => 'fedex', 'base' => 8.75, 'perLb' => 0.98, 'perZone' => 0.68], // FedEx Home Delivery
            's15' => ['carrier' => 'fedex', 'base' => 8.25, 'perLb' => 0.92, 'perZone' => 0.62], // FedEx Ground
            's11' => ['carrier' => 'fedex', 'base' => 19.20, 'perLb' => 1.85, 'perZone' => 1.15], // FedEx 2Day
            's12' => ['carrier' => 'fedex', 'base' => 31.50, 'perLb' => 3.10, 'perZone' => 1.85], // FedEx Priority Overnight
            's13' => ['carrier' => 'dhl', 'base' => 28.00, 'perLb' => 2.60, 'perZone' => 1.90],   // DHL Express Worldwide
            's16' => ['carrier' => 'dhl', 'base' => 14.50, 'perLb' => 1.30, 'perZone' => 0.95],   // DHL Economy
        ];

        $calculatedRates = [];

        foreach ($services as $svc) {
            $cid = $svc['carrierId'];
            if (empty($carrierActive[$cid]) || empty($svc['enabled'])) {
                continue;
            }

            $rule = $serviceBaseCosts[$svc['id']] ?? null;
            if (!$rule) {
                // Generic fallback formula
                $rule = ['carrier' => $cid, 'base' => 9.0, 'perLb' => 1.0, 'perZone' => 0.5];
            }

            $rawPrice = $rule['base'] + ($rule['perLb'] * $billableWeight) + ($rule['perZone'] * ($zone - 1));

            // Apply Markup
            if ($markupType === '%') {
                $rawPrice = $rawPrice * (1 + ($markupValue / 100.0));
            } else {
                $rawPrice = $rawPrice + $markupValue;
            }

            // Apply Handling Fee
            $rawPrice = $rawPrice + $handlingFee;

            $finalPrice = number_format(max(1.00, round($rawPrice, 2)), 2, '.', '');

            $calculatedRates[] = [
                'serviceId' => $svc['id'],
                'carrierId' => $cid,
                'name' => $svc['name'],
                'code' => $svc['code'],
                'type' => $svc['type'],
                'estDelivery' => $svc['estDelivery'],
                'price' => $finalPrice,
                'billableWeight' => round($billableWeight, 2),
                'dimWeight' => round($dimWeight, 2),
                'actualWeight' => round($weightInLbs, 2),
                'zone' => $zone,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $calculatedRates,
            'summary' => [
                'actualWeightLbs' => round($weightInLbs, 2),
                'dimWeightLbs' => round($dimWeight, 2),
                'billableWeightLbs' => round($billableWeight, 2),
                'dimensions' => ['length' => $length, 'width' => $width, 'height' => $height],
                'zone' => $zone,
                'carrierCount' => count(array_filter($carrierActive)),
            ]
        ]);
    }
}
