<?php

namespace App\Listeners\PlatformAutomation;

use App\Enums\PlatformAutomation\PlatformTargetType;
use App\Events\Account\UserAccountStatusChanged;
use App\Events\Business\BusinessCreated;
use App\Events\Business\BusinessStatusChanged;
use App\Events\Calendar\AppointmentScheduled;
use App\Events\Crm\CrmOpportunityCreated;
use App\Events\DocumentPaymentSucceeded;
use App\Events\DocumentSent;
use App\Events\Provider\ProviderReconnectionRequired;
use App\Events\Usage\BusinessSpendingLimitReached;
use App\Events\Usage\BusinessWalletLowBalance;
use App\Events\Workspace\ClientInvitationCreated;
use App\Events\Workspace\WorkspaceMembershipDeactivated;
use App\Events\Workspace\WorkspaceMembershipReactivated;
use App\Events\Workspace\WorkspaceMembershipRoleChanged;
use App\Events\Business\CustomerOnboardingCompleted;
use App\Events\Entitlement\WorkspaceAccessRestored;
use App\Events\Entitlement\WorkspaceEnteredGracePeriod;
use App\Events\Entitlement\WorkspaceLocked;
use App\Events\Entitlement\WorkspacePlanAssigned;
use App\Events\Entitlement\WorkspacePlanChanged;
use App\Events\GoogleBusinessProfile\GoogleBusinessProfileConnected;
use App\Events\GoogleBusinessProfile\GoogleBusinessProfileConnectionRevoked;
use App\Events\GoogleBusinessProfile\GoogleBusinessProfileDisconnected;
use App\Events\Usage\BusinessFundingAttemptFailed;
use App\Events\Usage\BusinessWalletBillingStatusChanged;
use App\Events\Website\WebsitePublished;
use App\Events\Workspace\WorkspaceCreated;
use App\Events\Workspace\WorkspaceDeactivated;
use App\Events\Workspace\WorkspaceReactivated;
use App\Library\PlatformAutomation\PlatformTarget;
use App\Library\PlatformAutomation\PlatformTriggerDispatcher;
use App\Models\CustomerOnboarding;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Maps CANONICAL domain events to Platform triggers. It listens; it never changes
 * what the originating flow does: any failure here is reported and swallowed, so a
 * broken automation can never fail a signup, a payment or a publish.
 *
 * Each handler builds an explicit PlatformTarget from the ids the event carries.
 */
class PlatformTriggerSubscriber
{
    private const TIER_ORDER = ['core' => 1, 'growth' => 2, 'agency' => 3];

