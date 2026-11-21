<?php

namespace App\Http\Controllers\Mobile;

use App\Library\Mobile\MobileBusinessAccess;
use App\Library\Voice\ConversationCalling;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 1 calling is CAPABILITY ONLY: it reports whether this person could call from this Business, reusing
 * ConversationCalling (platform switches, Telnyx/BYO readiness, the `place_voice_calls` authority). It never
 * contacts Telnyx, mints a credential or places a call. The Phase 2 session/answer endpoints will wrap the
 * existing BrowserCredentialProvisioner / VoiceCallController paths (docs/mobile/ARCHITECTURE.md).
 */
class MobileCallingController extends MobileController
{
    public function capability(Request $request, string $workspaceUid, string $businessUid, ConversationCalling $calling): JsonResponse
    {
        $resolved = $this->tenant($request, $workspaceUid, $businessUid, MobileBusinessAccess::FEATURE_CRM, null);

        if ($resolved === null) {
            return $this->notFound();
        }

        [, $business] = $resolved;
        $unavailable = $calling->unavailableReason($business);
        $unauthorized = $calling->unauthorizedReason($business);

        return response()->json(['data' => [
            'available' => $unavailable === null && $unauthorized === null,
            'reason' => $unavailable ?? $unauthorized,
            'phase' => 'capability-only',
        ]])->header('Cache-Control', 'no-store');
    }
}
