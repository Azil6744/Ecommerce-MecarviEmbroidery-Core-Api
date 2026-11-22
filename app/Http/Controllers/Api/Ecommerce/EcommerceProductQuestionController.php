<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\EcommerceProductQuestion;
use App\Models\EcommerceProductQuestionReply;
use App\Models\EcommerceQaReport;
use App\Models\ModerationActivityLog;
use App\Models\Product;
use App\Models\User;
use App\Services\Moderation\ContentFilter;
use App\Services\Moderation\ModerationLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Product questions & answers.
 *
 * - Only signed-in customers can ask, answer or report; identity always comes from the session.
 * - Questions and customer answers stay hidden until an admin approves them. Staff answers
 *   are published straight away (answering a pending question approves it).
 * - Admins can approve / hide / delete questions, edit staff answers, hide or delete any
 *   answer, and handle reports. Every admin action is written to the moderation log.
 */
class EcommerceProductQuestionController extends Controller
{
    private const STAFF_ROLES = ['super_admin', 'admin', 'editor'];

    private function isAdmin(?User $user): bool
    {
        return $user && method_exists($user, 'hasRole') && $user->hasRole(self::STAFF_ROLES);
    }

    /** Admin listing/moderation: an admin route used by an admin. */
    private function isAdminRequest(Request $request): bool
    {
        return $request->is('*admin/*') && $this->isAdmin($request->user());
    }

    private function log(Request $request, string $subjectType, int $subjectId, string $action, array $details = [], ?int $productId = null): void
    {
        ModerationLogger::record($request, ModerationActivityLog::FEATURE_QUESTION, $subjectType, $subjectId, $action, $details, $productId);
    }

    /** Replies a viewer may see: everything for admins, otherwise approved ones plus their own. */
    private function replyScope(bool $admin, ?User $viewer): \Closure
    {
        return function ($q) use ($admin, $viewer) {
            if (! $admin) {
                $q->where(function ($rq) use ($viewer) {
                    $rq->where('moderation_status', EcommerceProductQuestion::MODERATION_APPROVED);
                    if ($viewer) {
                        $rq->orWhere('user_id', $viewer->id);
                    }
                });
            }
            $q->orderBy('created_at', 'asc');
        };
    }

    private function relations(bool $admin, ?User $viewer): array
    {
        $relations = [
            'replies' => $this->replyScope($admin, $viewer),
            'product.category',
            'product.previewAssets' => function ($q) {
                $q->where('is_active', true);
            },
        ];
        if ($admin) {
            $relations[] = 'user';
        }

        return $relations;
    }

    /** Questions owned by the signed-in customer (by account, or older ones by account email). */
    private function scopeOwned(Builder $query, User $user): void
    {
        $email = strtolower(trim((string) $user->email));
        $query->where(function ($q) use ($user, $email) {
            $q->where('user_id', $user->id);
            if ($email !== '') {
                $q->orWhere(function ($eq) use ($email) {
                    $eq->whereNull('user_id')->whereRaw('LOWER(customer_email) = ?', [$email]);
                });
            }
            $q->orWhereHas('replies', function ($rq) use ($user) {
                $rq->where('user_id', $user->id);
            });
        });
    }

    private function ownsQuestion(?User $user, EcommerceProductQuestion $question): bool
    {
        if (! $user) {
            return false;
        }
        if (! empty($question->user_id)) {
            return (int) $question->user_id === (int) $user->id;
        }
        $email = strtolower(trim((string) $user->email));

        return $email !== '' && $email === strtolower(trim((string) $question->customer_email));
    }

    /** Customer emails stay private outside the admin panel. */
    private function publicView($questions)
    {
        $hide = function ($question) {
            $question->makeHidden(['customer_email', 'normalized_question']);
        };
        if ($questions instanceof EcommerceProductQuestion) {
            $hide($questions);
        } else {
            collect($questions)->each($hide);
        }

        return $questions;
    }

