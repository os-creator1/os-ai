<?php

namespace App\Library\Voice\Inbound;

use App\Library\Workspace\BusinessRouteAccess;
use App\Models\Business;
use App\Models\BusinessInboundCallSetting;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * What a page needs to take inbound browser calls: the endpoints, and only for a user who may. Decided on the server per
 * request, so a page for a Business not set to ring browsers, a user without the calling
 * permission, or a View-As session never receives a softphone that registers for calls. Every endpoint it names
 * re-checks tenancy, entitlement and permission itself.
 */
final class InboundCallingSurface
{
    public function __construct(private readonly InboundCallingGate $gate, private readonly BusinessRouteAccess $routes)
    {
    }

    /** @return array<string, string>|null */
    public function inboundUrls(string $workspaceUid, Business $business, ?User $user): ?array
    {
        if ($user === null || ! $this->gate->available($business) || ! Gate::forUser($user)->allows('place_voice_calls')
            || $this->routes->viewedBusinessIdFor((int) $user->id) !== null) {
            return null;
        }

        $setting = BusinessInboundCallSetting::query()->where('business_id', $business->id)->first();
        if ($setting === null || ! $setting->usesBrowser()) {
            return null;
        }

        $scope = [$workspaceUid, $business->uid];

        return [
            'presenceUrl' => route('customer.workspaces.businesses.calls.presence', $scope),
            'incomingUrl' => route('customer.workspaces.businesses.calls.incoming', $scope),
            'answerUrl' => route('customer.workspaces.businesses.calls.answer', [...$scope, '__CALL__']),
            'declineUrl' => route('customer.workspaces.businesses.calls.decline', [...$scope, '__CALL__']),
        ];
    }

    /** The complete softphone config for pages that are not a contact profile. @return array<string, mixed>|null */
    public function browserCalling(string $workspaceUid, Business $business, ?User $user): ?array
    {
        $inbound = $this->inboundUrls($workspaceUid, $business, $user);
        if ($inbound === null) {
            return null;
        }

        $scope = [$workspaceUid, $business->uid];

        return [
            'sessionUrl' => route('customer.workspaces.businesses.calls.browser-session', $scope),
            'placeUrl' => route('customer.workspaces.businesses.calls.store', $scope),
            'statusUrl' => route('customer.workspaces.businesses.calls.show', [...$scope, '__CALL__']),
            'hangupUrl' => route('customer.workspaces.businesses.calls.hangup', [...$scope, '__CALL__']),
            'inbound' => $inbound,
        ];
    }
}
