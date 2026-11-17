<?php

namespace Tests\Feature\Marketing;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Http\Controllers\Public\AgencySiteController;
use App\Library\AgencySite\AgencySiteBookingService;
use App\Library\AgencySite\AgencySiteManager;
use App\Library\AgencySite\OfficialDomainPaths;
use App\Models\Appointment;
use App\Models\AgencySite;
use App\Models\BookingType;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\AgencyBilling\Concerns\CreatesAgencySaasFixtures;
use Tests\TestCase;

/**
 * The consultation landing page as an editable White Label template available to EVERY Agency, with the real booking
 * calendar embedded, and the official-domain paths restricted to exactly two immutable workspace uids.
 */
class ConsultationLandingTemplateTest extends TestCase
{
    use CreatesAgencySaasFixtures;
    use RefreshDatabase;

    private const PHOTOBOOTH_UID = 'e14e25d2-42f2-4c24-b840-25efcdede356';

    private const DOGS_UID = 'cb403a56-3fb9-4694-b04e-a407495622db';

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();
        $this->bindFakeAgencyStripe();
    }

    /** @return array{0: Workspace, 1: \App\Models\Customer, 2: BookingType} an Agency with a working booking type and NO branding record */
    private function agency(string $name, ?string $forceUid = null): array
    {
        [$owner, $business, $agency] = $this->tenant(WorkspacePlanTier::Agency, $name . ' Business', $name);
        app(\App\Library\Business\BusinessLocationManager::class)->upsertPrimaryLocation($business, ['service_mode' => 'storefront', 'country_code' => 'US']);
        $type = app(AgencySiteBookingService::class)->create((int) $owner->user_id, $agency, ['name' => 'Intro call', 'duration_minutes' => 30, 'default_hours' => true]);

        if ($forceUid !== null) {
            Workspace::query()->whereKey($agency->id)->update(['uid' => $forceUid]);
            $agency = $agency->fresh();
        }

        return [$agency, $owner, $type];
    }

    private function consultationSite(Workspace $agency, $owner, BookingType $type, string $headline, bool $publish = true): AgencySite
    {
        $sites = app(AgencySiteManager::class);
        $sites->ensure((int) $owner->user_id, $agency);
        $sites->save((int) $owner->user_id, $agency, [
            'x.goal' => 'book_call', 'x.template' => 'consultation', 'x.booking_type_uid' => $type->public_booking_uuid,
            'consultation.title' => $headline, 'links.privacy' => 'https://example.test/privacy', 'links.terms' => 'https://example.test/terms',
        ]);

        return $publish ? $sites->publish((int) $owner->user_id, $agency) : $sites->find($agency);
    }

    private function config(string $html): array
    {
        $this->assertSame(1, preg_match('/data-role="public-scheduler"[^>]*data-config="([^"]*)"/', $html, $m), 'the page embeds the scheduler');

        return json_decode(html_entity_decode($m[1]), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_every_agency_can_choose_edit_preview_and_publish_the_template(): void
    {
        [$agency, $owner, $type] = $this->agency('Some Other Agency');
        $sites = app(AgencySiteManager::class);
        $site = $this->consultationSite($agency, $owner, $type, 'Edited headline for us', false);

        // The Photo-booth style two-column page, all copy editable through the ordinary editor save.
        $sites->save((int) $owner->user_id, $agency, [
            'consultation.lead' => 'Our own description.', 'consultation.steps' => [['One', 'First'], ['Two', 'Second'], ['Three', 'Third'], ['Four', 'Fourth']],
            'consultation.eyebrow' => 'Talk to us', 'consultation.calendar_title' => 'Choose a slot',
        ]);
        $this->authenticateAs($owner);
        $editorPage = $this->get(route('customer.workspaces.agency.white-label.show', ['workspaceUid' => $agency->uid, 'tab' => 'landing']))->assertOk()->getContent();
        $this->assertStringContainsString('Book a Call Landing Page', $editorPage, 'the template is offered in the White Label editor');
        $this->assertStringContainsString('consultation.title', $editorPage);
        $preview = $this->get(route('customer.workspaces.agency.site.preview', ['workspaceUid' => $agency->uid, 'page' => 'home']))->assertOk()->getContent();
        foreach (['Edited headline for us', 'Our own description.', 'Talk to us', 'Choose a slot', 'Fourth'] as $text) {
            $this->assertStringContainsString($text, $preview);
        }
        $this->assertStringContainsString('data-role="public-scheduler"', $preview);

        // No personal-information form before a time is chosen, and no organizer / meeting-summary sidebar.
        $this->assertStringNotContainsString('Tell us a little about you', $preview);
        $this->assertStringNotContainsString('mgd-form', $preview);
        $this->assertStringNotContainsString('data-role="booking-info"', $preview);
        $this->assertStringContainsString('<b>30 minutes</b>', $preview);

        // The editor presents the template's fields and choice.
        $editor = app(\App\Library\AgencySite\SiteEditorPresenter::class)->present($agency, $sites->find($agency));
        $this->assertArrayHasKey('consultation', $editor['groups']);
        $this->assertSame('consultation', $editor['template']);

        // Draft edits are invisible on the published page until publishing.
        $sites->publish((int) $owner->user_id, $agency);
        $sites->save((int) $owner->user_id, $agency, ['consultation.title' => 'NEXT DRAFT headline']);
        $live = $this->get(route('agency.site.home', $site->public_id))->assertOk()->getContent();
        $this->assertStringContainsString('Edited headline for us', $live);
        $this->assertStringNotContainsString('NEXT DRAFT headline', $live);
        $sites->publish((int) $owner->user_id, $agency);
        $this->get(route('agency.site.home', $site->public_id))->assertSee('NEXT DRAFT headline');
    }

    public function test_the_calendar_offers_real_availability_and_completes_a_booking(): void
    {
        [$agency, $owner, $type] = $this->agency('Booking Agency');
        $site = $this->consultationSite($agency, $owner, $type, 'Book here');
        $html = $this->get(route('agency.site.home', $site->public_id))->assertOk()->getContent();
        $cfg = $this->config($html);

        $this->assertSame(route('public.booking.dates', [$type->public_booking_uuid], false), $cfg['datesUrl']);
        $this->assertSame(30, $cfg['durationMinutes']);

        // Real available dates from the booking engine (not arithmetic in the page), then real times for one of them.
        $tz = $cfg['businessTimezone'];
        $date = null;
        foreach ([now($tz)->format('Y-m'), now($tz)->addMonth()->format('Y-m')] as $month) {
            $dates = $this->getJson($cfg['datesUrl'] . '?month=' . $month . '&tz=' . $tz)->assertOk()->json('available');
            if ($dates !== []) {
                $date = array_values((array) $dates)[0];
                $date = is_array($date) ? ($date['date'] ?? array_key_first($dates)) : $date;
                break;
            }
        }
        $this->assertNotNull($date, 'the engine offers at least one available date');
        $slots = $this->getJson($cfg['slotsUrl'] . '?date=' . $date . '&tz=' . $tz)->assertOk()->json('slots');
        $this->assertNotEmpty($slots, 'the engine offers times on that date');

        // The booking completes through the existing public booking write: a real Appointment is created.
        $before = Appointment::query()->count();
        $this->postJson($cfg['storeUrl'], [
            'date' => $date, 'time' => $slots[0]['time'], 'first_name' => 'Test', 'last_name' => 'Visitor',
            'email' => 'visitor@example.test', 'phone' => '5551230000', 'visitor_timezone' => $tz,
        ], ['X-Requested-With' => 'XMLHttpRequest'])->assertSuccessful();
        $this->assertSame($before + 1, Appointment::query()->count());

        // The same slot cannot be booked twice.
        $this->postJson($cfg['storeUrl'], [
            'date' => $date, 'time' => $slots[0]['time'], 'first_name' => 'Second', 'last_name' => 'Visitor',
            'email' => 'second@example.test', 'phone' => '5551230001', 'visitor_timezone' => $tz,
        ], ['X-Requested-With' => 'XMLHttpRequest'])->assertStatus(409);
    }

    public function test_an_unbookable_calendar_never_renders_a_fake_calendar(): void
    {
        [$agency, $owner, $type] = $this->agency('Dead Calendar Agency');
        $site = $this->consultationSite($agency, $owner, $type, 'Book here');
        app(\App\Library\Calendar\BookingTypeManager::class)->setActive($type, false);

        $html = $this->get(route('agency.site.home', $site->public_id))->getContent();
        $this->assertStringNotContainsString('data-role="public-scheduler"', $html);
    }

    public function test_only_the_two_authorized_workspaces_serve_the_official_paths(): void
    {
        [$a, $ownerA, $typeA] = $this->agency('Motion Grove Photo Booth', self::PHOTOBOOTH_UID);
        [$b, $ownerB, $typeB] = $this->agency('MotionGrove', self::DOGS_UID);
        [$c, $ownerC, $typeC] = $this->agency('Third Agency');

        $siteA = $this->consultationSite($a, $ownerA, $typeA, 'HEADLINE-A');
        $siteB = $this->consultationSite($b, $ownerB, $typeB, 'HEADLINE-B');
        $siteC = $this->consultationSite($c, $ownerC, $typeC, 'HEADLINE-C');

        $this->assertSame(['photobooth' => self::PHOTOBOOTH_UID, 'dogs' => self::DOGS_UID], config('marketing.landing.official_paths'));
        $this->assertSame('photobooth', OfficialDomainPaths::pathFor($a));
        $this->assertSame('dogs', OfficialDomainPaths::pathFor($b));
        $this->assertNull(OfficialDomainPaths::pathFor($c));

        // Each alias serves its own account's published site, with its own calendar, and nothing of the others.
        $pb = $this->get('/photobooth?site=' . $siteC->public_id)->assertOk()->getContent();
        $dg = $this->get('/dogs?site=' . $siteC->public_id)->assertOk()->getContent();
        $this->assertStringContainsString('HEADLINE-A', $pb);
        $this->assertStringContainsString('HEADLINE-B', $dg);
        foreach ([$pb, $dg] as $html) {
            $this->assertStringNotContainsString('HEADLINE-C', $html);
        }
        $this->assertSame(route('public.booking.dates', [$typeA->public_booking_uuid], false), $this->config($pb)['datesUrl']);
        $this->assertSame(route('public.booking.dates', [$typeB->public_booking_uuid], false), $this->config($dg)['datesUrl']);
        $this->assertStringNotContainsString($typeB->public_booking_uuid, $pb);
        $this->assertStringNotContainsString($typeA->public_booking_uuid, $dg);

        // Every other Agency keeps its ordinary hosted address.
        $this->get(route('agency.site.home', $siteC->public_id))->assertOk()->assertSee('HEADLINE-C');

        // Defence in depth: even if the controller is asked to serve another Agency's site on an official path, it is a 404.
        foreach (['photobooth', 'dogs'] as $path) {
            try {
                app(AgencySiteController::class)->landing($siteC->public_id, 'home', $path);
                $this->fail('another Agency must never be served on /' . $path);
            } catch (HttpException $e) {
                $this->assertSame(404, $e->getStatusCode());
            }
        }
        // ...and the two accounts cannot swap paths.
        try {
            app(AgencySiteController::class)->landing($siteA->public_id, 'home', 'dogs');
            $this->fail('Account A must not be served on /dogs');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        // Unknown root-level paths do not exist for anyone.
        $this->assertFalse(OfficialDomainPaths::permits('anything-else', self::PHOTOBOOTH_UID));
        $this->assertFalse(OfficialDomainPaths::permits('photobooth', (string) $c->uid));
        $this->assertFalse(OfficialDomainPaths::permits('photobooth', null));
    }

    public function test_crafted_settings_cannot_claim_or_create_an_official_path(): void
    {
        [$c, $ownerC, $typeC] = $this->agency('Third Agency');
        $this->consultationSite($c, $ownerC, $typeC, 'HEADLINE-C');
        $sites = app(AgencySiteManager::class);

        foreach (['x.official_path' => 'photobooth', 'x.path' => 'dogs', 'x.slug' => 'my-own', 'x.domain' => 'getmotiongrove.com', 'official_path' => 'dogs'] as $key => $value) {
            try {
                $sites->save((int) $ownerC->user_id, $c, [$key => $value]);
                $this->fail("{$key} must be refused");
            } catch (ValidationException) {
                $this->assertArrayNotHasKey($key, AgencySiteManager::values($sites->find($c)));
            }
        }

        // Through the editor's own HTTP endpoint too.
        $this->authenticateAs($ownerC);
        $this->postJson(route('customer.workspaces.agency.site.save', ['workspaceUid' => $c->uid]), ['answers' => ['x.official_path' => 'photobooth'], 'step' => 1])->assertStatus(422);

        // The route set contains no catch-all that could answer an arbitrary root path.
        $this->get('/my-own-path')->assertNotFound();
        $this->get('/photobooth/anything-else')->assertNotFound();
    }

    public function test_the_official_accounts_keep_independent_revisions_and_the_old_page_stays_until_republished(): void
    {
        [$a, $ownerA, $typeA] = $this->agency('Motion Grove Photo Booth', self::PHOTOBOOTH_UID);
        [$b, $ownerB, $typeB] = $this->agency('MotionGrove', self::DOGS_UID);
        $this->consultationSite($a, $ownerA, $typeA, 'A-FIRST');
        $this->consultationSite($b, $ownerB, $typeB, 'B-FIRST');
        $sites = app(AgencySiteManager::class);

        $sites->save((int) $ownerA->user_id, $a, ['consultation.title' => 'A-DRAFT']);
        $this->get('/photobooth')->assertSee('A-FIRST')->assertDontSee('A-DRAFT');
        $this->get('/dogs')->assertSee('B-FIRST');

        $sites->publish((int) $ownerA->user_id, $a);
        $this->get('/photobooth')->assertSee('A-DRAFT')->assertDontSee('A-FIRST');
        $this->get('/dogs')->assertSee('B-FIRST')->assertDontSee('A-DRAFT');
        $this->assertSame(2, AgencySite::query()->count());
    }

    public function test_book_a_call_is_a_first_class_option_at_the_top_of_the_editor_for_every_agency(): void
    {
        [$agency, $owner, $type] = $this->agency('Ordinary Agency');
        $this->authenticateAs($owner);
        $url = route('customer.workspaces.agency.white-label.show', ['workspaceUid' => $agency->uid, 'tab' => 'landing']);

        $html = $this->get($url)->assertOk()->getContent();

        // Both options are offered immediately, BEFORE the 10-step wizard navigation.
        $chooser = strpos($html, 'data-role="template-chooser"');
        $this->assertNotFalse($chooser);
        $this->assertLessThan(strpos($html, 'data-role="step-nav"'), $chooser, 'the chooser is outside and above the wizard');
        $this->assertStringContainsString('data-template-option="home"', $html);
        $this->assertStringContainsString('data-template-option="consultation"', $html);
        $this->assertStringContainsString('Book a Call Landing Page', $html);
        $this->assertStringContainsString('Full marketing website', $html);
        $this->assertStringContainsString('data-mode="site"', $html, 'the existing website stays the default');

        // The Book a Call editor has its own content, branding and calendar panels, plus draft, preview and publish.
        foreach (['data-role="callpage-content"', 'data-role="callpage-brand"', 'consultation.title', 'consultation.steps', 'data-booking-option' , 'data-role="save-draft"', 'data-role="publish"', 'id="wl-preview"'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }

        // Choosing it saves through the ordinary editor endpoint and the editor reopens in Book a Call mode.
        $this->postJson(route('customer.workspaces.agency.site.save', ['workspaceUid' => $agency->uid]), ['answers' => ['x.template' => 'consultation', 'x.booking_type_uid' => $type->public_booking_uuid], 'step' => 1])->assertOk();
        $this->assertStringContainsString('data-mode="callpage"', $this->get($url)->assertOk()->getContent());

        // Bad values are refused server-side.
        $this->postJson(route('customer.workspaces.agency.site.save', ['workspaceUid' => $agency->uid]), ['answers' => ['x.template' => 'something-else'], 'step' => 1])->assertStatus(422);
    }

    public function test_switching_type_is_a_draft_change_that_never_alters_the_published_page_and_enforces_call_only(): void
    {
        [$agency, $owner, $type] = $this->agency('Switching Agency');
        $sites = app(AgencySiteManager::class);
        $publish = function () use ($sites, $owner, $agency) {
            try { return $sites->publish((int) $owner->user_id, $agency); } catch (\App\Exceptions\AgencySite\SitePublishBlockedException $e) { $this->fail('Publish blocked: ' . json_encode(array_column($e->blockers, 'detail', 'key'))); }
        };
        $sites->ensure((int) $owner->user_id, $agency);
        $sites->save((int) $owner->user_id, $agency, ['x.goal' => 'book_call', 'x.booking_type_uid' => $type->public_booking_uuid, 'links.privacy' => 'https://example.test/p', 'links.terms' => 'https://example.test/t']);
        try { $site = $publish(); } catch (\App\Exceptions\AgencySite\SitePublishBlockedException $e) { $this->fail(json_encode($e->blockers ?? $e->getMessage())); }
        $before = $this->get(route('agency.site.home', $site->public_id))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-role="public-scheduler"', $before);

        // Switching to Book a Call in the draft leaves the live page byte-identical until republished.
        $sites->save((int) $owner->user_id, $agency, ['x.template' => 'consultation']);
        $this->assertSame($before, $this->get(route('agency.site.home', $site->public_id))->getContent());
        $this->assertTrue($sites->hasUnpublishedChanges($sites->find($agency)->fresh()));

        $publish();
        $this->get(route('agency.site.home', $site->public_id))->assertSee('data-role="public-scheduler"', false);

        // Call-only is enforced for the Book a Call page even when the saved goal sells plans, and the goal is not rewritten.
        $sites->save((int) $owner->user_id, $agency, ['x.goal' => 'both']);
        $publish();
        $html = $this->get(route('agency.site.home', $site->public_id))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/Get started|\bplans?\b|\$\d/i', strip_tags((string) preg_replace('/<script.*?<\/script>|<style.*?<\/style>/s', '', $html)));
        $this->assertSame('both', AgencySiteManager::values($sites->find($agency))['x.goal']);

        // Switching back restores the full website without losing the page copy.
        // (A full website that sells plans needs a sellable plan to publish, so restore the call-only goal first.)
        $sites->save((int) $owner->user_id, $agency, ['x.template' => 'home', 'x.goal' => 'book_call']);
        $publish();
        $this->get(route('agency.site.home', $site->public_id))->assertDontSee('data-role="public-scheduler"', false);
    }
    public function test_consultation_displays_only_approved_agency_testimonials_directly_after_calendar(): void
    {
        [$agency, $owner, $type] = $this->agency('Agency Testimonials');
        $sites = app(AgencySiteManager::class);
        $this->consultationSite($agency, $owner, $type, 'Free Consultation and Growth Plan', false);

        $sites->save((int) $owner->user_id, $agency, [
            'consultation.testimonials_title' => 'What clients say',
            'x.testimonials' => [
                ['customer_name' => 'Verified Customer', 'business_name' => 'Example Studio', 'quote' => 'We appreciated the consultation.', 'approved' => true, 'visible' => true],
                ['customer_name' => 'Not Approved', 'quote' => 'Do not publish this.', 'approved' => false, 'visible' => true],
            ],
        ]);

        $this->authenticateAs($owner);
        $preview = $this->get(route('customer.workspaces.agency.site.preview', [
            'workspaceUid' => $agency->uid, 'page' => 'home',
        ]))->assertOk()->getContent();

        $hero = strpos($preview, 'data-role="consultation"');
        $testimonials = strpos($preview, 'data-role="consultation-testimonials"');
        $this->assertNotFalse($hero);
        $this->assertNotFalse($testimonials);
        $this->assertGreaterThan($hero, $testimonials, 'Testimonials appear after the calendar section.');
        $this->assertStringContainsString('What clients say', $preview);
        $this->assertStringContainsString('We appreciated the consultation.', $preview);
        $this->assertStringContainsString('Verified Customer', $preview);
        $this->assertStringNotContainsString('Do not publish this.', $preview);

        $editor = $this->get(route('customer.workspaces.agency.white-label.show', [
            'workspaceUid' => $agency->uid, 'tab' => 'landing',
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('consultation.testimonials_title', $editor);
        $this->assertStringContainsString('data-role="callpage-testimonials-slot"', $editor);
        $this->assertSame(1, substr_count($editor, 'data-role="shared-testimonials-editor"'), 'Only one shared testimonial editor.');
        $this->assertSame(1, substr_count($editor, 'data-testimonials'), 'No duplicate testimonial forms.');
    }

    public function test_consultation_hides_testimonial_section_until_the_agency_approves_a_real_story(): void
    {
        [$agency, $owner, $type] = $this->agency('Agency No Testimonials');
        $sites = app(AgencySiteManager::class);
        $this->consultationSite($agency, $owner, $type, 'Free Consultation and Growth Plan', false);
        $sites->save((int) $owner->user_id, $agency, [
            'x.testimonials' => [
                ['customer_name' => 'Private Customer', 'quote' => 'Not permitted.', 'approved' => false, 'visible' => true],
            ],
        ]);

        $this->authenticateAs($owner);
        $this->get(route('customer.workspaces.agency.site.preview', [
            'workspaceUid' => $agency->uid, 'page' => 'home',
        ]))->assertOk()->assertDontSee('data-role="consultation-testimonials"', false);
    }

}
