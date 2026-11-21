<?php

namespace App\Http\Controllers\Mobile;

use App\Library\Mobile\MobileBusinessAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/** The account/business switcher: which Businesses this signed-in person may open, and what each offers them. */
class MobileBusinessController extends MobileController
{
    public function index(Request $request, MobileBusinessAccess $access): JsonResponse
    {
        $user = $this->actor($request);
        Auth::setUser($user);

        $rows = [];

        foreach ($access->accessibleBusinesses($user) as [$workspace, $business]) {
            $rows[] = [
                'workspace_uid' => $workspace->uid,
                'workspace_name' => $workspace->name,
                'business_uid' => $business->uid,
                'name' => $business->name,
                'features' => [
                    'conversations' => Gate::forUser($user)->allows('chat_box') && $access->entitled($user, $workspace, $business, MobileBusinessAccess::FEATURE_CONVERSATIONS),
                    'contacts' => Gate::forUser($user)->allows('view_contact') && $access->entitled($user, $workspace, $business, MobileBusinessAccess::FEATURE_CRM),
                ],
            ];
        }

        return response()->json(['data' => [
            'user' => ['uid' => $user->uid, 'name' => trim($user->first_name . ' ' . $user->last_name), 'email' => $user->email],
            'businesses' => $rows,
        ]])->header('Cache-Control', 'no-store');
    }
}
