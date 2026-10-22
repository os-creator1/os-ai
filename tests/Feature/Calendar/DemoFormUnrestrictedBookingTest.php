<?php

namespace Tests\Feature\Calendar;

use App\Library\Calendar\DemoBooking\DemoBookingManageLinks;
use App\Library\Calendar\DemoBooking\Form\DemoFormDefaults;
use App\Library\Calendar\DemoBooking\Form\DemoFormRepository;
use App\Library\Calendar\DemoBooking\Form\DemoFormSchema;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\ContactsCustomField;
use App\Models\DemoBookingResponse;
use App\Models\DemoFormVersion;
use App\Notifications\Calendar\AppointmentCustomerNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\TestCase;

/**
 * The Book a Demo form is completely owner-editable, down to no questions at all. The booking's own essentials
 * (first name and email) are then collected with the attendee inputs at confirmation, and never asked twice.
 */
class DemoFormUnrestrictedBookingTest extends TestCase
{
    use CreatesCalendarHttpFixtures;
    use RefreshDatabase;

    private $type;

    /** What the faked Cloudflare siteverify answers. */
    private $verdict;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-25 12:00:00 UTC');
        $this->bootCalendarHttpFixtures();
        $this->type = $this->bookingType(null, ['name' => 'MotionGrove Demo', 'notify_email' => true]);
        $this->type->staff()->attach($this->bookableStaff()->id);

        config([
            'demo_booking.booking_type_uuid' => $this->type->public_booking_uuid,
            'demo_booking.turnstile.site_key' => 'site-key-public',
            'demo_booking.turnstile.secret_key' => 'secret-key-private',
            'demo_booking.turnstile.bypass' => false,
        ]);
        $this->verdict = fn () => Http::response(['success' => true, 'hostname' => 'localhost']);
        Http::fake(['challenges.cloudflare.com/*' => fn () => ($this->verdict)()]);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function publish(array $fields): void
    {
        $repo = app(DemoFormRepository::class);
        $repo->saveDraft(DemoFormSchema::normalize($fields)['fields'], 1);
        $repo->publish(1);
    }

    private function field(string $key, string $type, string $label, array $extra = []): array
    {
        return $extra + [
            'key' => $key, 'type' => $type, 'label' => $label, 'placeholder' => '', 'help' => '',
            'required' => false, 'visible' => true, 'half' => false, 'show_optional' => false, 'options' => [],
        ];
    }

    private function book(array $payload)
    {
        return $this->post(route('demo.booking.store'), $payload + ['date' => '2027-03-01', 'time' => '10:00', 'cf-turnstile-response' => 'token-1']);
    }

    private function attendee(array $override = []): array
    {
        return $override + ['attendee_first_name' => 'Grace', 'attendee_email' => 'grace@example.test'];
    }

    // ---- the public page with no questions ---------------------------------------------------

    public function test_an_empty_questionnaire_renders_a_clean_page_that_goes_straight_to_the_calendar(): void
    {
        $this->publish([]);

        $this->get('/book-demo')->assertOk()
            ->assertSee('data-state="schedule"', false)
            ->assertSee('Pick a time that suits you.')
            ->assertDontSee('id="mgd-form"', false)
            ->assertDontSee('Tell us a little about you')
            ->assertDontSee('Continue to choose a time')
            ->assertDontSee('Edit my details')
            ->assertSee('id="pb-app"', false)
            ->assertSee('name="attendee_first_name"', false)
            ->assertSee('name="attendee_email"', false)
            ->assertSee('id="mgd-turnstile"', false)
            ->assertSee('Confirm booking');
    }

    // ---- booking with no questions -----------------------------------------------------------

    public function test_an_empty_questionnaire_still_books_with_the_attendee_details(): void
    {
        $this->publish([]);

        $this->book($this->attendee())->assertRedirect(route('demo.booking.confirmed'));

        $appointment = Appointment::query()->sole();
        $this->assertSame([], DemoBookingResponse::query()->sole()->answers);

        // CRM: the Contact exists with the attendee's own name and email, and nothing is invented.
        $values = ContactsCustomField::query()->where('contact_id', $appointment->contact_id)->pluck('value')->all();
        $this->assertContains('Grace', $values);
        $this->assertContains('grace@example.test', $values);
        $this->assertNotContains('Not provided', $values);

        // The confirmation goes to that address and the guest link works.
        $row = AppointmentNotification::query()->where('kind', AppointmentNotification::KIND_CONFIRMATION)->sole();
        $this->assertSame('grace@example.test', $row->recipient_email);
        Notification::assertSentOnDemand(AppointmentCustomerNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'grace@example.test');
        $this->post(DemoBookingManageLinks::urlFor($appointment), ['action' => 'cancel'])->assertOk();
    }

