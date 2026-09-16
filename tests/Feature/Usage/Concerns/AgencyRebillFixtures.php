<?php

namespace Tests\Feature\Usage\Concerns;

use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\UsageWalletManager;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Models\Business;
use App\Models\BusinessPaymentInstrument;
use App\Models\Customer;
use App\Models\PaymentProviderCustomer;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessPaymentInstrumentRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Usage\Slice5\Concerns\Slice5Fixtures;

/**
 * Implementation Contract 09 — shared fixtures for the AgencyRebill tests.
 *
 * Everything real: Contract 01 relationships through their own manager,
 * AgencyRebill granted through BillingProfileManager::assignPayer() by the
 * managing Agency owner, lifecycle states through EntitlementManager's own
 * writers, and the real RFC-005 wallet/ledger/meters. The only fakes are the
 * payment-provider gateway (Slice5Fixtures) and provider customers/instruments
 * written straight through their repositories, so each test controls exactly
 * which customer and card exist on which side.
 */
trait AgencyRebillFixtures
{
    use Slice5Fixtures;

    /**
     * A Client Business in its own Client Workspace, managed through an ACTIVE
     * Contract 01 relationship by a separate Agency Workspace. Not yet
     * AgencyRebill (see rebilledClient()).
     *
     * @return array{agencyOwner: Customer, agency: Workspace, clientOwner: Customer, business: Business, client: Workspace, relationship: \App\Models\AgencyClientWorkspaceRelationship}
     */
    protected function managedClient(WorkspacePlanTier $clientTier = WorkspacePlanTier::Growth, string $suffix = ''): array
    {
        $this->platformAdminId();

        [$agencyOwner, $agency] = $this->agencyAccount('Northwind Agency' . $suffix);
        [$clientOwner, $business, $client] = $this->tenantWithWallet($clientTier, 'Client Bakery' . $suffix, 'Client Account' . $suffix);

        $relationship = app(AgencyClientRelationshipManager::class)->create((int) $agencyOwner->user_id, $agency, $client);

        return [
            'agencyOwner' => $agencyOwner,
            'agency' => $agency->fresh(),
            'clientOwner' => $clientOwner,
            'business' => $business->fresh(),
            'client' => $client->fresh(),
            'relationship' => $relationship,
        ];
    }

    /** managedClient(), with AgencyRebill granted by the managing Agency owner. */
    protected function rebilledClient(WorkspacePlanTier $clientTier = WorkspacePlanTier::Growth, string $suffix = ''): array
    {
        $m = $this->managedClient($clientTier, $suffix);
        $this->grantAgencyRebill($m);
        $m['business'] = $m['business']->fresh();

        return $m;
    }

    /** @return array{0: Customer, 1: Workspace} */
    protected function agencyAccount(string $name = 'Northwind Agency'): array
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user, ['name' => $name]);
        $this->assignTier($workspace, WorkspacePlanTier::Agency);

        return [$owner, $workspace->fresh()];
    }

    protected function grantAgencyRebill(array $m): array
    {
        return app(BillingProfileManager::class)->assignPayer($m['business'], PayerType::AgencyRebill, (int) $m['agencyOwner']->user_id, 'Agency funds this client.');
    }

    protected function assignmentRow(Business $business): object
    {
        return DB::table('business_payer_assignments')->where('business_id', $business->id)->first();
    }

    protected function memberOf(Workspace $workspace, WorkspaceMembershipRole $role, WorkspaceBusinessAccessScope $scope = WorkspaceBusinessAccessScope::All): Customer
    {
        $customer = $this->createCustomer();
        $this->member($workspace, $customer->user, $role, $scope);

        return $customer;
    }

    /** @return array{0: PaymentProviderCustomer, 1: BusinessPaymentInstrument} */
    protected function workspaceProviderCustomerWithCard(Workspace $workspace, string $lastFour): array
    {
        $customer = app(PaymentProviderCustomerRepository::class)->create([
            'provider' => 'stripe',
            'workspace_id' => $workspace->id,
            'business_id' => null,
            'provider_customer_id' => 'cus_fake_workspace_' . $workspace->id,
            'status' => 'active',
        ]);

        return [$customer, $this->defaultCard($customer, $lastFour)];
    }

    /** @return array{0: PaymentProviderCustomer, 1: BusinessPaymentInstrument} */
    protected function businessProviderCustomerWithCard(Business $business, string $lastFour): array
    {
        $customer = app(PaymentProviderCustomerRepository::class)->create([
            'provider' => 'stripe',
            'workspace_id' => null,
            'business_id' => $business->id,
            'provider_customer_id' => 'cus_fake_business_' . $business->id,
            'status' => 'active',
        ]);

        return [$customer, $this->defaultCard($customer, $lastFour)];
    }

    protected function workspaceProviderCustomerWithoutCard(Workspace $workspace): PaymentProviderCustomer
    {
        return app(PaymentProviderCustomerRepository::class)->create([
            'provider' => 'stripe',
            'workspace_id' => $workspace->id,
            'business_id' => null,
            'provider_customer_id' => 'cus_fake_workspace_' . $workspace->id,
            'status' => 'active',
        ]);
    }

    protected function defaultCard(PaymentProviderCustomer $customer, string $lastFour): BusinessPaymentInstrument
    {
        return app(BusinessPaymentInstrumentRepository::class)->create([
            'provider_customer_id' => $customer->id,
            'provider' => 'stripe',
            'provider_payment_method_id' => 'pm_fake_' . $customer->id . '_' . $lastFour,
            'type' => 'card',
            'brand' => 'visa',
            'last_four' => $lastFour,
            'expiry_month' => 12,
            'expiry_year' => 2030,
            'is_default' => true,
            'status' => 'active',
            'created_at' => now(),
        ]);
    }

    /**
     * Automatic top-up enabled for this Business by $actorUserId (the payer's
     * funding authority), with the wallet below its threshold.
     */
    protected function enableAutoRecharge(Business $business, int $actorUserId, ?string $monthlyCapMicro = null): int
    {
        $amount = UsageWalletManager::AUTO_RECHARGE_PRESETS_MICRO[1];

        app(UsageWalletManager::class)->configureAutoRecharge(
            $business,
            true,
            (string) 3_000_000,
            (string) $amount,
            $monthlyCapMicro ?? (string) 100_000_000,
            $actorUserId,
        );

        $this->fund($business, 0);

        return $amount;
    }

    /**
     * Puts a Workspace into a Contract 03 lifecycle state through
     * EntitlementManager's own writers.
     */
    protected function putWorkspaceInto(Workspace $workspace, string $state): void
    {
        $manager = app(EntitlementManager::class);

        match ($state) {
            'active' => null,
            'grace' => $manager->enterGracePeriod($workspace, null, 'Renewal failed.'),
            'locked' => [
                $manager->enterGracePeriod($workspace, null, 'Renewal failed.'),
                $manager->lockForNonPayment($workspace, null, 'Grace period elapsed without payment'),
            ],
            'inactive' => $manager->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Inactive, $this->platformAdminId(), 'Closed.'),
            'suspended' => $manager->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Suspended, $this->platformAdminId(), 'Suspended.'),
        };
    }

    /** A Workspace controls row with the given aggregate settings. */
    protected function workspaceControls(Workspace $workspace, array $columns): void
    {
        DB::table('workspace_usage_controls')->updateOrInsert(
            ['workspace_id' => $workspace->id],
            array_merge(['updated_by_user_id' => $workspace->owner_user_id, 'created_at' => now(), 'updated_at' => now()], $columns),
        );
    }
}
