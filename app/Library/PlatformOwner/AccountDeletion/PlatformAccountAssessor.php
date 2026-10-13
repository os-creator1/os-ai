<?php

namespace App\Library\PlatformOwner\AccountDeletion;

use App\Enums\AgencyBilling\AgencyClientSubscriptionStatus;
use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Enums\Usage\SlotAgreementState;
use App\Enums\Workspace\AgencyClientRelationshipStatus;
use App\Models\AdditionalBusinessSlotAgreement;
use App\Models\AgencyClientSubscription;
use App\Models\AgencyClientWorkspaceRelationship;
use App\Models\Business;
use App\Models\PlatformAccountClosure;
use App\Models\PlatformSubscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Models\WorkspacePlanAssignment;
use Illuminate\Support\Facades\DB;

/**
 * The authoritative, server-side picture of one customer account: everything
 * the Platform Owner needs to manage it in one place (Users > account) and
 * everything a deletion would touch (the preflight).
 *
 * READ-ONLY. It makes no provider call and writes nothing. The same method
 * feeds the account page, the confirmation screen and the manager's
 * pre-execution re-check, so those three can never disagree about what a
 * deletion would do — and the `fingerprint` lets the manager reject a
 * confirmation that was reviewed against an older picture.
 *
 * OWNERSHIP vs MEMBERSHIP. `owned_workspaces` come from
 * workspaces.owner_user_id and are the ONLY Workspaces a deletion may retire.
 * `memberships_elsewhere` are Workspaces somebody else owns: the account
 * merely loses its seat there. An Agency's client Workspaces are separate
 * rows with their own owners and appear under `agency.clients` for
 * information; they are never selected for deletion.
 */
final class PlatformAccountAssessor
{
    public const VERDICT_DELETE = 'delete';
    public const VERDICT_CLOSE = 'close';
    public const VERDICT_BLOCKED = 'blocked';

