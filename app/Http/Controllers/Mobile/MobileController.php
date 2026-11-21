<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Library\Mobile\MobileBusinessAccess;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Shared base for /api/mobile/v1. Errors are always {"error":{"code","message"}} with a real HTTP status.
 * Any tenancy failure is the one 404 `not_found`, so a probe learns nothing from which link refused it.
 */
abstract class MobileController extends Controller
{
    protected function fail(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status)->header('Cache-Control', 'no-store');
    }

    protected function notFound(): JsonResponse
    {
        return $this->fail('not_found', 'Not found.', 404);
    }

    protected function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * @return array{0: Workspace, 1: Business}|null
     */
    protected function tenant(Request $request, string $workspaceUid, string $businessUid, string $feature, ?string $permission): ?array
    {
        // Gates and the Location ACL read Auth::user(); make the token's user the one they see.
        Auth::setUser($this->actor($request));

        return app(MobileBusinessAccess::class)->resolve($this->actor($request), $workspaceUid, $businessUid, $feature, $permission);
    }
}
