<?php

namespace Tests\Feature\Calendar;

use App\Library\Calendar\DemoBooking\Form\DemoFormDefaults;
use App\Library\Calendar\DemoBooking\Form\DemoFormRepository;
use App\Library\Calendar\DemoBooking\Form\DemoFormSchema;
use App\Events\Calendar\AppointmentScheduled;
use App\Models\AppConfig;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\Contacts;
use App\Models\ContactsCustomField;
use App\Models\DemoBookingResponse;
use App\Models\DemoFormVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\Feature\PlatformOwner\Concerns\PlatformOwnerFixtures;
use Tests\TestCase;

/** The owner-editable Book a Demo form: definition rules, the public page, the editor, and what a booking keeps. */
class DemoFormTest extends TestCase
{
    use CreatesCalendarHttpFixtures;
    use PlatformOwnerFixtures;
    use RefreshDatabase;

    private $type;

    /**
     * Both fixture traits declare this identical environment fixture (customer-shell config rows), so PHP cannot
     * compose them without a rule. The class's own method resolves it and keeps the setup both need.
     */
    protected function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])->pluck('setting')->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }
        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }
        if (! in_array('customer_permissions', $existing, true)) {
            AppConfig::create(collect((new AppConfig())->defaultSettings())->firstWhere('setting', 'customer_permissions'));
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-25 12:00:00 UTC');
        $this->bootCalendarHttpFixtures();
        $this->bootPlatformOwnerFixtures();
        $this->type = $this->bookingType(null, ['name' => 'MotionGrove Demo', 'notify_email' => true]);
        $this->type->staff()->attach($this->bookableStaff()->id);
        config([
            'demo_booking.booking_type_uuid' => $this->type->public_booking_uuid,
            'demo_booking.turnstile.site_key' => 'site-key-public',
            'demo_booking.turnstile.secret_key' => 'secret-key-private',
            'demo_booking.turnstile.bypass' => false,
        ]);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true, 'hostname' => 'localhost'])]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fields(): array
    {
        return DemoFormDefaults::fields();
    }

    /** Fields as the editor posts them, with one extra question added. */
    private function withExtra(array $extra): array
    {
        return array_merge($this->fields(), [$extra + [
            'key' => 'custom_ab12cd34', 'type' => 'short_text', 'label' => 'How did you hear about us?', 'placeholder' => '', 'help' => '',
            'required' => false, 'visible' => true, 'half' => false, 'show_optional' => true, 'options' => [], 'system' => false,
        ]]);
    }

    private function publish(array $fields): void
    {
        $repo = app(DemoFormRepository::class);
        $repo->saveDraft(DemoFormSchema::normalize($fields)['fields'], 1);
        $repo->publish(1);
    }

    private function payload(array $override = []): array
    {
        return $override + [
            'date' => '2027-03-01', 'time' => '10:00', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test',
            'business_name' => 'Analytical Engines Ltd', 'phone' => '+1 (415) 555-1234', 'industry' => 'home_services',
            'help_with' => ['website', 'crm'], 'message' => 'Looking forward to it.', 'cf-turnstile-response' => 'token-1',
        ];
    }

    // ---- the definition ----------------------------------------------------------------------

    public function test_the_default_form_is_the_claude_design_configuration(): void
    {
        $fields = DemoFormDefaults::fields();

        $this->assertSame(['first_name', 'last_name', 'email', 'phone', 'business_name', 'industry', 'help_with', 'message'], array_column($fields, 'key'));
        $this->assertSame(['Choose one'], [$fields[5]['placeholder']]);
        $this->assertSame(['Website', 'Follow-ups', 'Bookings', 'SEO & ads', 'Organizing leads', 'Agency clients'], array_column($fields[6]['options'], 'label'));
        $this->assertSame([], DemoFormSchema::normalize($fields)['errors']);
    }

    public function test_system_fields_cannot_be_removed_hidden_or_retyped(): void
    {
        $fields = $this->fields();
        $email = array_search('email', array_column($fields, 'key'), true);
        $fields[$email]['visible'] = false;
        $fields[$email]['required'] = false;
        $fields[$email]['type'] = 'short_text';
        $fields[$email]['label'] = 'Your email';

        $normalized = DemoFormSchema::normalize($fields)['fields'][$email];
        $this->assertTrue($normalized['visible']);
        $this->assertTrue($normalized['required']);
        $this->assertSame('email', $normalized['type']);
        $this->assertSame('Your email', $normalized['label']);

        $without = array_values(array_filter($this->fields(), fn ($f) => $f['key'] !== 'business_name'));
        $this->assertNotEmpty(DemoFormSchema::normalize($without)['errors']);
    }

    public function test_definitions_are_rejected_when_unsafe(): void
    {
        $bad = [
            $this->withExtra(['key' => 'date']),
            $this->withExtra(['key' => 'Bad Key!']),
            $this->withExtra(['type' => 'file']),
            $this->withExtra(['label' => '']),
            $this->withExtra(['type' => 'dropdown', 'options' => []]),
            array_merge($this->withExtra([]), [$this->withExtra([])[8]]),
        ];
        foreach ($bad as $fields) {
            $this->assertNotEmpty(DemoFormSchema::normalize($fields)['errors']);
        }
    }

    private const PROTECTED = ['first_name', 'last_name', 'email', 'phone', 'business_name', 'industry'];

    private function index(array $fields, string $key): int
    {
        return (int) array_search($key, array_column($fields, 'key'), true);
    }

    public function test_the_protected_fields_cannot_be_hidden_removed_or_retyped_by_a_crafted_request(): void
    {
        // Every protected field: hidden, optional where allowed, and given the wrong type.
        $crafted = $this->fields();
        foreach (self::PROTECTED as $key) {
            $crafted[$this->index($crafted, $key)]['visible'] = false;
            $crafted[$this->index($crafted, $key)]['type'] = 'long_text';
            $crafted[$this->index($crafted, $key)]['system'] = false;
        }
        $normalized = collect(DemoFormSchema::normalize($crafted)['fields'])->keyBy('key');

        foreach (self::PROTECTED as $key) {
            $this->assertTrue($normalized[$key]['visible'], "{$key} stays visible");
            $this->assertTrue($normalized[$key]['system']);
        }
        $this->assertSame(['short_text', 'short_text', 'email', 'phone', 'short_text', 'dropdown'],
            array_map(fn ($k) => $normalized[$k]['type'], ['first_name', 'last_name', 'email', 'phone', 'business_name', 'industry']));

        // Each one, removed on its own, is rejected.
        foreach (self::PROTECTED as $key) {
            $without = array_values(array_filter($this->fields(), fn ($f) => $f['key'] !== $key));
            $this->assertNotEmpty(DemoFormSchema::normalize($without)['errors'], "{$key} cannot be removed");
        }
    }

    public function test_required_and_optional_flags_follow_the_shared_rules(): void
    {
        $normalized = collect(DemoFormSchema::normalize($this->fields())['fields'])->keyBy('key');
        foreach (DemoFormDefaults::ALWAYS_REQUIRED as $key) {
            $this->assertTrue($normalized[$key]['required'], "{$key} must be answered");
        }
        foreach (['last_name', 'phone', 'industry'] as $key) {
            $this->assertFalse($normalized[$key]['required'], "{$key} stays optional");
        }

        // Optional ones may be made required; the always-required ones cannot be made optional.
        $fields = $this->fields();
        $fields[$this->index($fields, 'industry')]['required'] = true;
        $fields[$this->index($fields, 'email')]['required'] = false;
        $fields[$this->index($fields, 'business_name')]['required'] = false;
        $out = collect(DemoFormSchema::normalize($fields)['fields'])->keyBy('key');
        $this->assertTrue($out['industry']['required']);
        $this->assertTrue($out['email']['required']);
        $this->assertTrue($out['business_name']['required']);
    }

    public function test_industry_options_can_be_renamed_added_removed_and_reordered_with_stable_values(): void
    {
        $fields = $this->fields();
        $at = $this->index($fields, 'industry');
        $before = collect($fields[$at]['options'])->pluck('value', 'label');
        $fields[$at]['label'] = 'Your industry';
        $fields[$at]['placeholder'] = 'Pick one';
        $fields[$at]['help'] = 'Helps us prepare.';
        $fields[$at]['options'] = [
            ['value' => $before['Marketing agency'], 'label' => 'Agency'],          // renamed, moved first
            ['value' => $before['Home services'], 'label' => 'Home services'],
            ['value' => '', 'label' => 'Landscaping'],                                // added
        ];                                                                          // the rest removed
        $result = DemoFormSchema::normalize($fields);

        $this->assertSame([], $result['errors']);
        $industry = $result['fields'][$at];
        $this->assertSame('industry', $industry['key']);
        $this->assertSame('dropdown', $industry['type']);
        $this->assertSame([$before['Marketing agency'], $before['Home services'], 'landscaping'], array_column($industry['options'], 'value'));
        $this->assertSame('Your industry', $industry['label']);

        $fields[$at]['options'] = [];
        $this->assertNotEmpty(DemoFormSchema::normalize($fields)['errors'], 'a dropdown needs at least one option');
    }

    public function test_a_published_industry_customisation_renders_on_the_public_form(): void
    {
        $fields = $this->fields();
        $at = $this->index($fields, 'industry');
        $fields[$at]['label'] = 'Your industry';
        $fields[$at]['required'] = true;
        $fields[$at]['options'] = [['value' => 'agency', 'label' => 'Agency'], ['value' => 'landscaping', 'label' => 'Landscaping']];
        $this->publish($fields);

        $this->get('/book-demo')->assertOk()->assertSee('Your industry')->assertSee('name="industry"', false)
            ->assertSee('<option value="landscaping">Landscaping</option>', false)->assertDontSee('Photo booth rentals');
        $this->postJson(route('demo.booking.details'), ['first_name' => 'A', 'email' => 'a@example.test', 'business_name' => 'B'])
            ->assertStatus(422)->assertJsonValidationErrors(['industry']);
    }

    public function test_versions_saved_before_the_protections_still_show_every_protected_field(): void
    {
        // An older published version where Phone was hidden and Industry removed.
        $old = array_values(array_filter($this->fields(), fn ($f) => $f['key'] !== 'industry'));
        $old[$this->index($old, 'phone')]['visible'] = false;
        \App\Models\DemoFormVersion::query()->create(['status' => 'published', 'fields' => $old, 'published_at' => now()]);

        $live = collect(app(DemoFormRepository::class)->published()['fields'])->keyBy('key');
        foreach (self::PROTECTED as $key) {
            $this->assertTrue($live[$key]['visible'], "{$key} is visible");
        }
        $this->get('/book-demo')->assertOk()->assertSee('name="phone"', false)->assertSee('name="industry"', false);
    }

    public function test_other_questions_can_still_be_added_removed_retyped_and_reordered(): void
    {
        $fields = $this->withExtra(['key' => 'team_size', 'type' => 'radio', 'label' => 'Team size', 'options' => [['value' => '', 'label' => 'Just me'], ['value' => '', 'label' => '2 to 10']]]);
        $fields = array_values(array_filter($fields, fn ($f) => $f['key'] !== 'message'));   // optional field removed
        $fields[$this->index($fields, 'help_with')]['type'] = 'dropdown';                    // retyped
        $fields[$this->index($fields, 'help_with')]['visible'] = false;                      // hidden
        array_unshift($fields, array_pop($fields));                                           // reordered to the top
        $this->publish($fields);

        $live = array_column(app(DemoFormRepository::class)->published()['fields'], 'key');
        $this->assertSame('team_size', $live[0]);
        $this->assertNotContains('message', $live);
        $this->get('/book-demo')->assertOk()->assertSee('Team size')->assertSee('name="team_size"', false)
            ->assertDontSee('What would you most like help with?')->assertDontSee('name="message"', false);
    }

    public function test_the_editor_rejects_a_crafted_request_that_removes_a_protected_field(): void
    {
        $this->actingAsPlatformOwner();
        foreach (['industry', 'phone', 'business_name'] as $key) {
            $without = array_values(array_filter($this->fields(), fn ($f) => $f['key'] !== $key));
            $this->post(route('admin.platform-owner.demo-form.publish'), ['fields' => json_encode($without)])->assertSessionHasErrors('fields');
        }
        $this->assertDatabaseCount('demo_form_versions', 0);
    }

    public function test_option_values_are_stable_and_independent_of_labels(): void
    {
        $fields = $this->withExtra(['key' => 'team_size', 'type' => 'radio', 'label' => 'Team size', 'options' => [['value' => '', 'label' => '1 to 5'], ['value' => '', 'label' => '1 to 5!'], ['value' => 'big', 'label' => 'Over 50']]]);
        $options = DemoFormSchema::normalize($fields)['fields'][8]['options'];

        $this->assertSame(['1_to_5', '1_to_5_2', 'big'], array_column($options, 'value'));
    }

    // ---- the public page ---------------------------------------------------------------------

    public function test_a_published_field_appears_on_the_public_form_in_the_design_markup(): void
    {
        $this->publish($this->withExtra(['label' => 'How did you hear about us?', 'type' => 'dropdown', 'key' => 'source',
            'options' => [['value' => 'search', 'label' => 'Search'], ['value' => 'friend', 'label' => 'A friend']]]));

        $this->get('/book-demo')->assertOk()
            ->assertSee('How did you hear about us?')->assertSee('name="source"', false)
            ->assertSee('<option value="friend">A friend</option>', false)->assertSee('class="f"', false);
    }

    public function test_hidden_optional_fields_are_not_rendered_or_accepted(): void
    {
        $fields = $this->fields();
        $message = array_search('message', array_column($fields, 'key'), true);
        $fields[$message]['visible'] = false;
        $this->publish($fields);

        $this->get('/book-demo')->assertOk()->assertDontSee('name="message"', false)->assertSee('name="phone"', false);
        $this->postJson(route('demo.booking.details'), ['first_name' => 'A', 'email' => 'a@example.test', 'business_name' => 'B', 'message' => str_repeat('x', 5000)])->assertOk();
    }

    public function test_step_one_validates_against_the_published_form_without_booking_anything(): void
    {
        $this->postJson(route('demo.booking.details'), ['first_name' => '', 'email' => 'nope', 'business_name' => ''])
            ->assertStatus(422)->assertJsonValidationErrors(['first_name', 'email', 'business_name']);
        $this->postJson(route('demo.booking.details'), ['first_name' => 'Ada', 'email' => 'ada@example.test', 'business_name' => 'AE', 'industry' => 'not-an-option'])
            ->assertStatus(422)->assertJsonValidationErrors(['industry']);
        $this->postJson(route('demo.booking.details'), ['first_name' => 'Ada', 'email' => 'ada@example.test', 'business_name' => 'AE'])
            ->assertOk()->assertJson(['status' => 'ok', 'form_version' => 'default']);

        $this->assertDatabaseCount('appointments', 0);
        Http::assertNothingSent();
    }

    // ---- what a booking keeps ----------------------------------------------------------------

    public function test_answers_are_validated_server_side_and_saved_with_the_appointment_and_contact(): void
    {
        $this->publish($this->withExtra([]));

        $this->post(route('demo.booking.store'), $this->payload(['industry' => 'nonsense']))->assertSessionHasErrors('industry');
        $this->post(route('demo.booking.store'), $this->payload(['help_with' => ['website', 'invented']]))->assertSessionHasErrors('help_with.1');
        $this->assertDatabaseCount('appointments', 0);

        $this->post(route('demo.booking.store'), $this->payload(['custom_ab12cd34' => 'A podcast']))->assertRedirect(route('demo.booking.confirmed'));

        $appointment = Appointment::query()->sole();
        $response = DemoBookingResponse::query()->sole();
        $this->assertSame((int) $appointment->id, (int) $response->appointment_id);
        $this->assertSame((int) $appointment->contact_id, (int) $response->contact_id);
        $this->assertNotNull($response->form_version_id);
        $byKey = collect($response->answers)->keyBy('key');
        $this->assertSame('Home services', $byKey['industry']['display']);
        $this->assertSame('Website, Organizing leads', $byKey['help_with']['display']);
        $this->assertSame('A podcast', $byKey['custom_ab12cd34']['display']);
        $this->assertSame('How did you hear about us?', $byKey['custom_ab12cd34']['label']);

        // The owner's extra answers also reach the Contact as CRM fields.
        $contact = Contacts::query()->findOrFail($appointment->contact_id);
        $crm = ContactsCustomField::query()->where('contact_id', $contact->id)->with('field')->get()
            ->mapWithKeys(fn ($row) => [$row->field->tag => $row->value]);
        $this->assertSame('Home services', $crm['DEMO_INDUSTRY']);
        $this->assertSame('A podcast', $crm['DEMO_CUSTOM_AB12CD34']);
    }

    public function test_a_failed_answer_save_rolls_the_whole_booking_back(): void
    {
        Notification::fake();
        Event::fake([AppointmentScheduled::class]);
        $contactsBefore = Contacts::query()->count();
        // Eloquent listeners outlive a single request, so the injection is switched off by a flag, not by removal.
        $failStorage = true;
        DemoBookingResponse::creating(static function () use (&$failStorage): void {
            if ($failStorage) {
                throw new \RuntimeException('forced answer-storage failure');
            }
        });

        $this->postJson(route('demo.booking.store'), $this->payload(), ['X-Requested-With' => 'XMLHttpRequest'])->assertStatus(500)
            ->assertJsonMissing(['status' => 'confirmed']);

        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('demo_booking_responses', 0);
        $this->assertSame($contactsBefore, Contacts::query()->count(), 'the Contact created for the booking rolled back too');
        $this->assertSame(0, AppointmentNotification::query()->count());
        Event::assertNotDispatched(AppointmentScheduled::class);
        Notification::assertNothingSent();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'zoom'));

        // The slot is still free: the same request succeeds once storage works again.
        $failStorage = false;
        $this->postJson(route('demo.booking.store'), $this->payload(), ['X-Requested-With' => 'XMLHttpRequest'])->assertCreated();
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('demo_booking_responses', 1);
    }

    public function test_a_failed_crm_mirror_never_loses_the_booking_or_its_answers(): void
    {
        $this->publish($this->withExtra([]));
        $failMirror = true;
        ContactsCustomField::creating(static function ($row) use (&$failMirror): void {
            if ($failMirror && (string) $row->value === 'A podcast') {
                throw new \RuntimeException('forced CRM mirror failure');
            }
        });

        $this->postJson(route('demo.booking.store'), $this->payload(['custom_ab12cd34' => 'A podcast']), ['X-Requested-With' => 'XMLHttpRequest'])->assertCreated();

        $this->assertDatabaseCount('appointments', 1);
        $this->assertSame('A podcast', collect(DemoBookingResponse::query()->sole()->answers)->firstWhere('key', 'custom_ab12cd34')['display']);
        $failMirror = false;
    }

    public function test_the_answers_commit_with_the_booking_and_other_booking_types_are_unaffected(): void
    {
        $this->post(route('demo.booking.store'), $this->payload())->assertRedirect(route('demo.booking.confirmed'));

        $this->assertSame(Appointment::query()->sole()->id, DemoBookingResponse::query()->sole()->appointment_id);
        $this->assertCount(8, DemoBookingResponse::query()->sole()->answers, 'the complete canonical snapshot');

        // The generic public booking page and write are not given a demo form.
        $other = $this->bookingType(null, ['name' => 'Another service']);
        $other->staff()->attach($this->bookableStaff()->id);
        $this->get(route('public.booking.show', [$other->public_booking_uuid]))->assertOk()
            ->assertDontSee('name="industry"', false)->assertDontSee('Tell us a little about you');
    }

    public function test_changing_the_form_does_not_alter_answers_already_saved(): void
    {
        $this->post(route('demo.booking.store'), $this->payload())->assertRedirect();
        $before = DemoBookingResponse::query()->sole()->answers;

        $fields = array_values(array_filter($this->fields(), fn ($f) => $f['key'] !== 'industry'));
        $this->publish($fields);

        $this->assertSame($before, DemoBookingResponse::query()->sole()->fresh()->answers);
    }

    public function test_a_visitor_who_started_on_an_older_form_is_told_when_it_changed_under_them(): void
    {
        $fields = $this->fields();
        $industry = array_search('industry', array_column($fields, 'key'), true);
        $fields[$industry]['required'] = true;
        $this->publish($fields);

        $this->postJson(route('demo.booking.store'), $this->payload(['industry' => '', 'form_version' => 'default']), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(422)->assertJson(['form_changed' => true])->assertJsonValidationErrors(['industry']);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_the_appointment_page_shows_the_prospects_answers(): void
    {
        $this->post(route('demo.booking.store'), $this->payload())->assertRedirect();
        $appointment = Appointment::query()->sole();
        $response = DemoBookingResponse::query()->sole();

        $this->assertSame('Industry', $response->answers[5]['label']);
        $this->assertSame($appointment->id, $appointment->demoBookingResponse->appointment_id);
    }

    // ---- the owner editor --------------------------------------------------------------------

    public function test_the_editor_is_for_platform_owners_only(): void
    {
        $publish = ['fields' => json_encode($this->withExtra(['label' => 'Should never go live']))];

        // Not signed in: refused outright (HTTP 401), nothing written.
        $this->get(route('admin.platform-owner.demo-form'))->assertUnauthorized();
        $this->post(route('admin.platform-owner.demo-form.publish'), $publish)->assertUnauthorized();
        $this->assertDatabaseCount('demo_form_versions', 0);

        // A signed-in customer (not a platform administrator) cannot open or change it.
        $customer = \App\Models\User::create([
            'first_name' => 'Cass', 'last_name' => 'Customer', 'email' => 'cass-'.uniqid('', true).'@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $this->actingAs($customer);
        $this->assertNotSame(200, $this->get(route('admin.platform-owner.demo-form'))->getStatusCode());
        $this->assertNotSame(200, $this->get(route('admin.platform-owner.demo-form.preview'))->getStatusCode());
        $this->assertNotSame(200, $this->post(route('admin.platform-owner.demo-form.publish'), $publish)->getStatusCode());
        $this->assertDatabaseCount('demo_form_versions', 0);

        // A platform administrator without the backend permission is stopped by the admin route group's
        // 'can:access backend' gate, which this application answers with HTTP 401, and writes nothing.
        $this->actingAsPlatformOwner(['view business']);
        $this->get(route('admin.platform-owner.demo-form'))->assertUnauthorized();
        $this->post(route('admin.platform-owner.demo-form.publish'), $publish)->assertUnauthorized();
        $this->assertDatabaseCount('demo_form_versions', 0);

        $this->actingAsPlatformOwner();
        $this->get(route('admin.platform-owner.demo-form'))->assertOk()->assertSee('Demo Form');
        $this->get(route('admin.platform-owner.demo-form.preview'))->assertOk()->assertSee('name="first_name"', false)->assertSee('noindex', false);
    }

    public function test_saving_a_draft_does_not_change_the_public_form_until_publish(): void
    {
        $this->actingAsPlatformOwner();
        $fields = $this->withExtra(['label' => 'Draft only question']);

        $this->post(route('admin.platform-owner.demo-form.save'), ['fields' => json_encode($fields)])->assertRedirect();
        $this->get('/book-demo')->assertOk()->assertDontSee('Draft only question');
        $this->get(route('admin.platform-owner.demo-form.preview'))->assertSee('Draft only question');

        $this->post(route('admin.platform-owner.demo-form.publish'), ['fields' => json_encode($fields)])->assertRedirect();
        $this->get('/book-demo')->assertOk()->assertSee('Draft only question');
        $this->assertSame(1, DemoFormVersion::query()->where('status', 'published')->count());
        $this->assertDatabaseHas('platform_admin_actions', ['action' => 'demo_form.published']);
    }

    public function test_the_editor_refuses_a_form_that_would_break_booking(): void
    {
        $this->actingAsPlatformOwner();
        $without = array_values(array_filter($this->fields(), fn ($f) => $f['key'] !== 'email'));

        $this->post(route('admin.platform-owner.demo-form.publish'), ['fields' => json_encode($without)])->assertSessionHasErrors('fields');
        $this->assertDatabaseCount('demo_form_versions', 0);
    }
}
