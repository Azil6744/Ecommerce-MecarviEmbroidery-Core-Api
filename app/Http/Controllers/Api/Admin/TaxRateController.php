<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TaxRate;
use Illuminate\Http\Request;

class TaxRateController extends Controller
{
    /**
     * Display a listing of the tax rates.
     */
    public function index()
    {
        try {
            $count = TaxRate::count();
            if ($count === 0) {
                // Auto seed initial state tax rates matching the design mockup
                $seedRates = [
                    [
                        'state' => 'Alabama',
                        'country' => 'US',
                        'rate' => 4.000,
                        'shipping_taxable' => true,
                        'label' => 'Alabama State Tax',
                        'is_active' => true,
                        'effective_date' => 'Jan 1, 2024',
                    ],
                    [
                        'state' => 'California',
                        'country' => 'US',
                        'rate' => 7.250,
                        'shipping_taxable' => false,
                        'label' => 'California State Tax',
                        'is_active' => true,
                        'effective_date' => 'Jan 1, 2024',
                    ],
                    [
                        'state' => 'Florida',
                        'country' => 'US',
                        'rate' => 7.000,
                        'shipping_taxable' => true,
                        'label' => 'Florida State Tax',
                        'is_active' => true,
                        'effective_date' => 'Jan 1, 2024',
                    ],
                    [
                        'state' => 'New York',
                        'country' => 'US',
                        'rate' => 8.875,
                        'shipping_taxable' => true,
                        'label' => 'New York State Tax',
                        'is_active' => true,
                        'effective_date' => 'Jan 1, 2024',
                    ],
                    [
                        'state' => 'Texas',
                        'country' => 'US',
                        'rate' => 6.250,
                        'shipping_taxable' => false,
                        'label' => 'Texas State Tax',
                        'is_active' => true,
                        'effective_date' => 'Jan 1, 2024',
                    ],
                ];

                foreach ($seedRates as $item) {
                    TaxRate::create($item);
                }
            }

            $rates = TaxRate::orderBy('state', 'asc')->get();
            return response()->json([
                'success' => true,
                'data' => $rates
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch tax rates',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created tax rate.
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'label' => 'nullable|string|max:255',
                'rate' => 'required|numeric|min:0',
                'state' => 'required|string|max:255',
                'country' => 'nullable|string|max:255',
                'shipping_taxable' => 'sometimes|boolean',
                'is_active' => 'sometimes|boolean',
                'effective_date' => 'nullable|string|max:255',
            ]);

            if (empty($validated['label'])) {
                $validated['label'] = ($validated['state'] ?? 'State') . ' Sales Tax';
            }
            if (empty($validated['country'])) {
                $validated['country'] = 'US';
            }
            if (empty($validated['effective_date'])) {
                $validated['effective_date'] = date('M j, Y');
            }

            $rate = TaxRate::create($validated);

            return response()->json([
                'success' => true,
                'data' => $rate,
                'message' => 'Tax rate created successfully'
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create tax rate',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified tax rate.
     */
    public function update(Request $request, $id)
    {
        try {
            $rate = TaxRate::findOrFail($id);

            $validated = $request->validate([
                'label' => 'sometimes|nullable|string|max:255',
                'rate' => 'sometimes|required|numeric|min:0',
                'state' => 'sometimes|required|string|max:255',
                'country' => 'nullable|string|max:255',
                'shipping_taxable' => 'sometimes|boolean',
                'is_active' => 'sometimes|boolean',
                'effective_date' => 'nullable|string|max:255',
            ]);

            $rate->update($validated);

            return response()->json([
                'success' => true,
                'data' => $rate,
                'message' => 'Tax rate updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update tax rate',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified tax rate.
     */
    public function destroy($id)
    {
        try {
            $rate = TaxRate::findOrFail($id);
            $rate->delete();

            return response()->json([
                'success' => true,
                'message' => 'Tax rate deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete tax rate',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