    /**
     * Display a listing of the resource with advanced filtering, search, sorting and metrics.
     */
    public function index(Request $request)
    {
        $admin = $this->isAdminRequest($request);
        $user = $request->user();

        $query = EcommerceProductQuestion::with($this->relations($admin, $user));

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        // Answered / unanswered
        if ($request->filled('status') && $request->input('status') !== 'All Statuses' && strtolower((string) $request->input('status')) !== 'all') {
            $status = strtolower((string) $request->input('status'));
            if (in_array($status, ['answered', 'unanswered'], true)) {
                $query->where('status', $status);
            } elseif ($admin && in_array($status, EcommerceProductQuestion::MODERATION_STATUSES, true)) {
                $query->where('moderation_status', $status);
            }
        }

        // Pending / approved / hidden (admin)
        if ($admin && $request->filled('moderation_status') && strtolower((string) $request->input('moderation_status')) !== 'all') {
            $moderation = strtolower((string) $request->input('moderation_status'));
            if (in_array($moderation, EcommerceProductQuestion::MODERATION_STATUSES, true)) {
                $query->where('moderation_status', $moderation);
            }
        }

        if ($request->filled('category') && $request->input('category') !== 'All Categories' && strtolower((string) $request->input('category')) !== 'all') {
            $categoryParam = $request->input('category');
            $query->whereHas('product.category', function ($q) use ($categoryParam) {
                if (is_numeric($categoryParam)) {
                    $q->where('id', $categoryParam);
                } else {
                    $q->whereRaw('LOWER(name) = ?', [strtolower((string) $categoryParam)])
                      ->orWhereRaw('LOWER(slug) = ?', [strtolower((string) $categoryParam)]);
                }
            });
        }

        if ($request->filled('search')) {
            $searchTerm = '%' . strtolower(trim((string) $request->input('search'))) . '%';
            $query->where(function ($q) use ($searchTerm, $admin) {
                $q->whereRaw('LOWER(question) LIKE ?', [$searchTerm])
                  ->orWhereRaw('LOWER(COALESCE(customer_name, \'\')) LIKE ?', [$searchTerm])
                  ->orWhereHas('product', function ($pq) use ($searchTerm) {
                      $pq->whereRaw('LOWER(name) LIKE ?', [$searchTerm])
                         ->orWhereRaw('LOWER(COALESCE(sku, \'\')) LIKE ?', [$searchTerm]);
                  });
                if ($admin) {
                    $q->orWhereRaw('LOWER(COALESCE(customer_email, \'\')) LIKE ?', [$searchTerm])
                      ->orWhereHas('replies', function ($rq) use ($searchTerm) {
                          $rq->whereRaw('LOWER(content) LIKE ?', [$searchTerm]);
                      });
                } else {
                    $q->orWhereHas('replies', function ($rq) use ($searchTerm) {
                        $rq->where('moderation_status', EcommerceProductQuestion::MODERATION_APPROVED)
                           ->whereRaw('LOWER(content) LIKE ?', [$searchTerm]);
                    });
                }
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', Carbon::parse($request->input('date_from')));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', Carbon::parse($request->input('date_to')));
        }

        // Who may see what
        if ($request->boolean('my_questions') || $request->boolean('strict_user') || $request->has('user_id')) {
            // "My questions" in the customer panel: the signed-in customer's own, any state.
            if ($user) {
                $this->scopeOwned($query, $user);
            } else {
                $query->whereRaw('1 = 0');
            }
        } elseif (! $admin) {
            $query->where('moderation_status', EcommerceProductQuestion::MODERATION_APPROVED);
        }

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
            case 'pending_first':
            case 'Pending First':
                $query->orderByRaw("CASE WHEN moderation_status = 'pending' THEN 0 ELSE 1 END")
                      ->orderBy('created_at', 'desc');
                break;
            case 'newest':
            case 'Newest First':
            default:
                $query->orderBy('created_at', 'desc');
                break;
        }

        $stats = $admin ? $this->computeStats() : $this->computePublicStats($request);

        if ($request->has('per_page')) {
            $perPage = max(1, min(100, (int) $request->input('per_page', 9)));
            $paginated = $query->paginate($perPage);
            $items = $paginated->items();

            return response()->json([
                'success' => true,
                'data' => $admin ? $items : $this->publicView($items),
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
            'data' => $admin ? $questions : $this->publicView($questions),
            'stats' => $stats,
            'meta' => [
                'total' => $questions->count(),
            ],
        ]);
    }

    /**
     * Get standalone statistics for the admin dashboard cards.
     */
    public function stats(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => $this->isAdminRequest($request) ? $this->computeStats() : $this->computePublicStats($request),
        ]);
    }

    protected function computeStats(): array
    {
        $totalQuestions = EcommerceProductQuestion::count();
        $answeredQuestions = EcommerceProductQuestion::where('status', 'answered')->count();
        $unansweredQuestions = EcommerceProductQuestion::where('status', 'unanswered')->count();
        $totalProductsWithQuestions = EcommerceProductQuestion::distinct('product_id')->count('product_id');
        $responseRate = $totalQuestions > 0 ? round(($answeredQuestions / $totalQuestions) * 100) : 0;

        return [
            'total_questions' => $totalQuestions,
            'answered_questions' => $answeredQuestions,
            'unanswered_questions' => $unansweredQuestions,
            'pending_questions' => EcommerceProductQuestion::where('moderation_status', EcommerceProductQuestion::MODERATION_PENDING)->count(),
            'hidden_questions' => EcommerceProductQuestion::where('moderation_status', EcommerceProductQuestion::MODERATION_HIDDEN)->count(),
            'pending_answers' => EcommerceProductQuestionReply::where('moderation_status', EcommerceProductQuestion::MODERATION_PENDING)->count(),
            'open_reports' => EcommerceQaReport::where('status', EcommerceQaReport::STATUS_OPEN)->count(),
            'total_products_with_questions' => $totalProductsWithQuestions,
            'top_categories_count' => $totalProductsWithQuestions,
            'response_rate' => $responseRate,
        ];
    }

    /** Counts over what the public can see (approved questions, optionally one product). */
    protected function computePublicStats(Request $request): array
    {
        $base = EcommerceProductQuestion::where('moderation_status', EcommerceProductQuestion::MODERATION_APPROVED);
        if ($request->filled('product_id')) {
            $base->where('product_id', $request->input('product_id'));
        }
        $total = (clone $base)->count();
        $answered = (clone $base)->where('status', 'answered')->count();

        return [
            'total_questions' => $total,
            'answered_questions' => $answered,
            'unanswered_questions' => $total - $answered,
            'total_products_with_questions' => (clone $base)->distinct('product_id')->count('product_id'),
            'top_categories_count' => 0,
            'response_rate' => $total > 0 ? round(($answered / $total) * 100) : 0,
        ];
    }

    /**
     * Ask a question (signed-in customers only). It is published once an admin approves it.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'reason' => 'login_required',
                'message' => 'Please sign in to ask a question.',
            ], 401);
        }

        $validated = $request->validate([
            'product_id' => ['required'],
            'question' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $rawProductId = trim((string) $validated['product_id']);
        $product = ctype_digit($rawProductId) ? Product::find((int) $rawProductId) : null;
        if (! $product && $rawProductId !== '') {
            $product = Product::where('sku', $rawProductId)->first();
        }
        if (! $product) {
            return response()->json([
                'success' => false,
                'message' => 'Selected product was not found.',
            ], 422);
        }

        $text = trim($validated['question']);
        if ($problem = ContentFilter::check(['question' => $text])) {
            return response()->json(['success' => false, 'reason' => 'content_rejected', 'message' => $problem], 422);
        }

        $normalized = EcommerceProductQuestion::normalizeText($text);
        $duplicate = EcommerceProductQuestion::where('product_id', $product->id)
            ->where('normalized_question', $normalized)
            ->where('moderation_status', '!=', EcommerceProductQuestion::MODERATION_HIDDEN)
            ->first();
        if ($duplicate) {
            $own = $this->ownsQuestion($user, $duplicate);
            $approved = $duplicate->moderation_status === EcommerceProductQuestion::MODERATION_APPROVED;

            return response()->json([
                'success' => false,
                'reason' => 'duplicate_question',
                'existing_question_id' => ($own || $approved) ? $duplicate->id : null,
                'message' => $own
                    ? 'You have already asked this question about this product.'
                    : ($approved
                        ? 'This question has already been asked about this product. Please see the existing answers.'
                        : 'This question has already been asked about this product and is waiting to be answered.'),
            ], 409);
        }

        $customerName = ! empty($user->name) ? $user->name : 'Customer';
        $customerEmail = strtolower(trim((string) $user->email)) ?: null;

        $question = EcommerceProductQuestion::create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'question' => $text,
            'normalized_question' => $normalized,
            'status' => 'unanswered',
            'moderation_status' => EcommerceProductQuestion::MODERATION_PENDING,
        ]);

        $question->load($this->relations(false, $user));

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
            'message' => 'Your question was submitted and will appear once it has been approved.',
            'data' => $question,
        ], 201);
    }

    /**
     * Display the specified question with relations.
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $admin = $this->isAdmin($user);

        $question = EcommerceProductQuestion::with($this->relations($admin, $user))->findOrFail($id);

        if (! $admin
            && $question->moderation_status !== EcommerceProductQuestion::MODERATION_APPROVED
            && ! $this->ownsQuestion($user, $question)) {
            abort(404);
        }

        return response()->json([
            'success' => true,
            'data' => $admin ? $question : $this->publicView($question),
        ]);
    }

    /**
     * Add a reply. Staff answers are published at once; customer answers wait for approval.
     */
    public function addReply(Request $request, $id)
    {
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:3000'],
            'name' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'string', 'in:admin,customer'],
        ]);

        $question = EcommerceProductQuestion::findOrFail($id);
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'reason' => 'login_required',
                'message' => 'Please sign in to answer questions.',
            ], 401);
        }

        // Only a signed-in admin can post an official answer. The requested role is a hint
        // that is honoured only for admins, so customers cannot pose as staff.
        $isAdminUser = $this->isAdmin($user);
        $role = $isAdminUser && ($request->is('*admin/*') || ($validated['role'] ?? null) === 'admin') ? 'admin' : 'customer';

        if ($role === 'customer') {
            if ($question->moderation_status !== EcommerceProductQuestion::MODERATION_APPROVED && ! $this->ownsQuestion($user, $question)) {
                abort(404);
            }
            if ($problem = ContentFilter::check(['answer' => $validated['content']])) {
                return response()->json(['success' => false, 'reason' => 'content_rejected', 'message' => $problem], 422);
            }
            $replyName = ! empty($user->name) ? $user->name : 'Customer';
        } else {
            $replyName = ! empty($validated['name']) ? $validated['name'] : (! empty($user->name) ? $user->name : 'Admin Support');
        }

        $reply = EcommerceProductQuestionReply::create([
            'product_question_id' => $question->id,
            'user_id' => $user->id,
            'name' => $replyName,
            'role' => $role,
            'content' => trim($validated['content']),
            'helpful_count' => 0,
            'moderation_status' => $role === 'admin'
                ? EcommerceProductQuestion::MODERATION_APPROVED
                : EcommerceProductQuestion::MODERATION_PENDING,
        ]);

        if ($role === 'admin') {
            $updates = ['status' => 'answered'];
            if ($question->moderation_status === EcommerceProductQuestion::MODERATION_PENDING) {
                // Answering a question publishes it.
                $updates['moderation_status'] = EcommerceProductQuestion::MODERATION_APPROVED;
                $this->log($request, 'question', (int) $question->id, 'approved', ['via' => 'answered'], (int) $question->product_id);
            }
            $question->update($updates);
            $this->log($request, 'reply', (int) $reply->id, 'answer_posted', [
                'question_id' => $question->id,
                'content' => $reply->content,
            ], (int) $question->product_id);
        }

        if ($role === 'admin' && ! empty($question->customer_email)) {
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

        $updatedQuestion = EcommerceProductQuestion::with($this->relations($isAdminUser, $user))->find($id);

        return response()->json([
            'success' => true,
            'message' => $role === 'admin' ? 'Answer posted.' : 'Your answer was submitted and will appear once it has been approved.',
            'data' => $reply,
            'question' => $isAdminUser ? $updatedQuestion : $this->publicView($updatedQuestion),
        ], 201);
    }

    /**
     * Update question status (answered / unanswered).
     */
    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:answered,unanswered'],
        ]);

        $question = EcommerceProductQuestion::findOrFail($id);
        $from = $question->status;
        $question->update(['status' => $validated['status']]);

        if ($from !== $validated['status']) {
            $this->log($request, 'question', (int) $question->id, 'marked_' . $validated['status'], ['from' => $from], (int) $question->product_id);
        }

        return response()->json([
            'success' => true,
            'data' => $question,
            'message' => 'Status updated successfully.',
        ]);
    }

    /**
     * Approve, hide, or send a question back to pending (admin).
     */
    public function moderate(Request $request, $id)
    {
        $validated = $request->validate([
            'moderation_status' => ['required', 'in:' . implode(',', EcommerceProductQuestion::MODERATION_STATUSES)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $question = EcommerceProductQuestion::findOrFail($id);
        $from = $question->moderation_status;
        $question->update(['moderation_status' => $validated['moderation_status']]);

        if ($from !== $validated['moderation_status']) {
            $action = [
                EcommerceProductQuestion::MODERATION_APPROVED => 'approved',
                EcommerceProductQuestion::MODERATION_HIDDEN => 'hidden',
                EcommerceProductQuestion::MODERATION_PENDING => 'returned_to_pending',
            ][$validated['moderation_status']];
            $this->log($request, 'question', (int) $question->id, $action, array_filter([
                'from' => $from,
                'note' => $validated['note'] ?? null,
            ]), (int) $question->product_id);
        }

        return response()->json([
            'success' => true,
            'message' => 'Question updated.',
            'data' => $question->fresh($this->relations(true, $request->user())),
        ]);
    }

    /**
     * Edit a staff answer's text, or approve / hide any answer (admin). Customer answers
     * can be approved, hidden or deleted, but their wording is never changed.
     */
    public function updateReply(Request $request, $id, $replyId)
    {
        $validated = $request->validate([
            'content' => ['sometimes', 'required', 'string', 'max:3000'],
            'moderation_status' => ['sometimes', 'required', 'in:' . implode(',', EcommerceProductQuestion::MODERATION_STATUSES)],
        ]);

        $question = EcommerceProductQuestion::findOrFail($id);
        $reply = EcommerceProductQuestionReply::where('product_question_id', $question->id)->findOrFail($replyId);

        if (array_key_exists('content', $validated) && $reply->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Customer answers cannot be edited. You can approve, hide or delete them.',
            ], 422);
        }

        $updates = [];
        if (array_key_exists('content', $validated) && trim($validated['content']) !== $reply->content) {
            $this->log($request, 'reply', (int) $reply->id, 'answer_edited', [
                'question_id' => $question->id,
                'before' => $reply->content,
                'after' => trim($validated['content']),
            ], (int) $question->product_id);
            $updates['content'] = trim($validated['content']);
            $updates['edited_by'] = $request->user()?->id;
            $updates['edited_at'] = now();
        }
        if (array_key_exists('moderation_status', $validated) && $validated['moderation_status'] !== $reply->moderation_status) {
            $this->log($request, 'reply', (int) $reply->id, 'answer_' . $validated['moderation_status'], [
                'question_id' => $question->id,
                'from' => $reply->moderation_status,
            ], (int) $question->product_id);
            $updates['moderation_status'] = $validated['moderation_status'];
        }

        if ($updates) {
            $reply->update($updates);
            $this->syncAnsweredStatus($question);
        }

        return response()->json([
            'success' => true,
            'message' => 'Answer updated.',
            'data' => $reply->fresh(),
            'question' => $question->fresh($this->relations(true, $request->user())),
        ]);
    }

    /**
     * Delete an answer (admin).
     */
    public function destroyReply(Request $request, $id, $replyId)
    {
        $question = EcommerceProductQuestion::findOrFail($id);
        $reply = EcommerceProductQuestionReply::where('product_question_id', $question->id)->findOrFail($replyId);

        $this->log($request, 'reply', (int) $reply->id, 'answer_deleted', [
            'question_id' => $question->id,
            'author' => $reply->name,
            'role' => $reply->role,
            'content' => $reply->content,
        ], (int) $question->product_id);

        $reply->delete();
        $this->syncAnsweredStatus($question);

        return response()->json([
            'success' => true,
            'message' => 'Answer deleted.',
            'question' => $question->fresh($this->relations(true, $request->user())),
        ]);
    }

    /** A question counts as answered while it has a visible staff answer. */
    private function syncAnsweredStatus(EcommerceProductQuestion $question): void
    {
        $hasStaffAnswer = EcommerceProductQuestionReply::where('product_question_id', $question->id)
            ->where('role', 'admin')
            ->where('moderation_status', EcommerceProductQuestion::MODERATION_APPROVED)
            ->exists();
        $status = $hasStaffAnswer ? 'answered' : 'unanswered';
        if ($question->status !== $status) {
            $question->update(['status' => $status]);
        }
    }

    /**
     * Remove the specified question (admin).
     */
    public function destroy(Request $request, $id)
    {
        $question = EcommerceProductQuestion::withCount('replies')->findOrFail($id);

        $this->log($request, 'question', (int) $question->id, 'deleted', [
            'customer' => $question->customer_name,
            'question' => $question->question,
            'replies_deleted' => $question->replies_count,
        ], (int) $question->product_id);

        $question->delete();

        return response()->json([
            'success' => true,
            'message' => 'Question deleted successfully.',
        ]);
    }

    /**
     * Report an inappropriate question or answer (signed-in customers).
     */
    public function report(Request $request, $id)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'reason' => 'login_required',
                'message' => 'Please sign in to report content.',
            ], 401);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'in:' . implode(',', array_keys(EcommerceQaReport::REASONS))],
            'comment' => ['nullable', 'string', 'max:1000'],
            'reply_id' => ['nullable', 'integer'],
        ]);

        $question = EcommerceProductQuestion::findOrFail($id);
        if ($question->moderation_status !== EcommerceProductQuestion::MODERATION_APPROVED) {
            abort(404);
        }

        $replyId = $validated['reply_id'] ?? null;
        if ($replyId) {
            $reply = EcommerceProductQuestionReply::where('product_question_id', $question->id)
                ->where('moderation_status', EcommerceProductQuestion::MODERATION_APPROVED)
                ->find($replyId);
            if (! $reply) {
                abort(404);
            }
        }

        $alreadyOpen = EcommerceQaReport::where('user_id', $user->id)
            ->where('product_question_id', $question->id)
            ->where(function ($q) use ($replyId) {
                $replyId ? $q->where('reply_id', $replyId) : $q->whereNull('reply_id');
            })
            ->where('status', EcommerceQaReport::STATUS_OPEN)
            ->exists();
        if ($alreadyOpen) {
            return response()->json([
                'success' => false,
                'reason' => 'already_reported',
                'message' => 'You have already reported this. Our team is reviewing it.',
            ], 409);
        }

        $report = EcommerceQaReport::create([
            'product_question_id' => $question->id,
            'reply_id' => $replyId,
            'user_id' => $user->id,
            'reason' => $validated['reason'],
            'comment' => $validated['comment'] ?? null,
            'status' => EcommerceQaReport::STATUS_OPEN,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Thank you. Our team will review this report.',
            'data' => ['id' => $report->id, 'status' => $report->status],
        ], 201);
    }

    /**
     * Reports on questions and answers (admin).
     */
    public function reports(Request $request)
    {
        $query = EcommerceQaReport::with([
            'question.product:id,name,sku',
            'reply',
            'user:id,name,email',
        ])->latest('id');

        $status = strtolower((string) $request->input('status', 'open'));
        if ($status !== 'all' && in_array($status, [EcommerceQaReport::STATUS_OPEN, EcommerceQaReport::STATUS_RESOLVED, EcommerceQaReport::STATUS_DISMISSED], true)) {
            $query->where('status', $status);
        }

        $page = $query->paginate(max(1, min(100, (int) $request->input('per_page', 25))));

        return response()->json([
            'success' => true,
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'reasons' => EcommerceQaReport::REASONS,
            'counts' => [
                'open' => EcommerceQaReport::where('status', EcommerceQaReport::STATUS_OPEN)->count(),
                'resolved' => EcommerceQaReport::where('status', EcommerceQaReport::STATUS_RESOLVED)->count(),
                'dismissed' => EcommerceQaReport::where('status', EcommerceQaReport::STATUS_DISMISSED)->count(),
            ],
        ]);
    }

    /**
     * Resolve, dismiss or reopen a Q&A report (admin).
     */
    public function updateReport(Request $request, $reportId)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:open,resolved,dismissed'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $report = EcommerceQaReport::with('question')->findOrFail($reportId);
        $from = $report->status;

        $report->update([
            'status' => $validated['status'],
            'handled_by' => $validated['status'] === EcommerceQaReport::STATUS_OPEN ? null : $request->user()?->id,
            'handled_at' => $validated['status'] === EcommerceQaReport::STATUS_OPEN ? null : now(),
        ]);

        if ($from !== $validated['status']) {
            $this->log($request, 'qa_report', (int) $report->id, 'report_' . $validated['status'], array_filter([
                'from' => $from,
                'question_id' => $report->product_question_id,
                'reply_id' => $report->reply_id,
                'reason' => $report->reason,
                'note' => $validated['note'] ?? null,
            ]), $report->question ? (int) $report->question->product_id : null);
        }

        return response()->json([
            'success' => true,
            'message' => 'Report updated.',
            'data' => $report->fresh(['question.product:id,name,sku', 'reply', 'user:id,name,email']),
        ]);
    }
}