    public function __construct(private readonly AccountRecordGraph $graph)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function assess(User $user): array
    {
        $workspaces = Workspace::query()->where('owner_user_id', $user->id)->orderBy('id')->get();
        $workspaceIds = $workspaces->pluck('id')->all();

        $businesses = $workspaceIds === []
            ? collect()
            : Business::query()->whereIn('workspace_id', $workspaceIds)->orderBy('id')->get();
        $businessIds = $businesses->pluck('id')->all();

        $locations = $businessIds === []
            ? collect()
            : DB::table('business_locations')->whereIn('business_id', $businessIds)->orderBy('id')->get(['business_id', 'name', 'lifecycle_state'])->groupBy('business_id');

        $assignments = $workspaceIds === []
            ? collect()
            : WorkspacePlanAssignment::query()->with('catalog:id,tier,display_name')->whereIn('workspace_id', $workspaceIds)->get()->keyBy('workspace_id');

        $platformSubs = $workspaceIds === []
            ? collect()
            : PlatformSubscription::query()->whereIn('workspace_id', $workspaceIds)->get()->keyBy('workspace_id');

        $members = $workspaceIds === []
            ? collect()
            : WorkspaceMembership::query()->with('user:id,email,first_name,last_name')
                ->whereIn('workspace_id', $workspaceIds)->where('is_active', true)->where('user_id', '!=', $user->id)->get()->groupBy('workspace_id');

        $rows = [];

        foreach ($workspaces as $workspace) {
            $assignment = $assignments->get($workspace->id);
            $sub = $platformSubs->get($workspace->id);

            $rows[] = [
                'id' => $workspace->id,
                'uid' => $workspace->uid,
                'name' => $workspace->name,
                'is_active' => (bool) $workspace->is_active,
                'plan' => $assignment?->catalog?->display_name ?? $assignment?->catalog?->tier?->value,
                'plan_status' => $assignment?->status?->value ?? 'none',
                'subscription' => $sub === null ? null : [
                    'status' => $sub->status->value,
                    'provider_linked' => $sub->provider_subscription_id !== null,
                    'live' => $this->platformSubscriptionIsLive($sub),
                    'cancel_at_period_end' => (bool) $sub->cancel_at_period_end,
                    'period_end' => $sub->current_period_end?->toDateString(),
                ],
                'businesses' => $businesses->where('workspace_id', $workspace->id)->map(fn (Business $b) => [
                    'uid' => $b->uid,
                    'name' => $b->name,
                    'status' => $b->status->value,
                    'locations' => ($locations->get($b->id) ?? collect())->map(fn ($l) => ['name' => $l->name, 'state' => $l->lifecycle_state])->values()->all(),
                ])->values()->all(),
                'members_losing_access' => ($members->get($workspace->id) ?? collect())->map(fn (WorkspaceMembership $m) => [
                    'email' => $m->user?->email,
                    'role' => $m->role instanceof \BackedEnum ? $m->role->value : (string) $m->role,
                ])->values()->all(),
            ];
        }

        $elsewhere = WorkspaceMembership::query()->with(['workspace:id,uid,name,owner_user_id', 'workspace.owner:id,email'])
            ->where('user_id', $user->id)->get()
            ->filter(fn (WorkspaceMembership $m) => $m->workspace !== null && (int) $m->workspace->owner_user_id !== (int) $user->id)
            ->map(fn (WorkspaceMembership $m) => [
                'workspace' => $m->workspace->name,
                'workspace_uid' => $m->workspace->uid,
                'owner_email' => $m->workspace->owner?->email,
                'role' => $m->role instanceof \BackedEnum ? $m->role->value : (string) $m->role,
                'active' => (bool) $m->is_active,
            ])->values()->all();

        $agency = $this->agency($workspaceIds);
        $commitments = $this->billingCommitments($workspaceIds, $rows);
        $services = $this->connectedServices((int) $user->id, $workspaceIds, $businessIds);
        $background = $this->backgroundWork((int) $user->id, $workspaceIds, $businessIds);

        $selection = $this->graph->discover((int) $user->id, array_map('intval', $workspaceIds));
        $protected = $this->graph->protectedRecords($selection);
        $closure = PlatformAccountClosure::query()->where('user_uid', $user->uid)->first();

        $verdict = self::VERDICT_DELETE;
        $reasons = [];

        if ($user->is_admin) {
            $verdict = self::VERDICT_BLOCKED;
            $reasons[] = 'Administrator accounts are managed under Administrators and can never be deleted here.';
        }

        if ($selection->unsupported !== []) {
            $verdict = self::VERDICT_BLOCKED;
            $reasons[] = 'The account owns data the deletion graph cannot enumerate safely (' . implode(', ', $selection->unsupported) . ').';
        }

        if ($verdict !== self::VERDICT_BLOCKED && ($protected !== [] || $selection->blockers !== [] || $commitments['provider_linked'] > 0 || $services['carrier_numbers'] > 0)) {
            $verdict = self::VERDICT_CLOSE;
        }

        foreach ($protected as $entry) {
            $reasons[] = $entry['count'] . ' × ' . $entry['label'] . ' must be retained.';
        }

        foreach ($selection->blockers as $blocker) {
            $reasons[] = $blocker['count'] . ' row(s) in `' . $blocker['table'] . '` reference this user from outside the account (another owner\'s or shared data), so the user row cannot be erased.';
        }

        $assessment = [
            'user' => ['id' => $user->id, 'uid' => $user->uid, 'email' => $user->email, 'name' => trim($user->first_name . ' ' . $user->last_name), 'status' => (bool) $user->status, 'created_at' => $user->created_at?->toDateString(), 'is_admin' => (bool) $user->is_admin],
            'owned_workspaces' => $rows,
            'memberships_elsewhere' => $elsewhere,
            'agency' => $agency,
            'billing_commitments' => $commitments,
            'connected_services' => $services,
            'background_work' => $background,
            'protected_records' => array_values($protected),
            'external_references' => $selection->blockers,
            'rows_to_remove' => $selection->tableCounts(),
            'verdict' => $verdict,
            'reasons' => $reasons,
            'closure' => $closure === null ? null : [
                'uid' => $closure->uid,
                'state' => $closure->state->value,
                'state_label' => $closure->state->label(),
                'outcome' => $closure->outcome,
                'pending_items' => $closure->pending_items ?? [],
                'last_error' => $closure->last_error,
                'attempts' => $closure->attempts,
                'requested_at' => $closure->created_at?->toDayDateTimeString(),
            ],
        ];

        $assessment['fingerprint'] = $this->fingerprint($assessment);

        return $assessment;
    }

    /**
     * What the confirmation was reviewed against. Changes whenever anything
     * the page showed would change, so a stale confirmation is refused.
     *
     * @param  array<string, mixed>  $assessment
     */
    public function fingerprint(array $assessment): string
    {
        $material = [
            $assessment['user']['id'],
            $assessment['user']['email'],
            $assessment['verdict'],
            array_map(fn (array $w) => [$w['id'], $w['is_active'], $w['subscription'], array_map(fn ($b) => [$b['uid'], $b['status']], $w['businesses']), $w['members_losing_access']], $assessment['owned_workspaces']),
            $assessment['memberships_elsewhere'],
            $assessment['agency'],
            $assessment['billing_commitments'],
            $assessment['protected_records'],
            $assessment['external_references'],
        ];

        return hash('sha256', json_encode($material, JSON_THROW_ON_ERROR));
    }

