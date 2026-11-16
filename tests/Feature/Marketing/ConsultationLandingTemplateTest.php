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
        $this->assertStringContainsString('Consultation landing page', $editorPage, 'the template is offered in the White Label editor');
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
        $this->assertContains('x.template', array_column($editor['groups']['setup']['fields'], 'path'));

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
}
