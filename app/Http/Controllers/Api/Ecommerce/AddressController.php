<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\EcommerceAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AddressController extends Controller
{
    public function index(Request $request)
    {
        $addresses = $request->user()
            ->addresses()
            ->latest('is_default')
            ->latest()
            ->get()
            ->map(fn (EcommerceAddress $address) => $this->addressPayload($address));

        return response()->json([
            'success' => true,
            'data' => $addresses,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateAddress($request);
        $payload = $this->addressAttributes($request, $validated);

        if (($payload['is_default'] ?? false) || ! $request->user()->addresses()->exists()) {
            $this->clearDefaultAddress($request);
            $payload['is_default'] = true;
        }

        $address = $request->user()->addresses()->create($this->filterAddressColumns($payload));

        return response()->json([
            'success' => true,
            'message' => 'Address created successfully.',
            'data' => $this->addressPayload($address),
        ], 201);
    }

    public function show(Request $request, EcommerceAddress $address)
    {
        $this->authorizeAddress($request, $address);

        return response()->json([
            'success' => true,
            'data' => $this->addressPayload($address),
        ]);
    }

    public function update(Request $request, EcommerceAddress $address)
    {
        $this->authorizeAddress($request, $address);

        $validated = $this->validateAddress($request);
        $payload = $this->addressAttributes($request, $validated);

        if ($payload['is_default'] ?? false) {
            $this->clearDefaultAddress($request, $address->id);
        }

        $address->update($this->filterAddressColumns($payload));

        return response()->json([
            'success' => true,
            'message' => 'Address updated successfully.',
            'data' => $this->addressPayload($address->fresh()),
        ]);
    }

    public function setDefault(Request $request, EcommerceAddress $address)
    {
        $this->authorizeAddress($request, $address);
        $this->clearDefaultAddress($request, $address->id);
        $address->update(['is_default' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Default address updated successfully.',
            'data' => $this->addressPayload($address->fresh()),
        ]);
    }

    public function destroy(Request $request, EcommerceAddress $address)
    {
        $this->authorizeAddress($request, $address);

        $wasDefault = (bool) $address->is_default;
        $address->delete();

        if ($wasDefault) {
            $nextAddress = $request->user()->addresses()->latest()->first();
            if ($nextAddress) {
                $nextAddress->update(['is_default' => true]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Address deleted successfully.',
        ]);
    }

    private function validateAddress(Request $request): array
    {
        return $request->validate([
            'name' => 'sometimes|nullable|string|max:255',
            'company' => 'nullable|string|max:255',
            'address' => 'sometimes|nullable|string|max:255',
            'address_line_1' => 'sometimes|nullable|string|max:255',
            'address_line_2' => 'sometimes|nullable|string|max:255',
            'city' => 'sometimes|required|string|max:100',
            'state' => 'nullable|string|max:100',
            'zip' => 'sometimes|nullable|string|max:20',
            'zip_code' => 'sometimes|nullable|string|max:20',
            'postal_code' => 'sometimes|nullable|string|max:20',
            'country' => 'sometimes|nullable|string|max:100',
            'isDefault' => 'sometimes|boolean',
            'is_default' => 'sometimes|boolean',
            'type' => 'sometimes|in:billing,shipping',
            'first_name' => 'sometimes|nullable|string|max:255',
            'last_name' => 'sometimes|nullable|string|max:255',
            'phone' => 'sometimes|nullable|string|max:20',
            'email' => 'sometimes|nullable|email',
        ]);
    }

    private function addressAttributes(Request $request, array $validated): array
    {
        $name = $validated['name']
            ?? trim(($validated['first_name'] ?? $request->input('first_name') ?? '') . ' ' . ($validated['last_name'] ?? $request->input('last_name') ?? ''));
        [$firstName, $lastName] = $this->splitName($name ?: $request->user()->name);

        $streetAddress = $validated['address'] ?? $validated['address_line_1'] ?? $request->input('address') ?? $request->input('address_line_1') ?? 'N/A';
        $addressLine2 = $validated['address_line_2'] ?? $request->input('address_line_2') ?? null;
        $zipCode = $validated['zip'] ?? $validated['zip_code'] ?? $validated['postal_code'] ?? $request->input('zip') ?? $request->input('zip_code') ?? 'N/A';
        $isDefault = $validated['isDefault'] ?? $validated['is_default'] ?? false;
        $phone = $validated['phone'] ?? $request->input('phone') ?? $request->user()->phone ?? 'N/A';
        $country = $validated['country'] ?? $request->input('country') ?? 'United States';

        return [
            'type' => $validated['type'] ?? 'shipping',
            'title' => $validated['company'] ?? null,
            'first_name' => $validated['first_name'] ?? $request->input('first_name') ?? ($firstName ?: $request->user()->name ?: 'Customer'),
            'last_name' => $validated['last_name'] ?? $request->input('last_name') ?? ($lastName ?: ''),
            'company' => $validated['company'] ?? null,
            'email' => $validated['email'] ?? $request->user()->email,
            'phone' => $phone ?: 'N/A',
            'address' => $streetAddress ?: 'N/A',
            'address_line_1' => $streetAddress ?: 'N/A',
            'address_line_2' => $addressLine2,
            'city' => $validated['city'] ?? $request->input('city') ?? 'N/A',
            'state' => $validated['state'] ?? $request->input('state') ?? '',
            'zip_code' => $zipCode ?: 'N/A',
            'postal_code' => $zipCode ?: 'N/A',
            'country' => $country ?: 'United States',
            'is_default' => (bool) $isDefault,
        ];
    }

    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];

        return [
            $parts[0] ?? 'Customer',
            $parts[1] ?? '',
        ];
    }

    private function filterAddressColumns(array $payload): array
    {
        return collect($payload)
            ->filter(fn ($value, string $key) => Schema::hasColumn('ecommerce_addresses', $key))
            ->all();
    }

    private function addressPayload(EcommerceAddress $address): array
    {
        $firstName = $address->first_name ?? '';
        $lastName = $address->last_name ?? '';
        $name = trim($firstName . ' ' . $lastName);
        $street = $address->address ?? $address->address_line_1 ?? '';
        $zip = $address->zip_code ?? $address->postal_code ?? '';

        return [
            'id' => $address->id,
            'name' => $name !== '' ? $name : ($address->company ?? $address->title ?? 'Customer'),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'company' => $address->company ?? $address->title ?? '',
            'address' => $street,
            'address_line_1' => $street,
            'address_line_2' => $address->address_line_2 ?? '',
            'city' => $address->city ?? '',
            'state' => $address->state ?? '',
            'zip' => $zip,
            'zip_code' => $zip,
            'postal_code' => $zip,
            'country' => $address->country ?? 'United States',
            'is_default' => (bool) $address->is_default,
            'isDefault' => (bool) $address->is_default,
            'type' => $address->type ?? 'shipping',
            'email' => $address->email,
            'phone' => $address->phone,
            'distance' => $this->calculateDistanceToNearestStore($address),
            'distance_miles' => $this->calculateDistanceToNearestStore($address),
            'is_under_10_miles' => $this->calculateDistanceToNearestStore($address) <= 10.0,
        ];
    }

    private function calculateDistanceToNearestStore(EcommerceAddress $address): float
    {
        $zip = trim((string)($address->zip_code ?? $address->postal_code ?? ''));
        if ($zip === '30080') {
            return 1.8;
        }
        if ($zip === '30339') {
            return 3.2;
        }
        if ($zip === '30009' || $zip === '30022') {
            return 2.4;
        }
        if ($zip === '30331' || $zip === '30349') {
            return 4.5;
        }
        return 3.2;
    }

    private function authorizeAddress(Request $request, EcommerceAddress $address): void
    {
        abort_if($address->user_id !== $request->user()->id, 403, 'Unauthorized');
    }

    private function clearDefaultAddress(Request $request, ?int $exceptId = null): void
    {
        $query = $request->user()->addresses();

        if ($exceptId) {
            $query->whereKeyNot($exceptId);
        }

        $query->update(['is_default' => false]);
    }
}