    public function platformSubscriptionIsLive(PlatformSubscription $sub): bool
    {
        return $sub->provider_subscription_id !== null
            && ! in_array($sub->status, [PlatformSubscriptionStatus::Canceled, PlatformSubscriptionStatus::IncompleteExpired, PlatformSubscriptionStatus::Pending], true);
    }

    public function agencySubscriptionIsLive(AgencyClientSubscription $sub): bool
    {
        return $sub->provider_subscription_id !== null
            && ! in_array($sub->status, [AgencyClientSubscriptionStatus::Canceled, AgencyClientSubscriptionStatus::IncompleteExpired, AgencyClientSubscriptionStatus::Pending, AgencyClientSubscriptionStatus::Offered], true);
    }

    /**
     * A slot agreement still able to charge: it has taken (or is about to take)
     * money and has not been told to stop renewing.
     */
    public function slotAgreementIsCharging(AdditionalBusinessSlotAgreement $agreement): bool
    {
        if ($agreement->cancel_at_period_end) {
            return false;
        }

        return in_array($agreement->state, [
            SlotAgreementState::PaymentSucceeded, SlotAgreementState::AllocationPending, SlotAgreementState::AllocationFailed,
            SlotAgreementState::Completed, SlotAgreementState::Active, SlotAgreementState::Suspended,
        ], true);
    }

