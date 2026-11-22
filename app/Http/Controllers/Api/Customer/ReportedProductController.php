<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Admin\ProductReportController;
use App\Models\ProductReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ReportedProductController extends Controller
{
    /**
     * Reports belonging to the signed-in customer: linked by user id, or filed with the
     * account's email. Identity comes only from the session, never from request input.
     */
    private function ownedReports(Request $request): Builder
    {
        $user = $request->user();
        $email = strtolower(trim((string) ($user->email ?? '')));

        return ProductReport::query()->where(function ($q) use ($user, $email) {
            $q->where('user_id', $user->id);
            if ($email !== '') {
                $q->orWhereRaw('LOWER(customer_email) = ?', [$email]);
            }
        });
    }

    private function findOwnedReport(Request $request, $id): ProductReport
    {
        return $this->ownedReports($request)
            ->where(function ($q) use ($id) {
                $q->where('id', $id)
                    ->orWhere('report_code', $id)
                    ->orWhere('report_code', '#' . $id);
            })
            ->firstOrFail();
    }

    public function index(Request $request)
    {
        $allReports = $this->ownedReports($request)->orderBy('created_at', 'desc')->get();

        $transformed = $allReports->map(fn (ProductReport $report) => ProductReportController::transformReport($report))->values();

        $stats = ProductReportController::statsFor($allReports);

        return response()->json([
            'success' => true,
            'stats' => $stats,
            'data' => $transformed,
        ]);
    }

    public function show(Request $request, $id)
    {
        $report = $this->findOwnedReport($request, $id);

        return response()->json([
            'success' => true,
            'data' => ProductReportController::transformReport($report),
        ]);
    }

    public function reply(Request $request, $id)
    {
        $request->validate([
            'message' => 'required|string',
        ]);

        $report = $this->findOwnedReport($request, $id);

        $replies = $report->customer_replies ?? [];
        $userName = $request->user()->name ?: ($report->customer_name ?? 'Customer');

        $newReply = [
            'id' => 'reply_' . uniqid(),
            'author' => 'Customer',
            'user_name' => $userName,
            'date' => now()->format('M d, Y h:i A'),
            'message' => $request->input('message'),
        ];

        $replies[] = $newReply;
        $report->customer_replies = $replies;
        $report->save();

        return response()->json([
            'success' => true,
            'message' => 'Reply sent successfully',
            'data' => ProductReportController::transformReport($report),
        ]);
    }
}