    public function test_the_attendee_essentials_are_required_and_turnstile_still_applies(): void
    {
        $this->publish([]);

        $this->postJson(route('demo.booking.store'), ['date' => '2027-03-01', 'time' => '10:00', 'cf-turnstile-response' => 'token-1'], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(422)->assertJsonValidationErrors(['attendee_first_name', 'attendee_email']);
        $this->postJson(route('demo.booking.store'), $this->attendee(['attendee_email' => 'nope']) + ['date' => '2027-03-01', 'time' => '10:00', 'cf-turnstile-response' => 'token-1'], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(422)->assertJsonValidationErrors(['attendee_email']);
        $this->assertDatabaseCount('appointments', 0);

        $this->verdict = fn () => Http::response(['success' => false]);
        $this->postJson(route('demo.booking.store'), $this->attendee() + ['date' => '2027-03-01', 'time' => '10:00', 'cf-turnstile-response' => 'bad'], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(422)->assertJsonValidationErrors(['turnstile']);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_required_validation_applies_only_to_configured_questions_plus_the_essentials(): void
    {
        $this->publish([$this->field('note', 'short_text', 'Anything to add?', ['required' => true])]);

        // No business name, phone, last name or industry is demanded.
        $this->postJson(route('demo.booking.store'), $this->attendee() + ['date' => '2027-03-01', 'time' => '10:00', 'cf-turnstile-response' => 't'], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(422)->assertJsonValidationErrors(['note'])->assertJsonMissingValidationErrors(['business_name', 'phone', 'last_name', 'industry']);

        $this->book($this->attendee(['note' => 'Hello']))->assertRedirect();
        $this->assertSame('Hello', DemoBookingResponse::query()->sole()->answers[0]['display']);
    }

    // ---- never asked twice -------------------------------------------------------------------

    public function test_questions_the_form_already_asks_are_not_requested_again(): void
    {
        // The design default asks for a required first name and email: no attendee inputs, and none needed to book.
        $this->get('/book-demo')->assertOk()->assertDontSee('name="attendee_first_name"', false)->assertDontSee('name="attendee_email"', false);
        $this->book(['first_name' => 'Ada', 'email' => 'ada@example.test', 'business_name' => 'AE'])->assertRedirect();
        $this->assertContains('ada@example.test', ContactsCustomField::query()->pluck('value')->all());

        // Only the email is asked by the form: the attendee input is for the first name alone.
        $this->publish([$this->field('email', 'email', 'Work email', ['required' => true])]);
        $this->get('/book-demo')->assertOk()->assertSee('name="attendee_first_name"', false)->assertDontSee('name="attendee_email"', false);
        $this->book(['email' => 'both@example.test', 'attendee_first_name' => 'Hedy', 'time' => '13:00'])->assertRedirect();
        $this->assertContains('Hedy', ContactsCustomField::query()->pluck('value')->all());
        $this->assertContains('both@example.test', ContactsCustomField::query()->pluck('value')->all());
    }

    public function test_the_form_answer_wins_over_the_fallback_and_a_retyped_field_is_not_trusted_as_identity(): void
    {
        $optionalEmail = [$this->field('email', 'email', 'Email', ['required' => false])];
        $identity = DemoFormSchema::identity($optionalEmail, ['email' => 'form@example.test', 'attendee_email' => 'other@example.test', 'attendee_first_name' => 'Kay']);
        $this->assertSame('form@example.test', $identity['email']);
        $this->assertSame('Kay', $identity['first_name']);
        $this->assertSame('other@example.test', DemoFormSchema::identity($optionalEmail, ['email' => '', 'attendee_email' => 'other@example.test'])['email']);

        // An optional email question keeps its fallback input; a required one drops it.
        $this->assertSame(['first_name' => true, 'email' => true], DemoFormSchema::attendeeNeeds($optionalEmail));
        $this->assertSame(['first_name' => true, 'email' => false], DemoFormSchema::attendeeNeeds([$this->field('email', 'email', 'Email', ['required' => true])]));

        // "email" retyped to a dropdown is an ordinary question now: it does not fill the Contact.
        $retyped = [$this->field('email', 'dropdown', 'Email?', ['options' => [['value' => 'yes', 'label' => 'Yes']]])];
        $this->assertSame([], DemoFormSchema::contactKeys($retyped));
        $this->assertSame('real@example.test', DemoFormSchema::identity($retyped, ['email' => 'yes', 'attendee_email' => 'real@example.test'])['email']);
    }

    // ---- history and compatibility -----------------------------------------------------------

    public function test_removing_every_question_keeps_earlier_answers_and_published_versions(): void
    {
        $this->book(['first_name' => 'Ada', 'email' => 'ada@example.test', 'business_name' => 'AE', 'industry' => 'home_services'])->assertRedirect();
        $before = DemoBookingResponse::query()->sole()->answers;
        $firstVersion = DemoFormVersion::query()->count();

        $this->publish([]);
        $this->publish([$this->field('note', 'short_text', 'Note')]);

        $this->assertSame($before, DemoBookingResponse::query()->sole()->fresh()->answers);
        $this->assertContains('Industry', array_column($before, 'label'));
        $this->assertSame($firstVersion + 2, DemoFormVersion::query()->count());
        $this->assertSame([], DemoFormVersion::query()->where('status', 'published')->orderBy('id')->first()->fields);
    }

    public function test_the_unrestricted_default_still_matches_the_design_until_the_first_publish(): void
    {
        $this->assertSame(['first_name', 'last_name', 'email', 'phone', 'business_name', 'industry', 'help_with', 'message'], array_column(DemoFormDefaults::fields(), 'key'));
        $this->assertSame(array_column(DemoFormDefaults::fields(), 'key'), array_column(app(DemoFormRepository::class)->published()['fields'], 'key'));
    }
}
