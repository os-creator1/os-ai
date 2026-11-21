<?php

namespace Tests\Feature\Website\Acceptance;

use App\Enums\Website\WebsiteDomainStatus;
use App\Library\Calendar\BookingTypeManager;
use App\Library\Website\Setup\ConsultationBookingSetup;
use App\Library\Website\Setup\QuestionnaireDefinitionValidator;
use App\Models\Appointment;
use App\Models\BookingType;
use App\Models\Contacts;
use App\Models\StaffAvailabilityRule;
use App\Models\Website;
use Database\Seeders\TutoringWebsiteSetupQuestionnaireSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesGuidedWizard;
use Tests\Feature\Website\Concerns\DrivesTutoringSetup;
use Tests\Feature\Website\Concerns\FakesGuidedWebsiteAi;
use Tests\TestCase;

/**
 * Slice 9 — the Tutoring owner configures the consultation booking type the blueprint installed (inactive, nobody assigned)
 * during the guided setup, and confirms the service areas that earn a page. Over the owner's real HTTP routes with the fake AI:
 * setup -> generate -> publish -> a visitor books a consultation on the published site; area pages exist only for confirmed
 * areas; and a missing or incomplete booking configuration falls back to the student inquiry form. Structure and behaviour
 * only; the fake AI proves no copy quality.
 */
class TutoringBookingAndAreasAcceptanceTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use DrivesGuidedWizard;
    use DrivesTutoringSetup;
    use FakesGuidedWebsiteAi;
    use RefreshDatabase;

    private const DOMAIN = 'petal-tutoring.test';

    private const TEMPLATES = ['tutoring_modern', 'tutoring_editorial', 'tutoring_luxury', 'tutoring_conversion'];

    /** The owner's answers for the booking screens. */
    private function booking(array $answers = []): array
    {
        $answers += ['enabled' => '1', 'days' => ['mon', 'wed'], 'hours' => 'after_school', 'duration' => 'm30', 'notice' => 'h4'];

        // The four follow-up questions share one screen, so they are submitted together, as the browser does.
        return [
            'consultation_booking' => fn () => $this->continueStep('consultation_booking', ['value' => $answers['enabled']]),
            'consultation_days' => fn () => $this->group([
                'consultation_days' => $answers['days'], 'consultation_hours' => $answers['hours'],
                'consultation_duration' => $answers['duration'], 'consultation_notice' => $answers['notice'],
            ]),
        ];
    }

    /** Hattiesburg is confirmed (a place the owner really teaches); Laurel is just a name; Petal is the base city. */
    private function areas(): array
    {
        return ['service_area_cities' => fn () => $this->continueStep('service_area_cities', [
            'value' => ['Petal, MS', 'Hattiesburg, MS', 'Laurel, MS'],
            'area_details' => ['', json_encode(['venues' => 'The Hattiesburg Public Library', 'logistics' => 'Lessons are held at the library or online; weekday evenings work best.', 'photos' => []]), ''],
        ])];
    }

    private function bookingType(): BookingType
    {
        return app(ConsultationBookingSetup::class)->installedType($this->business->fresh()) ?? $this->fail('the blueprint installed a consultation booking type');
    }

    private function publish(): array
    {
        $urls = ['publish' => $this->owner('publish'), 'studio' => $this->owner('studio.show'), 'allow' => $this->owner('pages.allowIndexing'), 'preview' => $this->owner('preview')];
        $website = Website::where('business_id', $this->business->id)->firstOrFail();
        $this->post($urls['allow'])->assertRedirect();
        $website->domains()->create(['domain' => self::DOMAIN, 'is_primary' => true, 'status' => WebsiteDomainStatus::Active, 'verification_token' => 'x', 'verified_at' => now(), 'activated_at' => now()]);
        $this->publishAsOwner($urls);

        return [$website->fresh(), $urls];
    }

    /** @return array<string, string> path => html of the published site */
    private function crawl(\App\Models\Website $website): array
    {
        $pages = [];
        foreach ($website->fresh()->publishedRevision->snapshot['pages'] as $page) {
            $path = ! empty($page['is_home']) ? '/' : '/' . $page['slug'];
            $pages[$path] = $this->get($this->site($path))->assertOk()->getContent();
        }

        return $pages;
    }

    public function test_the_owner_configures_booking_and_confirmed_areas_and_a_visitor_books_on_the_published_site(): void
    {
        $this->tutoringTenant();
        $type = $this->bookingType();
        $this->assertFalse($type->isActive(), 'the blueprint installs the booking type inactive, with nobody assigned');
        $this->assertSame(0, $type->staff()->count());

        $this->completeTutoringSetup($this->booking() + $this->areas());
        $website = $this->generate();

        $this->generateResponse->assertSessionHas('message', fn ($message) => str_contains($message, 'Online consultation booking is on'));

        // ---- setup configured the SAME booking type, as the owner answered; nothing else was created
        $this->assertSame(1, BookingType::query()->count());
        $type = $type->fresh();
        $this->assertTrue($type->isActive());
        $this->assertSame(30, (int) $type->duration_minutes);
        $this->assertSame(240, (int) $type->minimum_notice_minutes);
        $this->assertSame([(int) $this->customer->user_id], $type->staff()->pluck('users.id')->map(fn ($id) => (int) $id)->all(), 'the owner takes the consultations');
        $hours = StaffAvailabilityRule::query()->where('business_location_id', $type->business_location_id)->orderBy('day_of_week')->get();
        $this->assertSame([1, 3], $hours->pluck('day_of_week')->all(), 'Monday and Wednesday only');
        $this->assertSame(['15:30:00', '15:30:00'], $hours->pluck('start_time')->all());
        $this->assertTrue(app(BookingTypeManager::class)->publicBookingReadiness($type)['ready']);

        // ---- confirmed service areas: a page only for the area the owner confirmed, never invented
        $slugs = $website->pages()->pluck('slug')->filter()->all();
        $areaPages = array_values(array_filter($slugs, fn ($slug) => str_starts_with((string) $slug, 'serving-')));
        $this->assertSame(['serving-hattiesburg-ms'], $areaPages, 'only the confirmed area earns a page; Laurel (a bare name) and Petal (the base city) do not: ' . json_encode($slugs));
        $this->assertSame(count($slugs), count(array_unique($slugs)));
        $this->assertSame([], array_values(array_filter($slugs, fn ($slug) => str_contains((string) $slug, 'photo-booth'))));

        [$website, $urls] = $this->publish();
        $site = $this->crawl($website);

        // ---- the area page: its own title/description, self canonical, indexable, in the sitemap, linked from the site and onward
        $area = $site['/serving-hattiesburg-ms'];
        $this->assertStringContainsString('<link rel="canonical" href="https://' . self::DOMAIN . '/serving-hattiesburg-ms">', $area);
        $this->assertMatchesRegularExpression('#<meta name="robots" content="index#i', $area);
        $this->assertStringContainsString('BreadcrumbList', $area);
        $this->assertStringContainsString('The Hattiesburg Public Library', $area, 'the owner-confirmed place is stated');
        $this->assertStringContainsString('Lessons are held at the library or online', $area, 'the owner-confirmed detail is stated');
        $this->assertStringContainsString('Ready to get started in Hattiesburg, MS?', $area, 'a neutral closing line, not the Photo Booth "Planning an event" wording');
        $this->assertStringNotContainsString('Planning an event', $area);
        foreach (['Laurel', 'Petal'] as $notConfirmed) {
            $this->assertStringNotContainsString('Serving ' . $notConfirmed, $area);
        }
        $links = fn (string $html) => (function () use ($html) { preg_match_all('#href="(?:https://' . preg_quote(self::DOMAIN, '#') . ')?(/[^"\#?]*)"#', $html, $m); return array_values(array_unique($m[1])); })();
        $this->assertContains('/contact', $links($area), 'the area page leads to the contact page');
        $this->assertNotEmpty(array_filter($links($area), fn ($path) => str_starts_with($path, '/service-')), 'and to the services');
        $this->assertContains('/serving-hattiesburg-ms', $links($site['/']), 'the home page links to the confirmed area');
        $home = strip_tags($site['/']);
        $this->assertStringContainsString('Laurel', $home, 'a listed area is still named on the site');
        $this->assertNotContains('/serving-laurel-ms', $links($site['/']));
        $this->get($this->site('/serving-laurel-ms'))->assertNotFound();
        $titles = array_map(fn ($html) => preg_match('#<title>([^<]+)</title>#', $html, $m) ? $m[1] : '', $site);
        $this->assertSame(count($titles), count(array_unique($titles)), 'every page has its own title');
        $sitemap = $this->get($this->site('/sitemap.xml'))->assertOk()->getContent();
        $this->assertStringContainsString('https://' . self::DOMAIN . '/serving-hattiesburg-ms</loc>', $sitemap);
        $this->assertStringNotContainsString('serving-laurel', $sitemap);

        // ---- the booking button appears because booking is really configured; the student form stays on the contact page
        $app = rtrim((string) config('app.url'), '/');
        $bookingUrl = $app . '/book/' . $type->public_booking_uuid;
        foreach (['/', '/contact', '/serving-hattiesburg-ms'] as $path) {
            $this->assertStringContainsString('href="' . $bookingUrl . '"', $site[$path], "{$path}: the Book now button reaches the real booking page");
            $this->assertStringContainsString('Book now', $site[$path]);
        }
        $this->assertStringContainsString('data-deployment-uid="' . $this->deployment->uid . '"', $site['/contact'], 'the student inquiry form is still offered');

        // ---- a visitor books through the published booking page
        $page = $this->get($bookingUrl)->assertOk()->getContent();
        $this->assertStringContainsString($type->name, $page);
        $date = null;
        foreach ([now()->format('Y-m'), now()->addMonth()->format('Y-m')] as $month) {
            $available = $this->getJson($bookingUrl . '/dates?month=' . $month)->assertOk()->json('available');
            if ($available !== []) {
                $date = $available[0];
                break;
            }
        }
        $this->assertNotNull($date, 'the owner\'s Monday and Wednesday hours offer dates');
        $this->assertContains((int) \Carbon\Carbon::parse($date)->dayOfWeek, [1, 3], 'only the days the owner chose');
        $slots = $this->getJson($bookingUrl . '/slots?date=' . $date)->assertOk()->json('slots');
        $this->assertNotEmpty($slots);
        $time = is_array($slots[0]) ? ($slots[0]['time'] ?? $slots[0]['start'] ?? reset($slots[0])) : $slots[0];
        $this->assertGreaterThanOrEqual('15:30', $time, 'inside the owner\'s after-school window');

        $payload = ['date' => $date, 'time' => $time, 'first_name' => 'Jordan', 'last_name' => 'Parent', 'email' => 'jordan@example.test', 'phone' => '6015550177'];
        $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])->post($bookingUrl, $payload)->assertStatus(201)->assertJsonPath('status', 'confirmed');

        $appointment = Appointment::query()->where('booking_type_id', $type->id)->sole();
        $contact = Contacts::query()->where('business_id', $this->business->id)->findOrFail($appointment->contact_id);
        $this->assertSame(1, Contacts::query()->where('business_id', $this->business->id)->where('phone', $contact->phone)->count());
        $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])->post($bookingUrl, $payload)->assertStatus(409);
        $this->assertSame(1, Appointment::query()->where('booking_type_id', $type->id)->count(), 'the same time cannot be booked twice');

        // ---- evidence runs only: ACCEPTANCE_REPORT_DIR dumps every look's published pages for the browser check
        if ($dir = getenv('ACCEPTANCE_REPORT_DIR')) {
            $rebuild = $this->owner('rebuild');
            foreach (self::TEMPLATES as $template) {
                $this->post($rebuild, ['template_key' => $template, 'mode' => 'look_only', 'confirm_rebuild' => '1'])->assertRedirect();
                $this->publishAsOwner($urls);
                @mkdir($dir . '/' . $template, 0777, true);
                foreach (['index' => '/', 'area' => '/serving-hattiesburg-ms', 'contact' => '/contact'] as $name => $path) {
                    file_put_contents($dir . '/' . $template . '/' . $name . '.html', $this->get($this->site($path))->assertOk()->getContent());
                }
            }
        }
    }

    public function test_missing_or_incomplete_booking_configuration_falls_back_to_the_inquiry_form(): void
    {
        $this->tutoringTenant();
        $bookingUrlPart = '/book/';

        // 1. The owner never answers the booking questions: nothing is switched on, the form is the main button.
        $this->completeTutoringSetup();
        $website = $this->generate();
        $type = $this->bookingType();
        $this->assertFalse($type->fresh()->isActive());
        [$website] = $this->publish();
        $site = $this->crawl($website);
        foreach ($site as $path => $html) {
            $this->assertStringNotContainsString($bookingUrlPart, $html, "{$path}: no booking link when booking is not configured");
            $this->assertStringNotContainsString('Book now', $html);
        }
        $this->assertStringContainsString('data-deployment-uid="' . $this->deployment->uid . '"', $site['/contact']);
        $this->assertStringContainsString('href="https://' . self::DOMAIN . '/contact"', $site['/'], 'the main button leads to the contact page with the student form');
        $this->get($this->site('/book/' . $type->public_booking_uuid))->assertNotFound();
        $this->assertSame(0, Appointment::query()->count());
        $this->assertFalse(app(BookingTypeManager::class)->publicBookingReadiness($type->fresh())['ready']);
    }

    public function test_yes_without_any_day_leaves_booking_off_and_the_owner_is_told_after_generating(): void
    {
        $this->tutoringTenant();
        $this->completeTutoringSetup($this->booking(['days' => [], 'hours' => 'morning']));
        $website = $this->generate();

        $this->generateResponse->assertSessionHas('message', fn ($message) => str_contains($message, 'Choose the days') && str_contains($message, 'inquiry form stays the main button'));
        $type = $this->bookingType()->fresh();
        $this->assertFalse($type->isActive(), 'never switched on without bookable hours');
        $this->assertSame(0, StaffAvailabilityRule::query()->count());
        [$website] = $this->publish();
        $site = $this->crawl($website);
        $this->assertStringNotContainsString('/book/', implode('', $site));
        $this->assertStringContainsString('data-deployment-uid="' . $this->deployment->uid . '"', $site['/contact']);
    }

    public function test_declining_or_choosing_no_days_never_switches_booking_on_and_says_why(): void
    {
        $this->tutoringTenant();

        // "No" changes nothing (the follow-up questions are then hidden).
        $this->completeTutoringSetup(['consultation_booking' => fn () => $this->continueStep('consultation_booking', ['value' => '0'])]);
        $this->generate();
        $this->assertFalse($this->bookingType()->fresh()->isActive());
        $this->assertSame([], StaffAvailabilityRule::query()->get()->all(), 'no working hours were written');

        // "Yes" without any day cannot be bookable: the type stays off and the owner is told what to add.
        $setup = app(ConsultationBookingSetup::class);
        $outcome = $setup->apply($this->business->fresh(), ['enabled' => true, 'days' => [], 'hours' => 'morning', 'duration_minutes' => 'm30'], (int) $this->customer->user_id);
        $this->assertSame(ConsultationBookingSetup::STATE_NOT_READY, $outcome['state']);
        $this->assertStringContainsString('Choose the days', (string) $outcome['reason']);
        $this->assertStringContainsString('inquiry form stays the main button', (string) $outcome['reason']);
        $this->assertFalse($this->bookingType()->fresh()->isActive());

        // A business whose blueprint installed no booking type is told so, and nothing is created.
        \App\Models\BusinessBlueprintComponentInstallation::query()->where('business_id', $this->business->id)->where('component_type', 'booking_type')->delete();
        $before = BookingType::query()->count();
        $this->assertSame(ConsultationBookingSetup::STATE_NOT_INSTALLED, $setup->apply($this->business->fresh(), ['enabled' => true, 'days' => ['mon'], 'hours' => 'morning'], (int) $this->customer->user_id)['state']);
        $this->assertSame($before, BookingType::query()->count());
    }

    public function test_the_definition_validator_accepts_the_new_steps_and_refuses_malformed_booking_or_label_steps(): void
    {
        $validator = app(QuestionnaireDefinitionValidator::class);
        $steps = TutoringWebsiteSetupQuestionnaireSeeder::steps();
        $validator->validate($steps);   // the shipped definition is valid

        $bad = function (callable $mutate) use ($steps, $validator) {
            $copy = $steps;
            $index = array_search('consultation_days', array_column($copy, 'key'), true);
            $copy[$index] = $mutate($copy[$index]);
            try {
                $validator->validate($copy);
            } catch (DomainException $e) {
                return $e->getMessage();
            }

            return null;
        };

        $this->assertNotNull($bad(fn ($s) => ['target_field' => 'nonsense'] + $s), 'an unknown booking field is refused');
        $this->assertNotNull($bad(fn ($s) => ['input_type' => 'select'] + $s), 'a booking field only accepts its own input type');
        $this->assertNotNull($bad(fn ($s) => $s + ['area_labels' => ['venues_label' => 'x']]), 'area_labels belong only on an area_details list');
    }
}
