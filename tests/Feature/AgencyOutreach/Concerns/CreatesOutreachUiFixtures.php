<?php

namespace Tests\Feature\AgencyOutreach\Concerns;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Usage\UsageWalletManager;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectingSetting;
use App\Models\Business;
use App\Models\BusinessMessagingRegistration;
use App\Models\Currency;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;

/**
 * Shared fixtures for the Outreach UI tests: a complete Agency (Workspace with
 * its own single Business, a ready sending number, approved verification, a
 * funded wallet, a saved script with a calendar link, one prospect in a managed
 * campaign) and the knobs to break each readiness item one at a time.
 */
trait CreatesOutreachUiFixtures
{
    use CreatesCustomerContextFixtures;
    use CreatesMessagingFixtures;

    /**
     * @param  array{number?: bool, registration?: ?string, balance?: int, script?: bool, enrolled?: bool, agencyName?: string, calendar?: ?string}  $opts
     * @return array{customer: \App\Models\Customer, business: Business, workspace: Workspace, campaign: AgencyProspectCampaign, prospect: ?AgencyProspect, member: ?AgencyProspectCampaignMember}
     */
    protected function outreachAgency(string $name = 'Alpha', array $opts = []): array
    {
        $opts = array_merge([
            'number' => true, 'registration' => 'approved', 'balance' => 5_000_000,
            'script' => true, 'enrolled' => true, 'agencyName' => $name . ' Agency', 'calendar' => 'https://cal.example.com/' . strtolower($name),
        ], $opts);

        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, $name . ' Business', $name . ' Workspace');

        if ($opts['number']) {
            $this->attachNumber($this->attachIdentity($business), $this->uniqueNumber());
        }

        if ($opts['registration'] !== null) {
            BusinessMessagingRegistration::create([
                'business_id' => $business->id, 'number_type' => 'local', 'status' => $opts['registration'],
                'approved_at' => $opts['registration'] === 'approved' ? now() : null,
                'legal_business_name' => $name . ' LLC', 'entity_type' => 'ein', 'ein' => '12-3456789',
                'address_line_1' => '1 Main St', 'city' => 'Portland', 'region' => 'OR', 'postal_code' => '97201',
                'website_url' => 'https://example.com', 'contact_email' => 'o@example.com', 'contact_phone' => '+15035550100',
                'use_case' => 'customer_care', 'opt_in_method' => 'Opt in form.', 'sample_message_1' => 'Hi. Reply STOP to opt out.',
                'sample_message_2' => 'Hello. Reply STOP to opt out.', 'privacy_policy_url' => 'https://example.com/p', 'terms_url' => 'https://example.com/t',
            ]);
        }

        $this->fundOutreachWallet($business, $opts['balance']);

        if ($opts['script']) {
            AgencyProspectingSetting::create(array_filter([
                'workspace_id' => $workspace->id,
                'agency_name' => $opts['agencyName'],
                'booking_url' => $opts['calendar'],
                'message_1' => 'Hi from ' . $name . ' unique-script-marker-' . strtolower($name) . '. {{agency.name}} here.',
            ], fn ($v) => $v !== null));
        }

        $prospect = $member = null;

        $campaign = AgencyProspectCampaign::create([
            'workspace_id' => $workspace->id, 'name' => $name . ' Campaign', 'status' => 'draft',
            'sending_mode' => 'managed', 'opening_message' => 'Hi {{prospect.first_name}}',
        ]);

        if ($opts['enrolled']) {
            $prospect = AgencyProspect::create([
                'workspace_id' => $workspace->id, 'company_name' => $name . ' Prospect Co', 'contact_name' => $name . ' Contact',
                'phone' => '1555' . random_int(1000000, 9999999), 'status' => 'active',
            ]);
            $member = AgencyProspectCampaignMember::create([
                'workspace_id' => $workspace->id, 'campaign_id' => $campaign->id, 'prospect_id' => $prospect->id, 'enrolled_at' => now(),
            ]);
        }

        return ['customer' => $customer, 'business' => $business, 'workspace' => $workspace, 'campaign' => $campaign, 'prospect' => $prospect, 'member' => $member];
    }

    protected function fundOutreachWallet(Business $business, int $micro): void
    {
        Currency::query()->first() ?? Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);

        if (! DB::table('business_usage_wallets')->where('business_id', $business->id)->exists()) {
            app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        }

        DB::table('business_usage_wallets')->where('business_id', $business->id)->update([
            'available_balance_micro' => $micro, 'refundable_paid_available_micro' => $micro,
        ]);
    }

    /** What the platform owner does with the existing rate tooling: a meter, a rate, then metering on. */
    protected function priceOutreachTransport(int $rateMicro = 10_000): void
    {
        $key = PlatformFeature::MessagingTransport->value;
        $currencyId = Currency::query()->first()->id;
        $actorId = User::create([
            'first_name' => 'Rate', 'last_name' => 'Actor', 'email' => 'rate' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;

        $manager = app(UsageWalletManager::class);
        app(UsageMeterRepository::class)->create([
            'meter_key' => $key, 'feature_key' => $key, 'business_id' => null,
            'currency_id' => $currencyId, 'description' => 'Managed messaging transport (per segment).', 'updated_by_user_id' => $actorId,
        ]);
        $manager->setActiveRate($key, (string) $rateMicro, '5000', 'per segment', $currencyId, $actorId, 'Fixture.');
        $manager->activateMetering($key, $actorId, 'Fixture.');
    }

    protected function outreachRoute(string $name, Workspace $workspace, array $extra = []): string
    {
        return route('customer.workspaces.prospecting.' . $name, array_merge([$workspace->uid], $extra));
    }
}
