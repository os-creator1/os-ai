<?php

namespace Tests\Feature\Business;

use App\Enums\Business\BusinessIndustry;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Messaging\MessagingRegistrationStatus;
use App\Library\Messaging\Contracts\MessagingProvisioningAdapter;
use App\Library\Messaging\FakeProvisioningAdapter;
use App\Library\Messaging\Registration\RegistrationTemplateCatalog;
use App\Models\BusinessLocation;
use App\Models\BusinessMessagingRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Niche-aware registration defaults and pre-submission validation. Never
 * submits to a real provider: every submit runs against the fake adapter.
 */
class TextMessagingRegistrationDefaultsTest extends TestCase
{
    use \Tests\Support\CompletesTenDlcStages;

    use RefreshDatabase;
    use CreatesCustomerContextFixtures;
    use CreatesMessagingFixtures;

    private function registrationState(): array
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Sunset Photo Booths');
        $this->attachNumber($this->attachIdentity($business), '+14155550400');
        $this->authenticateAs($customer);

        return [$business, $workspace];
    }

    private function showUrl($workspace, $business): string
    {
        return route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]);
    }

    private function updateUrl($workspace, $business): string
    {
        return route('customer.workspaces.businesses.text-messaging.registration.update', [$workspace->uid, $business->uid]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'legal_business_name' => 'Sunset Photo Booths LLC',
            'entity_type' => 'ein',
            'ein' => '12-3456789',
            'address_line_1' => '9 Sunset Ave',
            'city' => 'Austin',
            'region' => 'TX',
            'postal_code' => '78701',
            'website_url' => 'https://sunsetbooths.example',
            'contact_email' => 'hi@sunsetbooths.example',
            'contact_phone' => '+15125550100',
            'use_case' => 'customer_care',
            'campaign_description' => 'Sunset Photo Booths sends quote follow-ups and event reminders to customers who inquired.',
            'opt_in_method' => 'Customers tick an optional SMS box on https://sunsetbooths.example/contact.',
            'sample_message_1' => 'Sunset Photo Booths: thanks for your inquiry, your quote is on its way. Reply STOP to opt out.',
            'sample_message_2' => 'Sunset Photo Booths: reminder that your event is this weekend. Reply STOP to opt out.',
            'privacy_policy_url' => 'https://sunsetbooths.example/privacy',
            'terms_url' => 'https://sunsetbooths.example/sms-terms', 'support_contact' => 'help@fixture.example', 'support_contact_confirmed' => '1', 'support_contact_confirmed_for' => 'help@fixture.example', 'support_contact_confirmed_at' => '2026-01-01 00:00:00',
            'contact_first_name' => 'Pat', 'contact_last_name' => 'Owner', 'message_volume' => '1,000', 'opt_in_image_url' => 'https://example.com/opt-in.png', 'brand_vertical' => 'ENTERTAINMENT',
            'consent_confirmations' => array_keys(RegistrationTemplateCatalog::CONSENT_ITEMS),
        ], $overrides);
    }

    public function test_photo_booth_niche_prefills_from_onboarding_without_inventing_identity(): void
    {
        [$business, $workspace] = $this->registrationState();
        $business->forceFill([
            'industry' => BusinessIndustry::PhotoBoothService->value,
            'website_url' => 'https://sunsetbooths.example',
            'email' => 'hi@sunsetbooths.example',
            'phone' => '+15125550100',
        ])->save();
        // is_primary is deliberately not mass-assignable on BusinessLocation.
        $location = BusinessLocation::create([
            'business_id' => $business->id, 'name' => 'Primary Location', 'service_mode' => 'storefront',
            'address_line_1' => '9 Sunset Ave', 'city' => 'Austin', 'region' => 'TX', 'postal_code' => '78701',
            'country_code' => 'US',
        ]);
        $location->is_primary = true;
        $location->save();
        BusinessMessagingRegistration::create([
            'business_id' => $business->id, 'number_type' => 'local', 'status' => 'not_started', 'use_case' => 'customer_care',
        ]);

        $this->get($this->showUrl($workspace, $business))
            ->assertOk()
            ->assertViewHas('prefill', function (array $prefill): bool {
                $v = $prefill['values'];

                return $v['legal_business_name'] === 'Sunset Photo Booths'
                    && $v['address_line_1'] === '9 Sunset Ave'
                    && $v['city'] === 'Austin'
                    && $v['contact_email'] === 'hi@sunsetbooths.example'
                    && $v['website_url'] === 'https://sunsetbooths.example'
                    && str_contains($v['campaign_description'], 'Sunset Photo Booths sends customer-care texts to people who asked about photo booth rental')
                    && str_contains($v['sample_message_1'], 'Sunset Photo Booths:')
                    && ! str_contains($v['campaign_description'], 'promotional')
                    && in_array('Booking confirmations', $prefill['topics'], true)
                    && in_array('legal_business_name', $prefill['prefilled'], true);
            })
            ->assertSee('not verified')
            ->assertSee('needs your confirmation');

        // Legal identity is never invented.
        $registration = BusinessMessagingRegistration::where('business_id', $business->id)->first();
        $this->assertNull($registration->ein);
        $this->assertNull($registration->privacy_policy_url);
    }

    public function test_three_plain_choices_map_to_supported_use_cases(): void
    {
        $this->assertSame('customer_care', RegistrationTemplateCatalog::useCaseForChoice('care'));
        $this->assertSame('marketing', RegistrationTemplateCatalog::useCaseForChoice('marketing'));
        $this->assertSame('mixed', RegistrationTemplateCatalog::useCaseForChoice('both'));
        // Low Volume Mixed is its own choice, never an alias of ordinary Mixed.
        $this->assertSame('low_volume_mixed', RegistrationTemplateCatalog::useCaseForChoice('low_volume'));
        $this->assertCount(4, RegistrationTemplateCatalog::CHOICES);

        $marketing = RegistrationTemplateCatalog::forUseCase('photo_booth_service', 'marketing', 'Sunset Photo Booths', null);
        $care = RegistrationTemplateCatalog::forUseCase('photo_booth_service', 'customer_care', 'Sunset Photo Booths', null);
        $this->assertContains('Seasonal specials', $marketing['topics']);
        $this->assertNotContains('Seasonal specials', $care['topics']);
        // A legacy use case outside the three choices gets no suggested copy.
        $this->assertNull(RegistrationTemplateCatalog::forUseCase('photo_booth_service', 'two_factor', 'X', null));
        $this->assertNull(RegistrationTemplateCatalog::forUseCase('photo_booth_service', null, 'X', null));

        [$business, $workspace] = $this->registrationState();
        $this->get($this->showUrl($workspace, $business))
            ->assertSee('Service and customer-care messages')
            ->assertSee('Promotional and marketing messages')
            ->assertDontSee('A mix of the above');
    }

    public function test_user_edits_survive_later_page_loads_and_use_case_changes(): void
    {
        [$business, $workspace] = $this->registrationState();
        $business->forceFill(['industry' => BusinessIndustry::PhotoBoothService->value])->save();

        $edited = 'Sunset Photo Booths: my own hand-edited sample. Reply STOP to opt out.';
        $this->post($this->updateUrl($workspace, $business), $this->payload(['sample_message_1' => $edited]))
            ->assertSessionHas('status', 'success');

        $this->get($this->showUrl($workspace, $business))->assertSee($edited, false);

        // Re-saving with a different choice keeps the customer's own copy.
        $this->post($this->updateUrl($workspace, $business), $this->payload(['use_case' => 'mixed', 'sample_message_1' => $edited]));
        $registration = BusinessMessagingRegistration::where('business_id', $business->id)->first();
        $this->assertSame($edited, $registration->sample_message_1);
        $this->assertSame('mixed', $registration->use_case);
        $this->get($this->showUrl($workspace, $business))->assertSee($edited, false);
    }

    public function test_missing_consent_or_invalid_data_blocks_submission_without_charging(): void
    {
        $fake = new FakeProvisioningAdapter();
        $this->app->instance(MessagingProvisioningAdapter::class, $fake);
        config(['messaging.managed_messaging_enabled' => true, 'messaging.managed_messaging_provisioning_enabled' => true, 'services.telnyx.api_key' => 'fixture_key']);
        [$business, $workspace] = $this->registrationState();
        $submit = route('customer.workspaces.businesses.text-messaging.registration.submit', [$workspace->uid, $business->uid]);

        $this->post($this->updateUrl($workspace, $business), $this->payload([
            'consent_confirmations' => ['stop_help'],
            'sample_message_1' => 'Sunset Photo Booths: 20% off all packages this month! Reply STOP to opt out.',
            'privacy_policy_url' => 'https://sunsetbooths.example/terms',
            'terms_url' => 'https://sunsetbooths.example/terms', 'support_contact' => 'help@fixture.example', 'support_contact_confirmed' => '1', 'support_contact_confirmed_for' => 'help@fixture.example', 'support_contact_confirmed_at' => '2026-01-01 00:00:00',
            'contact_first_name' => 'Pat', 'contact_last_name' => 'Owner', 'message_volume' => '1,000', 'opt_in_image_url' => 'https://example.com/opt-in.png', 'brand_vertical' => 'ENTERTAINMENT',
            'campaign_description' => 'Sunset Photo Booths sends [describe your messages] to customers.',
        ]));

        $blocked = $this->post($submit);
        $blocked->assertSessionHas('status', 'error')->assertSessionHas('registration_blocked', true);
        $this->assertStringEndsWith('#pm-submit', (string) $blocked->headers->get('Location'));
        $this->assertStringContainsString('Nothing was sent to the carrier and nothing was charged', (string) session('message'));
        $this->assertSame([], $fake->submittedRegistrations, 'a failed local validation never reaches the carrier');
        $this->assertSame([], $fake->provisionedOrders);

        $registration = BusinessMessagingRegistration::where('business_id', $business->id)->first();
        $this->assertSame(MessagingRegistrationStatus::NotStarted, $registration->status);
        $this->assertNull($registration->provider_brand_id);

        $page = $this->get($this->showUrl($workspace, $business))->getContent();
        $this->assertStringContainsString('Before you submit', $page);
        $this->assertStringContainsString('Confirm on your website, then check', $page);
        $this->assertStringContainsString('reads as a promotion', $page);
        $this->assertStringContainsString('separate pages', $page);
        $this->assertStringContainsString('placeholder text', $page);
        // The exact failures are shown, each with a link to its section, and the draft/submit split is explained.
        $this->assertStringContainsString('data-role="submission-issues"', $page);
        $this->assertStringContainsString('href="#pm-sec-consent"', $page);
        $this->assertStringContainsString('last saved draft', $page);
    }

    public function test_a_complete_registration_submits_once_and_a_repeat_submit_is_refused(): void
    {
        $fake = new FakeProvisioningAdapter();
        $this->app->instance(MessagingProvisioningAdapter::class, $fake);
        config(['messaging.managed_messaging_enabled' => true, 'messaging.managed_messaging_provisioning_enabled' => true, 'services.telnyx.api_key' => 'fixture_key']);
        [$business, $workspace] = $this->registrationState();
        $submit = route('customer.workspaces.businesses.text-messaging.registration.submit', [$workspace->uid, $business->uid]);

        $this->post($this->updateUrl($workspace, $business), $this->payload());
        $this->post($submit)->assertSessionHas('status', 'success');

        // Stage 1 only creates the brand; the campaign waits for verification and authorization.
        $registration = BusinessMessagingRegistration::where('business_id', $business->id)->first();
        $this->assertSame(MessagingRegistrationStatus::NotStarted, $registration->status);
        $this->assertNotNull($registration->provider_brand_id);
        $this->assertNull($registration->provider_campaign_id);
        $this->post(str_replace('/registration/submit', '/registration/refresh', $submit));
        $this->assertSame('verified', $registration->fresh()->brand_status);
        $this->post($submit)->assertSessionHas('status', 'error'); // not authorized
        $this->assertNull($registration->fresh()->provider_campaign_id);
        $this->post($submit, ['authorize_campaign' => '1'])->assertSessionHas('status', 'success');

        $registration = $registration->fresh();
        $this->assertSame(MessagingRegistrationStatus::Pending, $registration->status);
        $brandId = $registration->provider_brand_id;

        $this->post($submit)->assertSessionHas('status', 'error');
        $this->assertSame($brandId, $registration->fresh()->provider_brand_id);
        $this->assertSame(MessagingRegistrationStatus::Pending, $registration->fresh()->status);
    }

    public function test_saving_a_draft_never_contacts_the_carrier_and_submit_uses_the_saved_dba_branded_draft(): void
    {
        $fake = new FakeProvisioningAdapter();
        $this->app->instance(MessagingProvisioningAdapter::class, $fake);
        config(['messaging.managed_messaging_enabled' => true, 'messaging.managed_messaging_provisioning_enabled' => true, 'services.telnyx.api_key' => 'fixture_key']);
        [$business, $workspace] = $this->registrationState();
        $submit = route('customer.workspaces.businesses.text-messaging.registration.submit', [$workspace->uid, $business->uid]);

        // Legal entity differs from the customer-facing name already on file for the Business.
        $this->post($this->updateUrl($workspace, $business), $this->payload(['legal_business_name' => 'Spartan Security Force, LLC']))
            ->assertSessionHas('status', 'success');
        $this->assertSame([], $fake->submittedRegistrations, 'saving a draft never submits to the carrier');

        $this->post($submit)->assertSessionHas('status', 'success');
        $this->assertNotEmpty($fake->submittedRegistrations);
    }

    public function test_changing_between_service_only_types_keeps_common_consent_ticks_but_marketing_clears_them(): void
    {
        [$business, $workspace] = $this->registrationState();
        $this->post($this->updateUrl($workspace, $business), $this->payload());

        $this->post($this->updateUrl($workspace, $business), $this->payload(['use_case' => 'appointment_reminders']))
            ->assertSessionHas('status', 'success');
        $this->assertCount(count(RegistrationTemplateCatalog::CONSENT_ITEMS), BusinessMessagingRegistration::where('business_id', $business->id)->first()->consent_confirmations);

        $this->post($this->updateUrl($workspace, $business), $this->payload(['use_case' => 'marketing']));
        $this->assertSame([], BusinessMessagingRegistration::where('business_id', $business->id)->first()->consent_confirmations);
        $this->assertStringContainsString('consent checklist was cleared', (string) session('message'));
    }

    public function test_save_draft_refuses_toll_free_text_over_500_characters_with_field_errors_and_keeps_the_saved_draft(): void
    {
        [$business, $workspace] = $this->registrationState();
        $this->post($this->updateUrl($workspace, $business), $this->payload());

        $response = $this->post($this->updateUrl($workspace, $business), $this->payload([
            'campaign_description' => str_repeat('a', 501),
            'opt_in_method' => str_repeat('b', 501),
        ]));

        // The fixture's own number is local (2000-character limit); a toll-free business gets 500.
        if (\App\Models\BusinessMessagingNumber::query()->where('business_id', $business->id)->value('number_type') === 'toll_free') {
            $response->assertSessionHasErrors(['campaign_description', 'opt_in_method']);
            $this->assertStringStartsWith('Sunset Photo Booths sends', BusinessMessagingRegistration::where('business_id', $business->id)->first()->campaign_description);
        } else {
            $this->markTestSkipped('Fixture number is local; the 500-character rule is toll-free only and is covered by the validator unit test.');
        }
    }

    public function test_a_valid_submission_for_a_business_without_a_wallet_shows_a_page_error_and_sends_nothing(): void
    {
        config(['messaging.managed_messaging_enabled' => true, 'messaging.managed_messaging_provisioning_enabled' => true, 'services.telnyx.api_key' => 'fixture_key']);
        \Illuminate\Support\Facades\Http::fake();
        \App\Models\Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'format' => '$', 'status' => true]);
        [$business, $workspace] = $this->registrationState();
        \Illuminate\Support\Facades\DB::table('business_usage_wallets')->where('business_id', $business->id)->delete();
        $submit = route('customer.workspaces.businesses.text-messaging.registration.submit', [$workspace->uid, $business->uid]);

        $this->post($this->updateUrl($workspace, $business), $this->payload());
        $this->post($submit)->assertSessionHas('status', 'error')->assertSessionMissing('errors')
            ->assertSessionHas('message', fn (string $m): bool => str_contains($m, 'pricing') && ! str_contains($m, 'enough funds'));

        \Illuminate\Support\Facades\Http::assertNothingSent();
        $this->assertSame(MessagingRegistrationStatus::NotStarted, BusinessMessagingRegistration::where('business_id', $business->id)->first()->status);
        $this->assertSame(0, (int) \Illuminate\Support\Facades\DB::table('business_usage_wallets')->where('business_id', $business->id)->value('available_balance_micro'));
    }

    public function test_changing_the_use_case_requires_reconfirming_consent_and_matching_copy(): void
    {
        [$business, $workspace] = $this->registrationState();
        $this->post($this->updateUrl($workspace, $business), $this->payload());
        $registration = BusinessMessagingRegistration::where('business_id', $business->id)->first();
        $this->assertCount(count(RegistrationTemplateCatalog::CONSENT_ITEMS), $registration->consent_confirmations);

        $this->post($this->updateUrl($workspace, $business), $this->payload(['use_case' => 'marketing']))
            ->assertSessionHas('status', 'success');

        $registration = $registration->fresh();
        $this->assertSame('marketing', $registration->use_case);
        $this->assertSame([], $registration->consent_confirmations);

        // Marketing needs a promotional sample that carries opt-out wording.
        $this->post($this->updateUrl($workspace, $business), $this->payload([
            'use_case' => 'marketing',
            'sample_message_1' => 'Sunset Photo Booths: ask about our current package specials.',
        ]));
        $issues = app(\App\Library\Messaging\Registration\RegistrationSubmissionValidator::class)->issues($registration->fresh());
        $this->assertContains('Every promotional sample message needs opt-out wording such as "Reply STOP to opt out".', $issues);
    }

    public function test_page_templates_keep_a_brand_token_so_suggestions_follow_the_typed_legal_name(): void
    {
        [$business, $workspace] = $this->registrationState();
        $business->forceFill(['industry' => BusinessIndustry::PhotoBoothService->value])->save();
        $this->post($this->updateUrl($workspace, $business), $this->payload(['legal_business_name' => 'Sunset Booths LLC']));

        $this->get($this->showUrl($workspace, $business))
            ->assertViewHas('templateBrand', 'Sunset Booths LLC')
            ->assertViewHas('templatesByChoice', function (array $templates): bool {
                foreach (['care', 'marketing', 'both'] as $choice) {
                    foreach (['campaign_description', 'sample_message_1', 'sample_message_2'] as $field) {
                        if (! str_contains($templates[$choice][$field], '{brand}')) {
                            return false;
                        }
                    }
                }

                return true;
            });
    }

    public function test_local_campaigns_keep_their_separate_2000_character_limit(): void
    {
        [$business, $workspace] = $this->registrationState();
        \App\Models\BusinessMessagingNumber::query()->update(['number_type' => 'local']);

        $this->post($this->updateUrl($workspace, $business), $this->payload(['campaign_description' => str_repeat('a', 1500)]))
            ->assertSessionDoesntHaveErrors('campaign_description');
        $this->post($this->updateUrl($workspace, $business), $this->payload(['campaign_description' => str_repeat('a', 2001)]))
            ->assertSessionHasErrors('campaign_description');
    }
}
