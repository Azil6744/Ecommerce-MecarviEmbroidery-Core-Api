<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Ecommerce\GiftCardPanelController;
use App\Http\Controllers\Controller;
use App\Services\GiftCardPanelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin panel "Gift Cards" pages (Customer / Business). Same rows, details and
 * popups as the panel service, run in admin mode: every buyer of the chosen
 * audience, with approve / decline / void / reverse / deactivate enabled.
 * Routes sit behind the admin.only middleware.
 */
class AdminGiftCardPanelController extends Controller
{
    public function __construct(private GiftCardPanelController $panel)
    {
    }

    private function audience(Request $request): string
    {
        return $request->input('audience') === 'business' ? 'business' : 'customer';
    }

    private function asAdmin(Request $request, callable $fn): JsonResponse
    {
        return GiftCardPanelService::forAdmin($this->audience($request), $fn);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->asAdmin($request, fn () => $this->panel->index($request));
    }

    public function show(Request $request, string $ref): JsonResponse
    {
        return $this->asAdmin($request, fn () => $this->panel->show($request, $ref));
    }

    public function generateCode(Request $request, string $ref): JsonResponse
    {
        return $this->asAdmin($request, fn () => $this->panel->generateCode($request, $ref));
    }

    public function approve(Request $request, string $ref): JsonResponse
    {
        return $this->asAdmin($request, fn () => $this->panel->approve($request, $ref));
    }

    public function decline(Request $request, string $ref): JsonResponse
    {
        return $this->asAdmin($request, fn () => $this->panel->decline($request, $ref));
    }

    public function void(Request $request, string $ref): JsonResponse
    {
        return $this->asAdmin($request, fn () => $this->panel->void($request, $ref));
    }

    public function toggleActive(Request $request, string $ref): JsonResponse
    {
        return $this->asAdmin($request, fn () => $this->panel->toggleActive($request, $ref));
    }

    public function addNote(Request $request, string $ref): JsonResponse
    {
        return $this->asAdmin($request, fn () => $this->panel->addNote($request, $ref));
    }
}
