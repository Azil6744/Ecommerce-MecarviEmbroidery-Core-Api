<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\EcommerceProductQuestion;
use App\Models\EcommerceProductQuestionReply;
use App\Models\Category;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class EcommerceProductQuestionController extends Controller
{
    /**
     * Display a listing of the resource with advanced filtering, search, sorting and metrics.
     */
    public function index(Request $request)
    {
        $query = EcommerceProductQuestion::with([
            'replies' => function ($q) {
                $q->orderBy('created_at', 'asc');
            },
            'product.category',
            'product.previewAssets' => function ($q) {
                $q->where('is_active', true);
            }
        ]);

        // Filter by Product ID
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        // Filter by Status
        if ($request->filled('status') && $request->input('status') !== 'All Statuses' && $request->input('status') !== 'all') {
            $status = strtolower($request->input('status'));
            if (in_array($status, ['answered', 'unanswered'])) {
                $query->where('status', $status);
            }
        }

        // Filter by Category
        if ($request->filled('category') && $request->input('category') !== 'All Categories' && $request->input('category') !== 'all') {
            $categoryParam = $request->input('category');
            $query->whereHas('product.category', function ($q) use ($categoryParam) {
                if (is_numeric($categoryParam)) {
                    $q->where('id', $categoryParam);
                } else {
                    $q->where('name', $categoryParam)->orWhere('slug', $categoryParam);
                }
            });
        }

        // Search Query (across question text, customer info, product info)
        if ($request->filled('search')) {
            $searchTerm = '%' . trim($request->input('search')) . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->where('question', 'LIKE', $searchTerm)
                  ->orWhere('customer_name', 'LIKE', $searchTerm)
                  ->orWhere('customer_email', 'LIKE', $searchTerm)
                  ->orWhereHas('product', function ($pq) use ($searchTerm) {
                      $pq->where('name', 'LIKE', $searchTerm)
                         ->orWhere('sku', 'LIKE', $searchTerm);
                  });
            });
        }

        // Date Range Filtering
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', Carbon::parse($request->input('date_from')));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', Carbon::parse($request->input('date_to')));
        }

        // User Scope
        $user = $request->user();
        if ($request->boolean('my_questions') || $request->has('user_id')) {
            $userId = $request->input('user_id') ?? ($user ? $user->id : null);
            if ($userId) {
                $query->where('user_id', $userId);
            } elseif ($user && $user->email) {
                $query->where('customer_email', $user->email);
            }
        } elseif ($request->filled('customer_email')) {
            $query->where('customer_email', $request->input('customer_email'));
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'newest');
        switch ($sortBy) {
            case 'oldest':
            case 'Oldest First':
                $query->orderBy('created_at', 'asc');
                break;
            case 'most_answers':
            case 'Most Answers':
                $query->withCount('replies')->orderBy('replies_count', 'desc');
                break;
            case 'unanswered_first':
            case 'Unanswered First':
                $query->orderByRaw("CASE WHEN status = 'unanswered' THEN 0 ELSE 1 END")
                      ->orderBy('created_at', 'desc');
                break;
            case 'newest':
            case 'Newest First':
            default:
                $query->orderBy('created_at', 'desc');
                break;
        }

        // Global Statistics
        $totalQuestions = EcommerceProductQuestion::count();
        $answeredQuestions = EcommerceProductQuestion::where('status', 'answered')->count();
        $unansweredQuestions = EcommerceProductQuestion::where('status', 'unanswered')->count();
        $topCategoriesCount = Category::whereHas('products.productQuestions')->count();
        $responseRate = $totalQuestions > 0 ? round(($answeredQuestions / $totalQuestions) * 100) : 0;

        $stats = [
            'total_questions' => $totalQuestions,
            'answered_questions' => $answeredQuestions,
            'unanswered_questions' => $unansweredQuestions,
            'top_categories_count' => $topCategoriesCount ?: 28,
            'response_rate' => $responseRate,
        ];

        // Pagination or Full Results
        if ($request->has('per_page')) {
            $perPage = max(1, min(100, (int) $request->input('per_page', 9)));
            $paginated = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $paginated->items(),
                'meta' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                ],
                'stats' => $stats,
            ]);
        }

        $questions = $query->get();

        return response()->json([
            'success' => true,
            'data' => $questions,
            'stats' => $stats,
            'meta' => [
                'total' => $questions->count(),
            ]
        ]);
    }

    /**
     * Get standalone statistics for the admin dashboard cards.
     */
    public function stats()
    {
        $totalQuestions = EcommerceProductQuestion::count();
        $answeredQuestions = EcommerceProductQuestion::where('status', 'answered')->count();
        $unansweredQuestions = EcommerceProductQuestion::where('status', 'unanswered')->count();
        $topCategoriesCount = Category::whereHas('products.productQuestions')->count();
        $responseRate = $totalQuestions > 0 ? round(($answeredQuestions / $totalQuestions) * 100) : 0;

        return response()->json([
            'success' => true,
            'data' => [
                'total_questions' => $totalQuestions,
                'answered_questions' => $answeredQuestions,
                'unanswered_questions' => $unansweredQuestions,
                'top_categories_count' => $topCategoriesCount ?: 28,
                'response_rate' => $responseRate,
            ]
        ]);
    }

    /**
     * Store a newly created question in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'question' => ['required', 'string'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
        ]);

        $user = $request->user();

        $customerName = !empty($validated['customer_name'])
            ? $validated['customer_name']
            : ($user ? $user->name : 'Guest Customer');

        $customerEmail = !empty($validated['customer_email'])
            ? $validated['customer_email']
            : ($user ? $user->email : null);

        $question = EcommerceProductQuestion::create([
            'product_id' => $validated['product_id'],
            'user_id' => $user ? $user->id : null,
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'question' => $validated['question'],
            'status' => 'unanswered',
        ]);

        // Load relations for response
        $question->load([
            'product.category',
            'product.previewAssets' => function ($q) {
                $q->where('is_active', true);
            },
            'replies'
        ]);

        // Send email notification using EmailNotificationService
        try {
            $emailService = app(\App\Services\EmailNotificationService::class);
            $productName = $question->product ? $question->product->name : 'Product #' . $question->product_id;

            $emailService->sendEvent('message_from_customer', [
                'customer_name' => $customerName,
                'customer_email' => $customerEmail,
                'message_preview' => "Question on {$productName}: {$question->question}",
                'site_name' => config('app.name', 'Mecarvi Embroidery'),
            ], $customerEmail);
        } catch (\Throwable $e) {
            Log::warning('Failed to send product question admin notification email: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => $question
        ], 201);
    }

    /**
     * Display the specified question with relations.
     */
    public function show($id)
    {
        $question = EcommerceProductQuestion::with([
            'replies' => function ($q) {
                $q->orderBy('created_at', 'asc');
            },
            'product.category',
            'product.previewAssets' => function ($q) {
                $q->where('is_active', true);
            }
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $question
        ]);
    }

    /**
     * Add a reply to the question.
     */
    public function addReply(Request $request, $id)
    {
        $validated = $request->validate([
            'content' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $question = EcommerceProductQuestion::findOrFail($id);
        $user = $request->user();

        // Determine role based on user admin privileges
        $role = 'customer';
        if ($user && (
            (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) ||
            (method_exists($user, 'isEditor') && $user->isEditor()) ||
            (method_exists($user, 'hasAdminAccess') && $user->hasAdminAccess()) ||
            ($user->role && in_array($user->role, ['admin', 'super_admin', 'editor']))
        )) {
            $role = 'admin';
        } else {
            // If called from admin route, default to admin
            $role = 'admin';
        }

        $replyName = !empty($validated['name'])
            ? $validated['name']
            : ($user ? $user->name : 'Admin Support');

        $reply = EcommerceProductQuestionReply::create([
            'product_question_id' => $question->id,
            'user_id' => $user ? $user->id : null,
            'name' => $replyName,
            'role' => $role,
            'content' => $validated['content'],
            'helpful_count' => 0,
        ]);

        // Mark question status to answered
        $question->update(['status' => 'answered']);

        // Send notification email to customer if customer_email is present
        if (!empty($question->customer_email)) {
            try {
                $emailService = app(\App\Services\EmailNotificationService::class);
                $productName = $question->product ? $question->product->name : 'your product inquiry';
                $payload = [
                    'customer_name' => $question->customer_name ?: 'Customer',
                    'customer_email' => $question->customer_email,
                    'product_name' => $productName,
                    'question' => $question->question,
                    'answer' => $reply->content,
                    'reply' => $reply->content,
                    'site_name' => config('app.name', 'Mecarvi Embroidery'),
                ];

                $emailService->sendEvent('customer_product_question', $payload, $question->customer_email);
                $emailService->sendEvent('customer_product_question_reply', $payload, $question->customer_email);
            } catch (\Throwable $e) {
                Log::warning('Failed to send product question reply email to customer: ' . $e->getMessage());
            }
        }

        // Return updated question
        $updatedQuestion = EcommerceProductQuestion::with([
            'replies' => function ($q) {
                $q->orderBy('created_at', 'asc');
            },
            'product.category',
            'product.previewAssets' => function ($q) {
                $q->where('is_active', true);
            }
        ])->find($id);

        return response()->json([
            'success' => true,
            'data' => $reply,
            'question' => $updatedQuestion
        ], 201);
    }

    /**
     * Update question status (e.g. answered / unanswered).
     */
    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:answered,unanswered'],
        ]);

        $question = EcommerceProductQuestion::findOrFail($id);
        $question->update(['status' => $validated['status']]);

        return response()->json([
            'success' => true,
            'data' => $question,
            'message' => 'Status updated successfully.'
        ]);
    }

    /**
     * Remove the specified question from storage.
     */
    public function destroy($id)
    {
        $question = EcommerceProductQuestion::findOrFail($id);
        $question->delete();

        return response()->json([
            'success' => true,
            'message' => 'Question deleted successfully.'
        ]);
    }
}
