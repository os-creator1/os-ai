<?php

namespace Tests\Feature\Calendar;

use App\Library\Calendar\DemoBooking\Form\DemoFormDefaults;
use App\Library\Calendar\DemoBooking\Form\DemoFormRepository;
use App\Library\Calendar\DemoBooking\Form\DemoFormSchema;
use App\Events\Calendar\AppointmentScheduled;
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

    public function test_hidden_fields_are_not_rendered_or_accepted(): void
    {
        $fields = $this->fields();
        $phone = array_search('phone', array_column($fields, 'key'), true);
        $fields[$phone]['visible'] = false;
        $this->publish($fields);

        $this->get('/book-demo')->assertOk()->assertDontSee('name="phone"', false);
        $this->postJson(route('demo.booking.details'), ['first_name' => 'A', 'email' => 'a@example.test', 'business_name' => 'B', 'phone' => 'garbage'])->assertOk();
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
        DemoBookingResponse::creating(static function (): void {
            throw new \RuntimeException('forced answer-storage failure');
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
        DemoBookingResponse::flushEventListeners();
        $this->postJson(route('demo.booking.store'), $this->payload(), ['X-Requested-With' => 'XMLHttpRequest'])->assertCreated();
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('demo_booking_responses', 1);
    }

    public function test_a_failed_crm_mirror_never_loses_the_booking_or_its_answers(): void
    {
        $this->publish($this->withExtra([]));
        ContactsCustomField::creating(static function ($row): void {
            if ((string) $row->value === 'A podcast') {
                throw new \RuntimeException('forced CRM mirror failure');
            }
        });

        $this->postJson(route('demo.booking.store'), $this->payload(['custom_ab12cd34' => 'A podcast']), ['X-Requested-With' => 'XMLHttpRequest'])->assertCreated();

        $this->assertDatabaseCount('appointments', 1);
        $this->assertSame('A podcast', collect(DemoBookingResponse::query()->sole()->answers)->firstWhere('key', 'custom_ab12cd34')['display']);
        ContactsCustomField::flushEventListeners();
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
        $this->get(route('admin.platform-owner.demo-form'))->assertRedirect();
        $this->post(route('admin.platform-owner.demo-form.publish'), ['fields' => json_encode($this->fields())])->assertRedirect();
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
