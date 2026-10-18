<?php

namespace Tests\Feature\GoogleAds\Attribution;

use App\Enums\GoogleAds\LeadAttributionSubjectType;
use App\Library\Forms\FormSubmissionService;
use App\Library\GoogleAds\Attribution\LeadAttributionRecorder;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use App\Models\FormDeployment;
use App\Models\FormSubmission;
use App\Models\LeadAttributionTouch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\Feature\GoogleAds\Attribution\Concerns\BuildsAttributionCookies;
use Tests\TestCase;

/** Surface 1 of 3: the public form (and the recorder's general rules, proven through it). */
class PublicFormAttributionTest extends TestCase
{
    use BuildsAttributionCookies;
    use CreatesFormsFixtures;
    use RefreshDatabase;

    private Business $business;

    private BusinessLocation $location;

    private FormDeployment $deployment;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->business] = $this->formsTenant();
        $this->location = $this->formsLocation($this->business);
        [, $this->deployment] = $this->liveForm($this->business, $this->location);
    }

    private function submit(array $cookies = [], array $headers = [], array $answers = [], ?FormDeployment $deployment = null)
    {
        $deployment ??= $this->deployment;

        return $this->withCookies($cookies)->withHeaders($headers)
            ->post(route('public.forms.submit', [$deployment->uid]), $this->submitInput($deployment, $answers));
    }

    public function test_a_tagged_submission_writes_a_first_row_for_the_server_resolved_business(): void
    {
        $this->submit($this->touchCookies(['gclid' => self::CLICK, 'utm_source' => 'google']))->assertRedirect();

        $submission = FormSubmission::query()->sole();
        $rows = $this->touchRows();

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('first', $row->touch_role);
        $this->assertSame('public_form', $row->entry_surface);
        $this->assertSame('form_submission', $row->subject_type);
        $this->assertSame((int) $submission->id, (int) $row->subject_id);
        $this->assertSame((int) $this->business->id, (int) $row->business_id);
        $this->assertSame((int) $this->location->id, (int) $row->business_location_id);
        $this->assertSame((int) $submission->contact_id, (int) $row->contact_id);
        $this->assertNotNull($row->contact_id);
        $this->assertSame(self::CLICK, $row->gclid);
        $this->assertSame('google', $row->utm_source);
        $this->assertSame('/sites/demo', $row->landing_page);
        $this->assertNotNull($row->captured_at);
        $this->assertNotNull($row->recorded_at);
    }

    public function test_a_different_last_touch_adds_a_last_row_and_a_same_one_does_not(): void
    {
        $this->submit($this->touchCookies(['gclid' => self::CLICK], ['utm_source' => 'newsletter']))->assertRedirect();
        $this->assertSame(['first', 'last'], array_map(fn ($r) => $r->touch_role, $this->touchRows()));
        $this->assertSame('newsletter', $this->touchRows()[1]->utm_source);

        DB::table('lead_attribution_touches')->delete();
        $this->submit($this->touchCookies(['gclid' => self::CLICK], ['gclid' => self::CLICK]), [], ['phone' => '+1 (415) 555-9999'])->assertRedirect();
        $this->assertSame(['first'], array_map(fn ($r) => $r->touch_role, $this->touchRows()));
    }

    public function test_only_a_last_cookie_is_recorded_as_the_first_touch(): void
    {
        $this->submit(['bos_at_last' => $this->touchJson(['utm_campaign' => 'solo'])])->assertRedirect();

        $rows = $this->touchRows();
        $this->assertCount(1, $rows);
        $this->assertSame('first', $rows[0]->touch_role);
        $this->assertSame('solo', $rows[0]->utm_campaign);
    }

    public function test_no_cookie_records_nothing(): void
    {
        $this->submit()->assertRedirect();

        $this->assertCount(0, $this->touchRows());
        $this->assertSame(1, FormSubmission::query()->count());
    }

    public function test_global_privacy_control_and_do_not_track_record_nothing_even_with_cookies(): void
    {
        $cookies = $this->touchCookies(['gclid' => self::CLICK]);

        $this->submit($cookies, ['Sec-GPC' => '1'])->assertRedirect();
        $this->submit($cookies, ['DNT' => '1'], ['phone' => '+1 (415) 555-0001'])->assertRedirect();

        $this->assertCount(0, $this->touchRows());
        $this->assertSame(2, FormSubmission::query()->count());
    }

    public function test_disabled_capture_records_nothing(): void
    {
        config(['google_ads.attribution.capture_enabled' => false]);

        $this->submit($this->touchCookies(['gclid' => self::CLICK]))->assertRedirect();

        $this->assertCount(0, $this->touchRows());
    }

    public function test_forged_garbage_and_oversized_cookies_are_ignored_without_failing_the_submission(): void
    {
        $forged = $this->withUnencryptedCookie('bos_at_first', $this->touchJson(['gclid' => self::CLICK]))
            ->withUnencryptedCookie('bos_at_last', 'garbage{{{')
            ->post(route('public.forms.submit', [$this->deployment->uid]), $this->submitInput($this->deployment));
        $forged->assertRedirect();
        $this->assertCount(0, $this->touchRows(), 'an unencrypted (forged) cookie never decrypts, so it is never read');

        $this->submit([
            'bos_at_first' => str_repeat('x', 6000),
            'bos_at_last' => '{"gclid":"x","t":"notanint"}',
        ], [], ['phone' => '+1 (415) 555-0002'])->assertRedirect();
        $this->submit([
            'bos_at_first' => '[]',
            'bos_at_last' => $this->touchJson(['gclid' => 'bad!'], '/x', time() + 99999),
        ], [], ['phone' => '+1 (415) 555-0003'])->assertRedirect();

        $this->assertCount(0, $this->touchRows());
        $this->assertSame(3, FormSubmission::query()->count());
    }

    public function test_a_validly_encrypted_but_hostile_cookie_is_re_sanitised_and_cannot_choose_the_business(): void
    {
        $other = $this->formsTenant(name: 'Other Studio');
        $otherBusiness = $other[1];

        $hostile = $this->touchJson([
            'gclid' => self::CLICK,
            'utm_source' => "x\x00<b>",
            'business_id' => (int) $otherBusiness->id,
            'business_location_id' => 999999,
            'contact_id' => 123456,
            'subject_id' => 1,
            'wbraid' => 'bad',
        ], '/path?secret=1#frag');

        $this->submit(['bos_at_first' => $hostile])->assertRedirect();

        $row = $this->touchRows()[0];
        $this->assertSame((int) $this->business->id, (int) $row->business_id);
        $this->assertSame((int) $this->location->id, (int) $row->business_location_id);
        $this->assertNotSame(123456, (int) $row->contact_id);
        $this->assertSame('x<b>', $row->utm_source);
        $this->assertNull($row->wbraid);
        $this->assertSame('/path', $row->landing_page);
        $this->assertSame(0, DB::table('lead_attribution_touches')->where('business_id', $otherBusiness->id)->count());
    }

    public function test_a_cookie_from_another_businesses_page_cannot_cross_businesses(): void
    {
        [, $otherBusiness] = $this->formsTenant(name: 'Other Studio');
        $otherLocation = $this->formsLocation($otherBusiness, 'Elsewhere');
        [, $otherDeployment] = $this->liveForm($otherBusiness, $otherLocation);

        // The visitor browsed Business B's page, then submitted Business A's form.
        $this->submit($this->touchCookies(['gclid' => self::CLICK], null, '/forms/'.$otherDeployment->uid))->assertRedirect();

        $this->assertSame((int) $this->business->id, (int) $this->touchRows()[0]->business_id);
        $this->assertSame(0, DB::table('lead_attribution_touches')->where('business_id', $otherBusiness->id)->count());
    }

    public function test_first_touch_is_preserved_across_a_second_visit_and_second_submission(): void
    {
        $cookies = $this->touchCookies(['gclid' => 'FirstClickIdAAAAAA1'], ['gclid' => 'FirstClickIdAAAAAA1']);
        $this->submit($cookies)->assertRedirect();
        $contactId = (int) FormSubmission::query()->sole()->contact_id;

        // A later visit tagged differently, then the same person submits again.
        $later = $this->touchCookies(['gclid' => 'FirstClickIdAAAAAA1'], ['utm_source' => 'email', 'utm_campaign' => 'later']);
        $this->submit($later, [], ['message' => 'Second note.'])->assertRedirect();

        $rows = DB::table('lead_attribution_touches')->where('contact_id', $contactId)->orderBy('id')->get();
        $this->assertSame(['first', 'first', 'last'], $rows->pluck('touch_role')->all());
        $this->assertSame(['FirstClickIdAAAAAA1', 'FirstClickIdAAAAAA1'], $rows->where('touch_role', 'first')->pluck('gclid')->values()->all());
        $this->assertSame('later', $rows->last()->utm_campaign);
    }

    public function test_re_recording_the_same_subject_is_idempotent(): void
    {
        $this->submit($this->touchCookies(['gclid' => self::CLICK], ['utm_source' => 'x']))->assertRedirect();
        $submission = FormSubmission::query()->sole();

        $request = \Illuminate\Http\Request::create('/', 'POST', [], $this->touchCookies(['gclid' => self::CLICK], ['utm_source' => 'x']));
        $written = app(LeadAttributionRecorder::class)->record(
            $this->business, $this->location->id, $submission->contact_id,
            LeadAttributionSubjectType::FormSubmission, $submission->id,
            \App\Enums\GoogleAds\LeadAttributionEntrySurface::PublicForm, $request,
        );

        $this->assertSame(0, $written);
        $this->assertCount(2, $this->touchRows());
    }

    public function test_replaying_the_same_operation_token_does_not_duplicate_rows(): void
    {
        $input = $this->submitInput($this->deployment);
        $cookies = $this->touchCookies(['gclid' => self::CLICK]);

        $this->withCookies($cookies)->post(route('public.forms.submit', [$this->deployment->uid]), $input)->assertRedirect();
        $this->withCookies($cookies)->post(route('public.forms.submit', [$this->deployment->uid]), $input)->assertRedirect();

        $this->assertSame(1, FormSubmission::query()->count());
        $this->assertCount(1, $this->touchRows());
    }

    public function test_a_recorder_failure_never_fails_the_submission_and_logs_no_payload(): void
    {
        Log::spy();
        Event::listen('eloquent.creating: '.LeadAttributionTouch::class, function (): void {
            throw new \RuntimeException('boom '.self::CLICK);
        });

        try {
            $this->submit($this->touchCookies(['gclid' => self::CLICK]))->assertRedirect();
        } finally {
            Event::forget('eloquent.creating: '.LeadAttributionTouch::class);
        }

        $this->assertSame(1, FormSubmission::query()->count());
        $this->assertCount(0, $this->touchRows());
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            return ! str_contains(json_encode($context), self::CLICK) && ! str_contains($message, self::CLICK)
                && array_keys($context) === ['exception', 'subject_type', 'subject_id'];
        });
    }

    public function test_a_filled_honeypot_records_nothing(): void
    {
        $this->submit($this->touchCookies(['gclid' => self::CLICK]), [], [FormSubmissionService::HONEYPOT_FIELD => 'gotcha'])->assertRedirect();

        $this->assertSame(0, FormSubmission::query()->count());
        $this->assertCount(0, $this->touchRows());
    }

    public function test_a_rejected_submission_records_nothing(): void
    {
        $this->submit($this->touchCookies(['gclid' => self::CLICK]), [], ['phone' => ''])->assertSessionHasErrors();

        $this->assertCount(0, $this->touchRows());
    }

    public function test_link_contact_fills_only_a_null_contact_within_the_same_business(): void
    {
        $recorder = app(LeadAttributionRecorder::class);
        $this->submit()->assertRedirect(); // untagged: no rows yet
        $submission = FormSubmission::query()->sole();

        $request = \Illuminate\Http\Request::create('/', 'POST', [], $this->touchCookies(['gclid' => self::CLICK]));
        $recorder->record(
            $this->business, $this->location->id, null, LeadAttributionSubjectType::FormSubmission, $submission->id,
            \App\Enums\GoogleAds\LeadAttributionEntrySurface::PublicForm, $request,
        );
        $this->assertNull($this->touchRows()[0]->contact_id);

        $mine = $this->contactFor($this->business, '+14155550101');
        [, $otherBusiness] = $this->formsTenant(name: 'Other Studio');
        $theirs = $this->contactFor($otherBusiness, '+14155550102');

        $this->assertSame(0, $recorder->linkContact($this->business, LeadAttributionSubjectType::FormSubmission, $submission->id, $theirs->id), 'a contact of another Business is refused');
        $this->assertSame(0, $recorder->linkContact($otherBusiness, LeadAttributionSubjectType::FormSubmission, $submission->id, $theirs->id), 'another Business cannot touch this row');
        $this->assertSame(1, $recorder->linkContact($this->business, LeadAttributionSubjectType::FormSubmission, $submission->id, $mine->id));
        $this->assertSame((int) $mine->id, (int) $this->touchRows()[0]->contact_id);

        // Already linked: never re-pointed.
        $another = $this->contactFor($this->business, '+14155550103');
        $this->assertSame(0, $recorder->linkContact($this->business, LeadAttributionSubjectType::FormSubmission, $submission->id, $another->id));
        $this->assertSame((int) $mine->id, (int) $this->touchRows()[0]->contact_id);
    }

    public function test_the_model_refuses_update_and_delete(): void
    {
        $this->submit($this->touchCookies(['gclid' => self::CLICK]))->assertRedirect();
        $touch = LeadAttributionTouch::query()->firstOrFail();

        $touch->gclid = 'Rewritten000000000';
        try {
            $touch->save();
            $this->fail('update must be refused');
        } catch (\LogicException) {
            $this->assertSame(self::CLICK, DB::table('lead_attribution_touches')->value('gclid'));
        }

        $this->expectException(\LogicException::class);
        $touch->delete();
    }

    public function test_attribution_never_becomes_contact_custom_fields(): void
    {
        $before = DB::table('contacts_custom_field')->count();

        $this->submit($this->touchCookies(['gclid' => self::CLICK, 'utm_source' => 'google']))->assertRedirect();

        $contact = Contacts::query()->findOrFail(FormSubmission::query()->sole()->contact_id);
        $values = DB::table('contacts_custom_field')->where('contact_id', $contact->id)->pluck('value')->implode('|');
        $this->assertStringNotContainsString(self::CLICK, $values);
        $this->assertStringNotContainsString('google', $values);
        $this->assertStringNotContainsString('gclid', json_encode(FormSubmission::query()->sole()->values));
        $this->assertGreaterThanOrEqual($before, DB::table('contacts_custom_field')->count());
    }

    private function contactFor(Business $business, string $phone): Contacts
    {
        $location = BusinessLocation::query()->where('business_id', $business->id)->first() ?? $this->formsLocation($business);
        [$contact] = app(\App\Repositories\Eloquent\EloquentContactsRepository::class)->findOrCreateForForm($location, $phone, []);

        return $contact;
    }
}