    /**
     * @param  array<int, int>  $workspaceIds
     * @return array<string, mixed>
     */
    private function agency(array $workspaceIds): array
    {
        if ($workspaceIds === []) {
            return ['clients' => [], 'managed_by' => []];
        }

        $clients = AgencyClientWorkspaceRelationship::query()->with(['clientWorkspace:id,uid,name,owner_user_id', 'clientWorkspace.owner:id,email'])
            ->whereIn('agency_workspace_id', $workspaceIds)->where('status', AgencyClientRelationshipStatus::Active->value)->get();

        $managedBy = AgencyClientWorkspaceRelationship::query()->with(['agencyWorkspace:id,uid,name,owner_user_id', 'agencyWorkspace.owner:id,email'])
            ->whereIn('client_workspace_id', $workspaceIds)->where('status', AgencyClientRelationshipStatus::Active->value)->get();

        return [
            'clients' => $clients->map(fn ($r) => [
                'workspace' => $r->clientWorkspace?->name,
                'workspace_uid' => $r->clientWorkspace?->uid,
                'owner_email' => $r->clientWorkspace?->owner?->email,
            ])->values()->all(),
            'managed_by' => $managedBy->map(fn ($r) => [
                'workspace' => $r->agencyWorkspace?->name,
                'workspace_uid' => $r->agencyWorkspace?->uid,
                'owner_email' => $r->agencyWorkspace?->owner?->email,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<int, int>  $workspaceIds
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function billingCommitments(array $workspaceIds, array $rows): array
    {
        if ($workspaceIds === []) {
            return ['platform' => [], 'agency_as_client' => [], 'agency_as_agency' => [], 'slot_agreements' => [], 'wallets' => [], 'provider_linked' => 0, 'charging' => 0];
        }

        $platform = [];

        foreach ($rows as $row) {
            if ($row['subscription'] !== null && $row['subscription']['provider_linked']) {
                $platform[] = ['workspace' => $row['name'], 'status' => $row['subscription']['status'], 'live' => $row['subscription']['live']];
            }
        }

        $asClient = AgencyClientSubscription::query()->whereIn('client_workspace_id', $workspaceIds)->whereNotNull('provider_subscription_id')->get();
        $asAgency = AgencyClientSubscription::query()->whereIn('agency_workspace_id', $workspaceIds)->whereNotNull('provider_subscription_id')->get();
        $slots = AdditionalBusinessSlotAgreement::query()->whereIn('workspace_id', $workspaceIds)->get();

        $wallets = DB::table('business_usage_wallets')
            ->join('businesses', 'businesses.id', '=', 'business_usage_wallets.business_id')
            ->whereIn('businesses.workspace_id', $workspaceIds)
            ->get(['businesses.name', 'available_balance_micro', 'refundable_paid_available_micro', 'reserved_balance_micro', 'debt_balance_micro', 'auto_recharge_enabled'])
            ->filter(fn ($w) => $w->available_balance_micro != 0 || $w->refundable_paid_available_micro != 0 || $w->reserved_balance_micro != 0 || $w->debt_balance_micro != 0 || $w->auto_recharge_enabled)
            ->map(fn ($w) => ['business' => $w->name, 'balance_micro' => (string) $w->available_balance_micro, 'refundable_micro' => (string) $w->refundable_paid_available_micro, 'debt_micro' => (string) $w->debt_balance_micro, 'auto_recharge' => (bool) $w->auto_recharge_enabled])
            ->values()->all();

        $charging = count(array_filter($platform, fn ($p) => $p['live']))
            + $asClient->filter(fn ($s) => $this->agencySubscriptionIsLive($s))->count()
            + $asAgency->filter(fn ($s) => $this->agencySubscriptionIsLive($s))->count()
            + $slots->filter(fn ($a) => $this->slotAgreementIsCharging($a))->count();

        return [
            'platform' => $platform,
            'agency_as_client' => $asClient->map(fn ($s) => ['status' => $s->status->value, 'live' => $this->agencySubscriptionIsLive($s)])->values()->all(),
            'agency_as_agency' => $asAgency->map(fn ($s) => ['status' => $s->status->value, 'live' => $this->agencySubscriptionIsLive($s), 'client_workspace_id' => $s->client_workspace_id])->values()->all(),
            'slot_agreements' => $slots->filter(fn ($a) => $a->state !== SlotAgreementState::Canceled)->map(fn ($a) => ['state' => $a->state->value, 'charging' => $this->slotAgreementIsCharging($a), 'ends' => $a->cancellation_effective_at?->toDateString()])->values()->all(),
            'wallets' => $wallets,
            'provider_linked' => count($platform) + $asClient->count() + $asAgency->count() + $slots->count(),
            'charging' => $charging,
        ];
    }

    /**
     * @param  array<int, int>  $workspaceIds
     * @param  array<int, int>  $businessIds
     * @return array<string, int>
     */
    private function connectedServices(int $userId, array $workspaceIds, array $businessIds): array
    {
        $byBusiness = fn (string $table, string $stateColumn, array $live) => $businessIds === [] ? 0
            : DB::table($table)->whereIn('business_id', $businessIds)->whereIn($stateColumn, $live)->count();

        return [
            'google' => $byBusiness('business_google_connections', 'state', ['pending', 'active']),
            'meta' => $byBusiness('business_meta_connections', 'state', ['pending', 'active']),
            'email' => $byBusiness('business_email_accounts', 'state', ['pending', 'active']),
            'calendar' => DB::table('external_calendar_connections')->where('user_id', $userId)->whereIn('state', ['pending', 'active'])->count(),
            'stripe_accounts' => ($businessIds === [] ? 0 : DB::table('business_stripe_connections')->whereIn('business_id', $businessIds)->count())
                + ($workspaceIds === [] ? 0 : DB::table('agency_stripe_connections')->whereIn('agency_workspace_id', $workspaceIds)->count()),
            'carrier_numbers' => $businessIds === [] ? 0 : DB::table('business_messaging_numbers')
                ->join('business_messaging_identities', 'business_messaging_identities.id', '=', 'business_messaging_numbers.business_messaging_identity_id')
                ->whereIn('business_messaging_identities.business_id', $businessIds)->whereNull('business_messaging_numbers.released_at')->count(),
        ];
    }

    /**
     * @param  array<int, int>  $workspaceIds
     * @param  array<int, int>  $businessIds
     * @return array<string, int>
     */
    private function backgroundWork(int $userId, array $workspaceIds, array $businessIds): array
    {
        return [
            'automations' => DB::table('automations')->where('user_id', $userId)->where('status', 'active')->count(),
            'workflows' => $businessIds === [] ? 0 : DB::table('automation_workflows')->whereIn('business_id', $businessIds)->where('status', 'published')->count(),
            'enrollments' => $businessIds === [] ? 0 : DB::table('automation_enrollments')->whereIn('business_id', $businessIds)->whereIn('status', ['active', 'waiting'])->count(),
            'campaigns' => DB::table('campaigns')->where('user_id', $userId)->whereIn('status', self::RUNNING_CAMPAIGN_STATUSES)->count(),
            'scheduled_messages' => DB::table('schedule_messages')->where('user_id', $userId)->where('schedule_on', '>=', now()->subMinutes(3))->count(),
            'outreach_campaigns' => $workspaceIds === [] ? 0 : DB::table('agency_prospect_campaigns')->whereIn('workspace_id', $workspaceIds)->where('status', 'active')->count(),
            'published_websites' => $businessIds === [] ? 0 : DB::table('websites')->whereIn('business_id', $businessIds)->where('status', 'published')->count(),
        ];
    }

    public const RUNNING_CAMPAIGN_STATUSES = ['new', 'queued', 'scheduled', 'processing', 'sending', 'queuing', 'paused'];
}
