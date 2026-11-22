<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ModerationActivityLog;
use Illuminate\Http\Request;

/**
 * Read-only admin view of moderation actions on reviews, Q&A and reported products.
 */
class ModerationActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'feature' => ['nullable', 'string', 'in:review,question,product_report'],
            'subject_type' => ['nullable', 'string', 'max:60'],
            'subject_id' => ['nullable', 'integer'],
            'product_id' => ['nullable', 'integer'],
            'admin_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:60'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = ModerationActivityLog::query()->latest('id');

        foreach (['feature', 'subject_type', 'subject_id', 'product_id', 'admin_id', 'action'] as $field) {
            if (isset($validated[$field]) && $validated[$field] !== '') {
                $query->where($field, $validated[$field]);
            }
        }

        $page = $query->paginate((int) ($validated['per_page'] ?? 50));

        return response()->json([
            'success' => true,
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