    public function __construct(private readonly PlatformTriggerDispatcher $dispatcher)
    {
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            Registered::class => 'onRegistered',
            Verified::class => 'onVerified',
            PasswordReset::class => 'onPasswordReset',
            WorkspaceCreated::class => 'onWorkspaceCreated',
            WorkspaceDeactivated::class => 'onWorkspaceDeactivated',
            WorkspaceReactivated::class => 'onWorkspaceReactivated',
            BusinessCreated::class => 'onBusinessCreated',
            CustomerOnboardingCompleted::class => 'onOnboardingCompleted',
            WorkspacePlanAssigned::class => 'onPlanAssigned',
            WorkspacePlanChanged::class => 'onPlanChanged',
            WorkspaceEnteredGracePeriod::class => 'onGrace',
            WorkspaceAccessRestored::class => 'onRestored',
            WorkspaceLocked::class => 'onLocked',
            BusinessFundingAttemptFailed::class => 'onFundingFailed',
            BusinessWalletBillingStatusChanged::class => 'onWalletStatus',
            WebsitePublished::class => 'onWebsitePublished',
            GoogleBusinessProfileConnected::class => 'onProviderConnected',
            GoogleBusinessProfileDisconnected::class => 'onProviderDisconnected',
            GoogleBusinessProfileConnectionRevoked::class => 'onProviderRevoked',
            ProviderReconnectionRequired::class => 'onAdsReconnectionRequired',
            BusinessWalletLowBalance::class => 'onWalletLow',
            BusinessSpendingLimitReached::class => 'onSpendLimit',
            WorkspaceMembershipRoleChanged::class => 'onRoleChanged',
            WorkspaceMembershipDeactivated::class => 'onMemberDeactivated',
            WorkspaceMembershipReactivated::class => 'onMemberReactivated',
            UserAccountStatusChanged::class => 'onAccountStatusChanged',
            BusinessStatusChanged::class => 'onBusinessStatusChanged',
            ClientInvitationCreated::class => 'onInvitationCreated',
            CrmOpportunityCreated::class => 'onOpportunityCreated',
            AppointmentScheduled::class => 'onAppointmentScheduled',
            DocumentSent::class => 'onDocumentSent',
            DocumentPaymentSucceeded::class => 'onDocumentPaymentSucceeded',
        ];
    }

    public function onRegistered(Registered $e): void
    {
        $this->fire('user.registered', PlatformTarget::user((int) $e->user->getAuthIdentifier()), 'user:' . $e->user->getAuthIdentifier() . ':registered');
    }

    public function onVerified(Verified $e): void
    {
        $this->fire('user.email_verified', PlatformTarget::user((int) $e->user->getAuthIdentifier()), 'user:' . $e->user->getAuthIdentifier() . ':verified');
    }

    public function onPasswordReset(PasswordReset $e): void
    {
        $id = (int) $e->user->getAuthIdentifier();
        $this->fire('user.password_reset_completed', PlatformTarget::user($id), "user:{$id}:pwreset:" . now()->timestamp);
    }

    public function onWorkspaceCreated(WorkspaceCreated $e): void
    {
        $this->fire('workspace.created', PlatformTarget::workspace($e->workspaceId), "workspace:{$e->workspaceId}:created");
    }

    public function onWorkspaceDeactivated(WorkspaceDeactivated $e): void
    {
        $this->fire('workspace.suspended', PlatformTarget::workspace($e->workspaceId), "workspace:{$e->workspaceId}:deactivated:" . now()->timestamp);
    }

    public function onWorkspaceReactivated(WorkspaceReactivated $e): void
    {
        $this->fire('workspace.reactivated', PlatformTarget::workspace($e->workspaceId), "workspace:{$e->workspaceId}:reactivated:" . now()->timestamp);
    }

    public function onBusinessCreated(BusinessCreated $e): void
    {
        $this->fire('business.created', PlatformTarget::business($e->businessId), "business:{$e->businessId}:created");
    }

    public function onOnboardingCompleted(CustomerOnboardingCompleted $e): void
    {
        $businessId = CustomerOnboarding::query()->whereKey($e->onboardingId)->value('business_id');

        if ($businessId !== null) {
            $this->fire('business.onboarding_completed', PlatformTarget::business((int) $businessId), "business:{$businessId}:onboarding_completed");
        }
    }

    public function onPlanAssigned(WorkspacePlanAssigned $e): void
    {
        $this->fire('subscription.activated', PlatformTarget::workspace($e->workspaceId), "workspace:{$e->workspaceId}:assigned:{$e->workspacePlanCatalogId}", ['to_status' => 'assigned']);
    }

    public function onPlanChanged(WorkspacePlanChanged $e): void
    {
        $from = self::TIER_ORDER[(string) DB::table('workspace_plan_catalog')->where('id', $e->fromWorkspacePlanCatalogId)->value('tier')] ?? 0;
        $to = self::TIER_ORDER[(string) DB::table('workspace_plan_catalog')->where('id', $e->toWorkspacePlanCatalogId)->value('tier')] ?? 0;

        if ($from === 0 || $to === 0 || $from === $to) {
            return;
        }

        $this->fire($to > $from ? 'subscription.plan_upgraded' : 'subscription.plan_downgraded', PlatformTarget::workspace($e->workspaceId),
            "workspace:{$e->workspaceId}:plan:{$e->fromWorkspacePlanCatalogId}-{$e->toWorkspacePlanCatalogId}:" . now()->timestamp);
    }

    public function onGrace(WorkspaceEnteredGracePeriod $e): void
    {
        $this->fire('subscription.payment_failed', PlatformTarget::workspace($e->workspaceId), "workspace:{$e->workspaceId}:grace:" . now()->timestamp);
    }

    public function onRestored(WorkspaceAccessRestored $e): void
    {
        $this->fire('subscription.payment_recovered', PlatformTarget::workspace($e->workspaceId), "workspace:{$e->workspaceId}:restored:" . now()->timestamp);
    }

    public function onLocked(WorkspaceLocked $e): void
    {
        $this->fire('subscription.locked', PlatformTarget::workspace($e->workspaceId), "workspace:{$e->workspaceId}:locked:" . now()->timestamp);
    }

    public function onFundingFailed(BusinessFundingAttemptFailed $e): void
    {
        $this->fire('usage.funding_failed', PlatformTarget::business($e->businessId), "funding:{$e->fundingAttemptId}:failed", ['purpose' => $e->purpose]);

        if ($e->purpose === 'auto_recharge') {
            $this->fire('usage.auto_recharge_failed', PlatformTarget::business($e->businessId), "funding:{$e->fundingAttemptId}:auto_recharge_failed");
        }
    }

    public function onWalletLow(BusinessWalletLowBalance $e): void
    {
        // One low-balance episode per marker transition; the timestamp keeps separate episodes separate.
        $this->fire('usage.wallet_low', PlatformTarget::business($e->businessId), "wallet:{$e->businessId}:low:" . now()->timestamp);
    }

    public function onSpendLimit(BusinessSpendingLimitReached $e): void
    {
        $this->fire('usage.spend_limit_reached', PlatformTarget::business($e->businessId), "spend_limit:{$e->businessId}:{$e->reason}:" . now()->format('Ym'), ['reason' => $e->reason]);
    }

    public function onRoleChanged(WorkspaceMembershipRoleChanged $e): void
    {
        $this->fire('membership.role_changed', PlatformTarget::member($e->userId, $e->workspaceId), "membership:{$e->membershipId}:role:{$e->previousRole}-{$e->newRole}:" . now()->timestamp, ['new_role' => $e->newRole]);
    }

    public function onMemberDeactivated(WorkspaceMembershipDeactivated $e): void
    {
        $this->fire('membership.deactivated', PlatformTarget::member($e->userId, $e->workspaceId), "membership:{$e->membershipId}:deactivated:" . now()->timestamp);
    }

    public function onMemberReactivated(WorkspaceMembershipReactivated $e): void
    {
        $this->fire('membership.reactivated', PlatformTarget::member($e->userId, $e->workspaceId), "membership:{$e->membershipId}:reactivated:" . now()->timestamp);
    }

    public function onAccountStatusChanged(UserAccountStatusChanged $e): void
    {
        $this->fire($e->enabled ? 'user.account_enabled' : 'user.account_disabled', PlatformTarget::user($e->userId), "user:{$e->userId}:" . ($e->enabled ? 'enabled' : 'disabled') . ':' . now()->timestamp);
    }

    public function onBusinessStatusChanged(BusinessStatusChanged $e): void
    {
        $trigger = match ($e->toStatus) {
            'inactive' => 'business.suspended',
            'active' => $e->fromStatus === 'inactive' ? 'business.reactivated' : null,
            default => null,
        };

        if ($trigger !== null) {
            $this->fire($trigger, PlatformTarget::business($e->businessId), "business:{$e->businessId}:{$e->toStatus}:" . now()->timestamp);
        }
    }

    public function onInvitationCreated(ClientInvitationCreated $e): void
    {
        $this->fire('invitation.created', PlatformTarget::workspace($e->agencyWorkspaceId), "invitation:{$e->invitationId}:created");
    }

    // -- Product milestones: a canonical event, and the canonical rows prove it is the FIRST. The
    //    occurrence key (one per Business) keeps a re-fire from ever producing a second run.

    public function onOpportunityCreated(CrmOpportunityCreated $e): void
    {
        if (DB::table('crm_opportunities')->where('business_id', $e->businessId)->count() <= 1) {
            $this->fire('product.first_lead', PlatformTarget::business($e->businessId), "business:{$e->businessId}:first_lead");
        }
    }

    public function onAppointmentScheduled(AppointmentScheduled $e): void
    {
        $count = DB::table('appointments as a')->join('business_locations as l', 'l.id', '=', 'a.business_location_id')->where('l.business_id', $e->businessId)->count();

        if ($count <= 1) {
            $this->fire('product.first_booking', PlatformTarget::business($e->businessId), "business:{$e->businessId}:first_booking");
        }
    }

    public function onDocumentSent(DocumentSent $e): void
    {
        $document = DB::table('business_documents')->where('id', $e->documentId)->first(['business_id', 'kind']);

        if ($document === null || $document->kind !== 'proposal') {
            return;
        }

        $sent = DB::table('business_documents')->where('business_id', $document->business_id)->where('kind', 'proposal')->whereNotNull('sent_at')->count();

        if ($sent <= 1) {
            $this->fire('product.first_proposal', PlatformTarget::business((int) $document->business_id), "business:{$document->business_id}:first_proposal");
        }
    }

    public function onDocumentPaymentSucceeded(DocumentPaymentSucceeded $e): void
    {
        $businessId = $e->businessId ?? DB::table('business_documents')->where('id', $e->documentId)->value('business_id');

        if ($businessId !== null && DB::table('business_document_payments')->where('business_id', $businessId)->where('status', 'succeeded')->count() <= 1) {
            $this->fire('product.first_payment', PlatformTarget::business((int) $businessId), "business:{$businessId}:first_payment");
        }
    }

    public function onAdsReconnectionRequired(ProviderReconnectionRequired $e): void
    {
        $this->fire('provider.reconnection_required', PlatformTarget::business($e->businessId, PlatformTargetType::Provider), "{$e->provider}:{$e->businessId}:{$e->state}:" . now()->timestamp);
    }

    public function onWalletStatus(BusinessWalletBillingStatusChanged $e): void
    {
        $this->fire('usage.wallet_status_changed', PlatformTarget::business($e->businessId), "wallet:{$e->businessId}:{$e->toStatus}:" . now()->timestamp, ['to_status' => $e->toStatus]);
    }

    public function onWebsitePublished(WebsitePublished $e): void
    {
        $this->fire('product.website_published', PlatformTarget::business($e->businessId), "website_revision:{$e->websiteRevisionId}");
    }

    public function onProviderConnected(GoogleBusinessProfileConnected $e): void
    {
        $this->fire('product.provider_connected', PlatformTarget::business($e->businessId, PlatformTargetType::Provider), "gbp:{$e->connectionId}:connected");
    }

    public function onProviderDisconnected(GoogleBusinessProfileDisconnected $e): void
    {
        $this->fire('product.provider_disconnected', PlatformTarget::business($e->businessId, PlatformTargetType::Provider), "gbp:{$e->connectionId}:disconnected:" . now()->timestamp);
    }

    public function onProviderRevoked(GoogleBusinessProfileConnectionRevoked $e): void
    {
        $this->fire('provider.reconnection_required', PlatformTarget::business($e->businessId, PlatformTargetType::Provider), "gbp:{$e->connectionId}:revoked");
    }

    private function fire(string $trigger, ?PlatformTarget $target, string $occurrenceKey, array $context = []): void
    {
        if ($target === null) {
            return;
        }

        try {
            $this->dispatcher->fire($trigger, $target, $occurrenceKey, $context);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
