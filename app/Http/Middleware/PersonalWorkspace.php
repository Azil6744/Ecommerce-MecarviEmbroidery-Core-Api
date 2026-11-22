<?php

namespace App\Http\Middleware;

use App\Support\WorkspaceOwner;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Switches notes/todos queries to the authenticated user's personal workspace.
 * Must run after central.auth. Used by the /api/v1/my/notes and /api/v1/my/todos routes.
 */
class PersonalWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Authentication required',
            ], 401);
        }

        WorkspaceOwner::set((int) $user->getKey());

        try {
            return $next($request);
        } finally {
            WorkspaceOwner::set(null);
        }
    }
}
