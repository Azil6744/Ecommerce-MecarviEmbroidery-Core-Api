<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use App\Services\GiftCardPanelService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * User / business panel "Gift Cards" page.
 */
class GiftCardPanelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => 'nullable|string|max:120',
            'status' => 'nullable|string|in:all,active,pending,partially_redeemed,redeemed,expired,cancelled,voided,deactivated',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        return response()->json([
            'success' => true,
            'data' => GiftCardPanelService::dashboard($request->user(), $request->input('search'), $request->input('status'), $request->input('from'), $request->input('to')),
        ]);
    }

    public function show(Request $request, string $ref): JsonResponse
    {
        return $this->run(fn () => GiftCardPanelService::details($request->user(), $ref));
    }

    public function generateCode(Request $request, string $ref): JsonResponse
    {
        return $this->run(fn () => GiftCardPanelService::generateCode($request->user(), $ref));
    }

    public function approve(Request $request, string $ref): JsonResponse
    {
        $data = $request->validate([
            'code' => 'nullable|string|regex:/^\d{15}$/',
            'expiry_mode' => 'nullable|in:standard,custom',
            'expires_at' => 'nullable|required_if:expiry_mode,custom|date',
            'internal_note' => 'nullable|string|max:500',
        ]);

        return $this->run(fn () => GiftCardPanelService::approve($request->user(), $ref, $request->ip(), $data), 'Gift card approved and issued.');
    }

    public function decline(Request $request, string $ref): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'required|string|max:255',
            'description' => 'required|string|max:500',
            'internal_note' => 'nullable|string|max:500',
        ]);

        return $this->run(fn () => GiftCardPanelService::decline($request->user(), $ref, $this->clean($data), $request->ip()), 'Gift card order declined.');
    }

    public function void(Request $request, string $ref): JsonResponse
    {
        $data = $request->validate([
            'action_type' => 'required|in:void,reverse',
            'reason' => 'required|string|max:255',
            'description' => 'required|string|max:500',
            'internal_note' => 'nullable|string|max:500',
        ]);

        return $this->run(
            fn () => GiftCardPanelService::void($request->user(), $ref, $this->clean($data), $request->ip()),
            $data['action_type'] === 'reverse' ? 'Gift card reversed and the purchase refunded.' : 'Gift card voided and remaining balance cancelled.'
        );
    }

    public function toggleActive(Request $request, string $ref): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'internal_note' => 'nullable|string|max:500',
        ]);

        return $this->run(fn () => GiftCardPanelService::toggleActive($request->user(), $ref, $this->clean($data), $request->ip()), 'Gift card status updated.');
    }

    private function clean(array $data): array
    {
        return array_map(fn ($v) => is_string($v) ? trim($v) : $v, $data);
    }

    public function addNote(Request $request, string $ref): JsonResponse
    {
        $data = $request->validate(['note' => 'required|string|max:2000']);

        return $this->run(fn () => GiftCardPanelService::addNote($request->user(), $ref, trim($data['note'])), 'Note added.');
    }

    private function run(callable $fn, ?string $message = null): JsonResponse
    {
        try {
            return response()->json(array_filter([
                'success' => true,
                'message' => $message,
                'data' => $fn(),
            ], fn ($v) => $v !== null));
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage() ?: 'Gift card not found.'], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
