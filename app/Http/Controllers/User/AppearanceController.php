<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Library\Appearance\Appearance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Saves the signed-in user's own appearance preference. It writes one column on
 * the acting user and nothing else: no Business, Agency or workspace setting.
 */
class AppearanceController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'appearance' => ['required', 'string', Rule::in(Appearance::values())],
        ]);

        $request->user()->forceFill(['appearance' => $validated['appearance']])->save();

        return response()->json(['status' => 'success', 'appearance' => $validated['appearance']]);
    }
}
