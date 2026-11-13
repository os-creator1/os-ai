<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Voice\Inbound\ForwardDestinationPolicy;
use App\Library\Voice\Inbound\ForwardVerifier;
use App\Library\Voice\Inbound\InboundCallingGate;
use App\Library\Workspace\WorkspaceManagementAuthority;
use App\Models\BusinessInboundCallSetting;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Settings -> Calling: how a Business's inbound calls are routed (browser / forward to one verified personal phone /
 * browser first, then phone). Tenancy -> CRM entitlement first (a foreign or un-entitled Business is a 404). Seeing the page
 * needs only ordinary back-office access; CHANGING routing needs the manage_inbound_calling authority (the Workspace owner
 * or an explicit grant) via WorkspaceManagementAuthority, which is refused outright during a View-As session.
 *
 * Available to every Business whenever the platform is operational (InboundCallingGate: global emergency switch + telephony
 * stack); there is no per-Business approval. When it is not operational the page says so and every control is disabled.
 * The forwarding number is never rendered back after it is saved (only its last four digits), and it cannot be dialled
 * until verified.
 */
class BusinessCallingSettingsController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    public function __construct(
        private readonly WorkspaceManagementAuthority $authority,
        private readonly InboundCallingGate $gate,
        private readonly ForwardVerifier $verifier,
    ) {
    }

    public function show(string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Crm->value);
        $this->authorize('access_backend');

        $setting = BusinessInboundCallSetting::query()->where('business_id', $business->id)->first();
        $number = $setting?->forward_number;

        return view('customer.business.calling.show', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'platformEnabled' => $this->gate->platformEnabled(),
            'canManage' => $this->authority->mayManage($workspace, 'manage_inbound_calling'),
            'mode' => $setting?->mode ?? BusinessInboundCallSetting::MODE_OFF,
            'ringSecs' => $setting?->browser_ring_secs ?? (int) config('messaging.voice.inbound.browser_ring_secs_default'),
            'ringMin' => (int) config('messaging.voice.inbound.browser_ring_secs_min'),
            'ringMax' => (int) config('messaging.voice.inbound.browser_ring_secs_max'),
            'forwardLast4' => $number !== null ? substr(preg_replace('/\D/', '', (string) $number), -4) : null,
            'forwardVerified' => $setting?->forwardVerified() ?? false,
            'codePending' => $setting?->verify_expires_at?->isFuture() === true && $setting->verify_code_hash !== null,
        ]);
    }

    public function update(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [$workspace, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Crm->value);
        $this->authorizeManage($workspace);

        $data = $request->validate([
            'mode' => ['required', Rule::in(BusinessInboundCallSetting::MODES)],
            'browser_ring_secs' => ['required', 'integer', 'min:' . (int) config('messaging.voice.inbound.browser_ring_secs_min'), 'max:' . (int) config('messaging.voice.inbound.browser_ring_secs_max')],
        ]);

        // Choosing a routing mode needs the calling platform to be operational (turning it off is always allowed).
        if ($data['mode'] !== BusinessInboundCallSetting::MODE_OFF && ! $this->gate->available($business)) {
            return $this->back($workspaceUid, $businessUid)->withErrors(['mode' => 'Calling is temporarily unavailable. Please try again shortly.']);
        }

        $existing = BusinessInboundCallSetting::query()->where('business_id', $business->id)->first();
        $wantsForward = in_array($data['mode'], [BusinessInboundCallSetting::MODE_FORWARD, BusinessInboundCallSetting::MODE_BROWSER_THEN_FORWARD], true);
        if ($wantsForward && ($existing === null || ! $existing->forwardVerified())) {
            return $this->back($workspaceUid, $businessUid)->withErrors(['mode' => 'Verify a personal phone number before choosing a forwarding option.']);
        }

        BusinessInboundCallSetting::query()->updateOrCreate(['business_id' => $business->id], [
            'mode' => $data['mode'], 'browser_ring_secs' => (int) $data['browser_ring_secs'], 'updated_by_user_id' => (int) Auth::id(),
        ]);

        return $this->back($workspaceUid, $businessUid)->with('status', 'Calling settings saved.');
    }

    public function sendVerification(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [$workspace, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Crm->value);
        $this->authorizeManage($workspace);

        $data = $request->validate(['phone' => ['required', 'string', 'max:32']]);
        $result = $this->verifier->start($business, (int) Auth::id(), $data['phone']);

        if (! $result['ok']) {
            return $this->back($workspaceUid, $businessUid)->withErrors(['phone' => $this->message((string) $result['code'])]);
        }

        return $this->back($workspaceUid, $businessUid)->with('status', 'We are calling that number now with a 6-digit code. Enter it below.');
    }

    public function confirmVerification(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [$workspace, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Crm->value);
        $this->authorizeManage($workspace);

        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $result = $this->verifier->confirm($business, $data['code']);

        return $result['ok']
            ? $this->back($workspaceUid, $businessUid)->with('status', 'Phone number verified.')
            : $this->back($workspaceUid, $businessUid)->withErrors(['code' => $this->message((string) $result['code'])]);
    }

    public function removeForward(string $workspaceUid, string $businessUid): RedirectResponse
    {
        [$workspace, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Crm->value);
        $this->authorizeManage($workspace);

        $setting = BusinessInboundCallSetting::query()->where('business_id', $business->id)->first();
        if ($setting !== null) {
            $setting->forceFill([
                'forward_number' => null, 'forward_number_hash' => null, 'forward_verified_hash' => null, 'forward_verified_at' => null,
                'verify_code' => null, 'verify_code_hash' => null, 'verify_expires_at' => null, 'verify_attempts' => 0,
                // Never leave a forwarding mode pointing at a number that no longer exists.
                'mode' => $setting->usesBrowser() && $setting->mode !== BusinessInboundCallSetting::MODE_FORWARD ? BusinessInboundCallSetting::MODE_BROWSER : BusinessInboundCallSetting::MODE_OFF,
                'updated_by_user_id' => (int) Auth::id(),
            ])->save();
        }

        return $this->back($workspaceUid, $businessUid)->with('status', 'Forwarding number removed.');
    }

    private function authorizeManage($workspace): void
    {
        if (! $this->authority->mayManage($workspace, 'manage_inbound_calling')) {
            throw new AuthorizationException('You do not have permission to change calling settings.');
        }
    }

    private function back(string $workspaceUid, string $businessUid): RedirectResponse
    {
        return redirect()->route('customer.workspaces.businesses.calling.show', [$workspaceUid, $businessUid]);
    }

    private function message(string $code): string
    {
        return match ($code) {
            'unavailable' => 'Calling is temporarily unavailable. Please try again shortly.',
            'number_not_voice_ready' => 'This business needs an active calling-ready number first.',
            'rate_limited' => 'Too many verification calls. Try again tomorrow.',
            'dial_failed' => 'We could not place the verification call. Try again shortly.',
            'code_expired' => 'That code expired. Request a new one.',
            'too_many_attempts' => 'Too many wrong codes. Request a new one.',
            'wrong_code' => 'That code is not correct.',
            default => ForwardDestinationPolicy::message($code),
        };
    }
}
