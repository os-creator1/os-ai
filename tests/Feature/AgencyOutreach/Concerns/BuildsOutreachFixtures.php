<?php

namespace Tests\Feature\AgencyOutreach\Concerns;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Events\Conversation\InboundMessageReceived;
use App\Library\AgencyOutreach\OutreachScriptManager;
use App\Library\Ai\Contracts\AiCompletionClient;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Library\Conversations\ConversationHistoryWriter;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\UsageWalletManager;
use App\Listeners\Outreach\HandleOutreachInboundMessage;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectMessage;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\ChatBoxMessage;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;

/**
 * Agency Outreach V1 — shared fixtures. Everything is the REAL stack (the real send
 * core, the real managed dispatcher, the real merge engine, the real gateway); only the
 * provider ({@see \App\Library\Messaging\FakeMessagingAdapter}) and the model
 * ({@see FakeAiCompletionClient}) are doubles, so `fakeAdapter->sentRequests` is the
 * ground truth for "what was actually sent to a stranger".
 *
 * The product copy these fixtures use ("photo booth", city names ...) is per-Agency
 * test DATA — exactly what an Agency would type — never anything the product supplies.
 */
trait BuildsOutreachFixtures
{
    use CreatesAutomationFixtures;
    use CreatesMessagingFixtures;

    /** @var array<int, string> managed number digits, by Business id */
    protected array $managedDigits = [];

    private int $outreachOperationSeq = 1000;

