<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\EcommerceDisputeType;
use App\Models\EcommerceDisputeQuestion;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EcommerceDisputeTypeController extends Controller
{
    /**
     * Public / Customer listing: only active types with their questions.
     */
    public function index(Request $request)
    {
        $types = EcommerceDisputeType::query()
            ->where('is_active', true)
            ->with(['questions' => function ($query) {
                $query->orderBy('sort_order', 'asc');
            }])
            ->orderBy('sort_order', 'asc')
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $types,
        ]);
    }

    /**
     * Admin listing: all types (active & inactive) with question counts.
     */
    public function adminIndex(Request $request)
    {
        $query = EcommerceDisputeType::query()->with(['questions' => function ($q) {
            $q->orderBy('sort_order', 'asc');
        }])->withCount('questions', 'disputes');

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active') && $request->input('is_active') !== '' && $request->input('is_active') !== null) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $types = $query->orderBy('sort_order', 'asc')->orderBy('id', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $types,
        ]);
    }

    /**
     * Admin: create dispute type with dynamic questions.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:100', 'unique:ecommerce_dispute_types,code'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'questions' => ['nullable', 'array'],
            'questions.*.label' => ['required', 'string', 'max:255'],
            'questions.*.field_key' => ['nullable', 'string', 'max:100'],
            'questions.*.input_type' => ['required', 'string', Rule::in(['text', 'textarea', 'number', 'select', 'radio', 'checkbox', 'date', 'file'])],
            'questions.*.placeholder' => ['nullable', 'string', 'max:255'],
            'questions.*.help_text' => ['nullable', 'string', 'max:1000'],
            'questions.*.options' => ['nullable', 'array'],
            'questions.*.options.*' => ['nullable', 'string', 'max:255'],
            'questions.*.is_required' => ['nullable', 'boolean'],
            'questions.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $code = !empty($validated['code'])
            ? Str::slug($validated['code'], '_')
            : Str::slug($validated['name'], '_');

        // Ensure unique code
        $originalCode = $code;
        $counter = 1;
        while (EcommerceDisputeType::where('code', $code)->exists()) {
            $code = "{$originalCode}_{$counter}";
            $counter++;
        }

        $disputeType = DB::transaction(function () use ($validated, $code) {
            $type = EcommerceDisputeType::create([
                'name' => $validated['name'],
                'code' => $code,
                'description' => $validated['description'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
                'sort_order' => $validated['sort_order'] ?? 0,
            ]);

            if (!empty($validated['questions'])) {
                foreach ($validated['questions'] as $idx => $q) {
                    $fieldKey = !empty($q['field_key'])
                        ? Str::slug($q['field_key'], '_')
                        : Str::slug($q['label'], '_');

                    if (empty($fieldKey)) {
                        $fieldKey = 'field_' . ($idx + 1);
                    }

                    $type->questions()->create([
                        'label' => $q['label'],
                        'field_key' => $fieldKey,
                        'input_type' => $q['input_type'],
                        'placeholder' => $q['placeholder'] ?? null,
                        'help_text' => $q['help_text'] ?? null,
                        'options' => !empty($q['options']) ? array_values(array_filter($q['options'])) : null,
                        'is_required' => $q['is_required'] ?? false,
                        'sort_order' => $q['sort_order'] ?? $idx + 1,
                    ]);
                }
            }

            return $type->load('questions');
        });

        return response()->json([
            'success' => true,
            'message' => 'Dispute type created successfully.',
            'data' => $disputeType,
        ], 201);
    }

    /**
     * Show a single dispute type with questions.
     */
    public function show($id)
    {
        $type = EcommerceDisputeType::with(['questions' => function ($q) {
            $q->orderBy('sort_order', 'asc');
        }])->find($id);

        if (!$type) {
            return response()->json([
                'success' => false,
                'message' => 'Dispute type not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $type,
        ]);
    }

    /**
     * Admin: update dispute type and sync questions.
     */
    public function update(Request $request, $id)
    {
        $type = EcommerceDisputeType::find($id);
        if (!$type) {
            return response()->json([
                'success' => false,
                'message' => 'Dispute type not found.',
            ], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:100', Rule::unique('ecommerce_dispute_types', 'code')->ignore($type->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'questions' => ['nullable', 'array'],
            'questions.*.id' => ['nullable', 'integer'],
            'questions.*.label' => ['required', 'string', 'max:255'],
            'questions.*.field_key' => ['nullable', 'string', 'max:100'],
            'questions.*.input_type' => ['required', 'string', Rule::in(['text', 'textarea', 'number', 'select', 'radio', 'checkbox', 'date', 'file'])],
            'questions.*.placeholder' => ['nullable', 'string', 'max:255'],
            'questions.*.help_text' => ['nullable', 'string', 'max:1000'],
            'questions.*.options' => ['nullable', 'array'],
            'questions.*.options.*' => ['nullable', 'string', 'max:255'],
            'questions.*.is_required' => ['nullable', 'boolean'],
            'questions.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($type, $validated) {
            $updateData = [];
            if (isset($validated['name'])) $updateData['name'] = $validated['name'];
            if (isset($validated['code'])) $updateData['code'] = Str::slug($validated['code'], '_');
            if (array_key_exists('description', $validated)) $updateData['description'] = $validated['description'];
            if (isset($validated['is_active'])) $updateData['is_active'] = $validated['is_active'];
            if (isset($validated['sort_order'])) $updateData['sort_order'] = $validated['sort_order'];

            $type->update($updateData);

            if (isset($validated['questions'])) {
                // Delete existing and re-insert to keep order clean
                $type->questions()->delete();
                foreach ($validated['questions'] as $idx => $q) {
                    $fieldKey = !empty($q['field_key'])
                        ? Str::slug($q['field_key'], '_')
                        : Str::slug($q['label'], '_');

                    if (empty($fieldKey)) {
                        $fieldKey = 'field_' . ($idx + 1);
                    }

                    $type->questions()->create([
                        'label' => $q['label'],
                        'field_key' => $fieldKey,
                        'input_type' => $q['input_type'],
                        'placeholder' => $q['placeholder'] ?? null,
                        'help_text' => $q['help_text'] ?? null,
                        'options' => !empty($q['options']) ? array_values(array_filter($q['options'])) : null,
                        'is_required' => $q['is_required'] ?? false,
                        'sort_order' => $q['sort_order'] ?? $idx + 1,
                    ]);
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Dispute type updated successfully.',
            'data' => $type->fresh(['questions']),
        ]);
    }

    /**
     * Admin: delete dispute type.
     */
    public function destroy($id)
    {
        $type = EcommerceDisputeType::find($id);
        if (!$type) {
            return response()->json([
                'success' => false,
                'message' => 'Dispute type not found.',
            ], 404);
        }

        $type->delete();

        return response()->json([
            'success' => true,
            'message' => 'Dispute type deleted successfully.',
        ]);
    }

    /**
     * Admin: quick toggle active status.
     */
    public function toggleStatus($id)
    {
        $type = EcommerceDisputeType::find($id);
        if (!$type) {
            return response()->json([
                'success' => false,
                'message' => 'Dispute type not found.',
            ], 404);
        }

        $type->is_active = !$type->is_active;
        $type->save();

        return response()->json([
            'success' => true,
            'message' => 'Status updated successfully.',
            'data' => $type,
        ]);
    }
}