    protected function bootOutreach(): void
    {
        Http::fake();
        $this->bindFakeAdapter();
        $this->ensureRequiredAppConfigRowsExist();

        // The first user is always a super admin; keep the customer from being it.
        User::create([
            'first_name' => 'Placeholder', 'last_name' => 'SuperAdmin',
            'email' => 'placeholder-superadmin' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
    }

    /**
     * One Agency: an Agency-tier Workspace with its own single Active Business on managed
     * messaging, plus the legacy plan/coverage the send core still reads.
     *
     * @return array{0: Customer, 1: Business, 2: Workspace}
     */
    protected function agency(string $name = 'Snap Booth Co', ?string $managedNumber = null): array
    {
        $customer = $this->createCustomer();

        $workspace = Workspace::create(['name' => $name . ' Workspace', 'owner_user_id' => $customer->user_id, 'is_active' => true]);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Agency, $this->platformAdminId(), 'Outreach fixture.', true, 0);

        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes(['name' => $name]));
        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Active->value]);
        $business = $business->fresh();

        $this->sendableChannel($business);

        $digits = $managedNumber ?? ('1415555' . random_int(1000, 9999));
        $identity = $this->attachIdentity($business);
        $this->attachNumber($identity, '+' . $digits, true);
        $this->managedDigits[(int) $business->id] = $digits;

        // Messaging verification approved: Outreach re-checks the canonical readiness state at send time.
        \App\Models\BusinessMessagingRegistration::create([
            'business_id' => $business->id, 'number_type' => 'local', 'status' => 'approved', 'approved_at' => now(),
            'legal_business_name' => $name . ' LLC', 'entity_type' => 'ein', 'ein' => '12-3456789',
            'address_line_1' => '1 Main St', 'city' => 'Portland', 'region' => 'OR', 'postal_code' => '97201',
            'website_url' => 'https://example.com', 'contact_email' => 'o@example.com', 'contact_phone' => '+15035550100',
            'use_case' => 'customer_care', 'opt_in_method' => 'Opt in form.', 'sample_message_1' => 'Hi. Reply STOP to opt out.',
            'sample_message_2' => 'Hello. Reply STOP to opt out.', 'privacy_policy_url' => 'https://example.com/p', 'terms_url' => 'https://example.com/t',
        ]);

        $customer->user->sms_unit = 1000;
        $customer->user->save();
        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        return [$customer->fresh(), $business, $workspace->fresh()];
    }

    /** A saved, owner-typed script (vocabulary the owner would use, including legacy aliases). */
    protected function saveScript(Workspace $workspace, array $overrides = []): void
    {
        OutreachScriptManager::save($workspace, array_merge([
            'agency_name' => 'Snap Booth Co',
            'booking_url' => 'https://cal.example.test/snap',
            'website_url' => 'https://snap.example.test',
            'message_1' => 'Hi, {{agency_name}} here. We book photo booths for local venues and you only pay at 9% when we deliver. Open to hearing more?',
            'message_2' => 'Great. Can we do a quick call about it?',
            'message_3' => 'Book a quick call here: {{calendar_link}}',
            'pricing_answer' => 'Our commission is 9% of booked events.',
            'location_answer' => 'We are based in Austin and serve Texas.',
            'found_you_answer' => 'We found you on Google Maps.',
            'what_we_do_answer' => 'We get venues photo booth bookings.',
            'website_answer' => 'See {{website}} for more.',
            'clarify_answer' => 'This is not an event booking, just a quick intro.',
            'followup_message' => 'Following up, you can book here: {{calendar_link}}',
        ], $overrides));
    }

    protected function prospect(Workspace $workspace, string $phone = '12025551000', array $overrides = []): AgencyProspect
    {
        return AgencyProspect::create(array_merge([
            'workspace_id' => $workspace->id,
            'company_name' => 'Maple Venue',
            'contact_name' => 'Dana Maple',
            'phone' => $phone,
            'status' => 'active',
        ], $overrides));
    }

    protected function managedCampaign(Workspace $workspace, array $overrides = []): AgencyProspectCampaign
    {
        return AgencyProspectCampaign::create(array_merge([
            'workspace_id' => $workspace->id,
            'name' => 'Outreach',
            'status' => 'active',
            'sending_mode' => 'managed',
            'opening_message' => 'Hi {{prospect.first_name}}, {{agency_name}} here.',
        ], $overrides));
    }

    protected function enroll(Workspace $workspace, AgencyProspectCampaign $campaign, AgencyProspect $prospect, int $stage = 1): AgencyProspectCampaignMember
    {
        return AgencyProspectCampaignMember::create([
            'workspace_id' => $workspace->id,
            'campaign_id' => $campaign->id,
            'prospect_id' => $prospect->id,
            'stage' => $stage,
            'enrolled_at' => now(),
        ]);
    }

    /**
     * A prospect reply arriving through canonical messaging: the managed inbound bridge's own
     * write (conversation + message), then the domain event handed to the Outreach listener.
     * Returns the occurrence key so a test can redeliver the SAME event.
     */
    protected function inbound(Business $business, string $body, string $phone = '12025551000', ?string $occurrenceKey = null, bool $deliver = true): string
    {
        app(ConversationHistoryWriter::class)->recordManagedInbound(
            $business,
            $this->managedDigits[(int) $business->id],
            $phone,
            $body,
            [],
            'sms',
        );

        $occurrenceKey ??= 'operation:' . (++$this->outreachOperationSeq);

        if ($deliver) {
            $this->deliverInbound($business, $phone, $occurrenceKey);
        }

        return $occurrenceKey;
    }

    protected function deliverInbound(Business $business, string $phone, string $occurrenceKey): void
    {
        app(HandleOutreachInboundMessage::class)->handle(new InboundMessageReceived((int) $business->id, '+' . $phone, $occurrenceKey));
    }

    /** @return list<string> every message body actually handed to the provider, in order */
    protected function sentBodies(): array
    {
        return array_map(static fn ($r): string => $r->body, $this->fakeAdapter->sentRequests);
    }

    protected function member(AgencyProspectCampaignMember $member): AgencyProspectCampaignMember
    {
        return $member->fresh();
    }

    /** @return list<AgencyProspectMessage> */
    protected function ledger(AgencyProspectCampaignMember $member, ?string $direction = null): array
    {
        return AgencyProspectMessage::query()
            ->where('campaign_member_id', $member->id)
            ->when($direction !== null, fn ($q) => $q->where('direction', $direction))
            ->orderBy('id')
            ->get()
            ->all();
    }

    protected function useFakeAi(): FakeAiCompletionClient
    {
        config(['services.openai.active' => true, 'ai.enforce_budgets_for_existing_categories' => false]);

        $fake = new FakeAiCompletionClient();
        $this->app->instance(AiCompletionClient::class, $fake);

        return $fake;
    }

    protected function fundWallet(Business $business, int $micro): void
    {
        Currency::query()->first() ?? Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);

        if (! DB::table('business_usage_wallets')->where('business_id', $business->id)->exists()) {
            app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        }

        DB::table('business_usage_wallets')->where('business_id', $business->id)->update([
            'available_balance_micro' => $micro,
            'refundable_paid_available_micro' => $micro,
        ]);
    }

    /** What the platform owner does with the existing rate tooling: a meter, a rate, then metering on. */
    protected function priceTransport(int $ratePerSegmentMicro = 10_000): void
    {
        $key = PlatformFeature::MessagingTransport->value;
        $currencyId = (Currency::query()->first() ?? Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]))->id;
        $actorId = $this->platformAdminId();

        $manager = app(UsageWalletManager::class);
        app(UsageMeterRepository::class)->create([
            'meter_key' => $key, 'feature_key' => $key, 'business_id' => null,
            'currency_id' => $currencyId, 'description' => 'Managed messaging transport (per segment).', 'updated_by_user_id' => $actorId,
        ]);
        $manager->setActiveRate($key, (string) $ratePerSegmentMicro, '5000', 'per segment', $currencyId, $actorId, 'Fixture.');
        $manager->activateMetering($key, $actorId, 'Fixture.');
    }

    /** A person pressing Send in Conversations after the latest inbound (what manual takeover looks like). */
    protected function ownerRepliesByHand(Business $business, string $phone, string $text): void
    {
        $box = ChatBox::query()->where('business_id', $business->id)->where('to', $phone)->orderByDesc('id')->firstOrFail();

        ChatBoxMessage::create([
            'box_id' => $box->id,
            'message' => $text,
            'sms_type' => 'plain',
            'direction' => 'outgoing',
            'send_by' => 'from',
            'source' => ConversationHistoryWriter::SOURCE_CONVERSATIONS,
        ]);
    }
}
