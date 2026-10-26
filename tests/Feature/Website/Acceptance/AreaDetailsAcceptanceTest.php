<?php

namespace Tests\Feature\Website\Acceptance;

use App\Enums\Website\WebsiteDomainStatus;
use App\Library\Website\LocalAreaUsefulness;
use App\Library\Website\Setup\ServiceAreaDetails;
use App\Library\Website\Setup\WebsitePlanSummary;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Library\Website\WebsitePageStrategy;
use App\Library\Website\WebsitePublisher;
use App\Library\Website\WebsiteSearchVisibility;
use App\Models\Website;
use App\Models\WebsiteRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesGuidedWizard;
use Tests\TestCase;

/**
 * Owner-confirmed local details, collected inside the service-area step, drive which cities earn a page and what the page
 * says. A disposable Spokane photo booth: Spokane is the homepage's market; Spokane Valley is a bare list entry; Liberty Lake
 * confirmed how serving it works; Coeur d'Alene confirmed a venue and an event photo; Cheney only has pasted example text
 * and a photo that belongs to another customer, neither of which counts.
 */
class AreaDetailsAcceptanceTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use DrivesGuidedWizard;
    use RefreshDatabase;

    private const DOMAIN = 'spokane-details.test';

    private const LOGISTICS = 'Liberty Lake is about 25 minutes from our Spokane studio; we load in 90 minutes early and there is no travel fee.';

    private const VENUE = 'The Lake Coeur d’Alene Resort';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGuidedWizard();

        $ai = \Mockery::mock(WebsiteAiGenerationClient::class);
        $ai->shouldNotReceive('complete');
        $ai->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $ai->shouldReceive('lastRefusalReason')->andReturn(null);
        $this->app->instance(WebsiteAiGenerationClient::class, $ai);
    }

    /** @return array<int, string> the owner's gallery asset uids */
    private function startSpokane(): array
    {
        $this->tenant(['name' => 'Glow Booths'], ['city' => 'Spokane', 'region' => 'WA']);

        return $this->uploadGalleryPhotos(4);
    }

    private function areaPayload(array $rows): array
    {
        return [
            'value' => array_column($rows, 0),
            'area_details' => array_map(fn (array $row) => isset($row[1]) ? json_encode($row[1]) : '', $rows),
        ];
    }

    private function saveAreas(array $rows)
    {
        return $this->continueStep('service_area_cities', $this->areaPayload($rows));
    }

    private function generateAndPublish(array $rows): Website
    {
        $this->completeGuidedSetup([
            'base_location' => fn () => $this->continueStep('base_location', ['value' => ['city' => 'Spokane', 'region' => 'WA', 'country_code' => 'US']]),
            'service_area_cities' => fn () => $this->saveAreas($rows),
        ]);
        Website::where('business_id', $this->business->id)->update(['template_key' => 'photo_booth_modern']);

        $this->post($this->url('generate'), ['answers_revision' => $this->wizardSession()->answers_revision])->assertRedirect();
        $website = Website::where('business_id', $this->business->id)->firstOrFail();

        app(WebsiteSearchVisibility::class)->release($website);
        app(WebsitePublisher::class)->publish($website->fresh(), $this->platformAdminId());
        $website->domains()->create(['domain' => self::DOMAIN, 'is_primary' => true, 'status' => WebsiteDomainStatus::Active, 'verification_token' => 't', 'verified_at' => now(), 'activated_at' => now()]);

        return $website->fresh();
    }

    private function html(string $path): string
    {
        return $this->get('http://' . self::DOMAIN . $path)->assertOk()->getContent();
    }

    /** @return array<int, array{0: string, 1?: array<string, mixed>}> */
    private function spokaneRows(array $photos, array $foreignPhoto = []): array
    {
        return [
            ['Spokane, WA', ['logistics' => 'Our studio is here, so there is nothing to travel for.']],
            ['Spokane Valley, WA'],
            ['Liberty Lake, WA', ['logistics' => self::LOGISTICS]],
            ["Coeur d'Alene, ID", ['venues' => self::VENUE . "\nThe Hagadone Event Center", 'photos' => [$photos[1]]]],
            ['Cheney, WA', ['venues' => "e.g. The Davenport Hotel\nRiverfront Pavilion", 'logistics' => ServiceAreaDetails::EXAMPLES['logistics'], 'photos' => $foreignPhoto]],
        ];
    }

    public function test_confirmed_details_earn_distinct_pages_and_everything_else_stays_in_the_list(): void
    {
        $photos = $this->startSpokane();
        $website = $this->generateAndPublish($this->spokaneRows($photos, ['00000000-0000-4000-8000-000000000000']));
        $locations = $website->pages()->where('page_role', 'location')->orderBy('slug')->get();

        $this->assertCount(2, $locations, 'Only Liberty Lake and Coeur d’Alene earn a page: ' . $locations->pluck('slug')->implode(', '));
        $this->assertSame(['serving-coeur-dalene-id', 'serving-liberty-lake-wa'], $locations->pluck('slug')->all());
        $this->assertSame([], $website->pages()->whereIn('slug', ['serving-spokane-wa', 'serving-spokane-valley-wa', 'serving-cheney-wa', 'serving-spokane'])->pluck('slug')->all(), 'Neither the primary city nor a bare or unsupported area gets a page.');
        $this->assertTrue((bool) $website->pages()->where('is_home', true)->firstOrFail()->is_home);

        $home = $this->html('/');
        foreach (['Spokane Valley', 'Cheney', 'Liberty Lake'] as $place) {
            $this->assertStringContainsString($place, $home, "{$place} stays visible in the Areas we serve list.");
        }
        $this->assertStringContainsString('Areas we serve', $home);

        $sitemap = $this->get('http://' . self::DOMAIN . '/sitemap.xml')->getContent();

        foreach ($locations as $page) {
            $html = $this->html('/' . $page->slug);
            $this->assertSame('location', $page->page_role);
            $this->assertNotNull($page->page_intent);
            $this->assertStringContainsString('<link rel="canonical" href="https://' . self::DOMAIN . '/' . $page->slug . '">', $html);
            $this->assertSame(1, preg_match_all('#<h1[ >]#', $html), 'One H1');
            $this->assertStringContainsString('https://' . self::DOMAIN . '/' . $page->slug, $sitemap);
            preg_match('#<main.*?</main>#s', $html, $main);
            preg_match_all('#<a [^>]*href="([^"]+)"#', $main[0], $links);
            $this->assertNotEmpty(array_filter($links[1], fn ($href) => str_contains($href, '-contact')), "{$page->slug} links to the contact page.");
            $this->assertNotEmpty(array_filter($links[1], fn ($href) => str_contains($href, '/services')), "{$page->slug} links to the services overview.");
        }

        // The page actually says what the owner confirmed — and nothing they did not.
        $liberty = $this->html('/serving-liberty-lake-wa');
        $this->assertStringContainsString('<h1', $liberty);
        $this->assertStringContainsString('Liberty Lake', strip_tags(explode('</h1>', explode('<h1', $liberty)[1])[0]));
        $this->assertStringContainsString('about 25 minutes from our Spokane studio', html_entity_decode($liberty));

        $coeur = html_entity_decode($this->html('/serving-coeur-dalene-id'));
        $this->assertStringContainsString('Hagadone Event Center', $coeur);
        $this->assertStringContainsString('Resort', $coeur);
        $this->assertStringContainsString('<img', explode('Events in', $coeur)[1] ?? '', 'The event photo appears.');
        $this->assertStringNotContainsString('Davenport', $coeur . $home . $liberty, 'An example is never published.');
        $this->assertStringNotContainsString('Riverfront Pavilion', $coeur . $home . $liberty);
        $this->assertStringNotContainsString('nothing to travel for', $home . $liberty, 'The base city has no page, so its details are not published as one.');

        $this->assertNotSame($this->html('/serving-liberty-lake-wa'), $coeur);
    }

    public function test_details_survive_next_back_editing_reload_reorder_and_removal(): void
    {
        $photos = $this->startSpokane();
        $this->continueStep('base_location', ['value' => ['city' => 'Spokane', 'region' => 'WA', 'country_code' => 'US']])->assertOk();
        $rows = $this->spokaneRows($photos);

        $this->saveAreas($rows)->assertOk();

        $stored = $this->wizardSession()->answers;
        $this->assertSame(['Spokane, WA', 'Spokane Valley, WA', 'Liberty Lake, WA', "Coeur d'Alene, ID", 'Cheney, WA'], $stored['service_area_cities'], 'The list stays a plain list of names.');
        $details = $stored[ServiceAreaDetails::ANSWER_KEY];
        $this->assertSame(self::LOGISTICS, $details['liberty-lake-wa']['logistics']);
        $this->assertSame([$photos[1]], $details['coeur-dalene-id']['photos']);
        $this->assertArrayNotHasKey('spokane-valley-wa', $details);
        $this->assertArrayNotHasKey('cheney-wa', $details, 'Example text submitted back is not a confirmed fact.');

        // Reload: the form shows what was saved.
        $page = $this->get($this->url('step', 'service_area_cities'))->assertOk()->getContent();
        $this->assertStringContainsString('Add local details (optional)', $page);
        $this->assertStringContainsString('about 25 minutes from our Spokane studio', html_entity_decode($page));
        $this->assertStringContainsString('Hagadone Event Center', $page);

        // Next to another screen, then Back: still there.
        $this->continueStep('primary_cta', ['value' => 'quote_request'])->assertOk();
        $this->get($this->url('step', 'service_area_cities'))->assertOk()->assertSee('Hagadone Event Center', false);

        // Reorder + delete + edit: each row's details travel with the row, never shift to a neighbour.
        $this->saveAreas([$rows[3], $rows[2], ['Spokane Valley, WA'], ['Liberty Lake, WA', ['logistics' => 'Edited: 30 minutes, no fee.']]])->assertOk();
        $after = $this->wizardSession()->answers;
        $this->assertSame(["Coeur d'Alene, ID", 'Liberty Lake, WA', 'Spokane Valley, WA'], $after['service_area_cities'], 'The duplicate row is dropped; the first one wins.');
        $this->assertSame(self::LOGISTICS, $after[ServiceAreaDetails::ANSWER_KEY]['liberty-lake-wa']['logistics']);
        $this->assertSame(['coeur-dalene-id', 'liberty-lake-wa'], array_keys($after[ServiceAreaDetails::ANSWER_KEY]));

        // A submission with no detail payload at all (an older form) leaves the details alone.
        $this->continueStep('service_area_cities', ['value' => ["Coeur d'Alene, ID", 'Liberty Lake, WA']])->assertOk();
        $this->assertArrayHasKey('liberty-lake-wa', $this->wizardSession()->answers[ServiceAreaDetails::ANSWER_KEY]);

        // Clearing the list clears the details with it.
        $this->jpost($this->url('draft', 'service_area_cities'), ['answers_revision' => $this->wizardSession()->answers_revision, '__present' => ['service_area_cities']])->assertOk();
        $this->assertSame([], $this->wizardSession()->answers[ServiceAreaDetails::ANSWER_KEY]);
    }

    public function test_one_customers_photos_and_details_never_reach_another(): void
    {
        $photos = $this->startSpokane();
        $this->continueStep('base_location', ['value' => ['city' => 'Spokane', 'region' => 'WA', 'country_code' => 'US']])->assertOk();
        $this->saveAreas([["Coeur d'Alene, ID", ['venues' => self::VENUE, 'photos' => [$photos[0]]]]])->assertOk();

        // A second customer submits the first customer's photo uid as their own.
        $this->tenant(['name' => 'Rival Booths'], ['city' => 'Boise', 'region' => 'ID']);
        $this->saveAreas([['Nampa, ID', ['venues' => 'The Nampa Civic Center', 'photos' => [$photos[0]]]]])->assertOk();

        $mine = $this->wizardSession()->answers[ServiceAreaDetails::ANSWER_KEY];
        $this->assertEquals(['venues' => ['The Nampa Civic Center'], 'name' => 'Nampa, ID'], $mine['nampa-id'], 'The foreign photo is dropped; the rest is kept.');
        $this->assertStringNotContainsString(self::VENUE, json_encode($this->wizardSession()->answers));
        $this->assertStringNotContainsString($photos[0], $this->get($this->url('step', 'service_area_cities'))->getContent(), "The other customer's photo is never offered.");
        $this->assertSame([], array_values(array_filter($mine['nampa-id'], fn ($v) => $v === [$photos[0]])));
    }

    public function test_a_stale_revision_cannot_overwrite_area_details(): void
    {
        $photos = $this->startSpokane();
        $this->continueStep('base_location', ['value' => ['city' => 'Spokane', 'region' => 'WA', 'country_code' => 'US']])->assertOk();
        $this->saveAreas([['Liberty Lake, WA', ['logistics' => self::LOGISTICS]]])->assertOk();
        $stale = $this->wizardSession()->answers_revision;

        // Another tab saves first.
        $this->saveAreas([['Liberty Lake, WA', ['logistics' => self::LOGISTICS . ' Edited elsewhere.']]])->assertOk();

        $response = $this->jpost($this->url('draft', 'service_area_cities'), $this->areaPayload([['Liberty Lake, WA', ['logistics' => 'Overwrite attempt.']]]) + ['answers_revision' => $stale, '__present' => ['service_area_cities']]);
        $response->assertStatus(409);
        $this->assertStringContainsString('Edited elsewhere', $this->wizardSession()->answers[ServiceAreaDetails::ANSWER_KEY]['liberty-lake-wa']['logistics']);

        $response = $this->jpost($this->url('autosave', 'service_area_cities'), $this->areaPayload([['Liberty Lake, WA', ['logistics' => 'Overwrite attempt.']]]) + ['answers_revision' => $stale, '__present' => ['service_area_cities']]);
        $response->assertStatus(409);
        $this->assertStringNotContainsString('Overwrite attempt', json_encode($this->wizardSession()->answers));
    }

    public function test_a_saved_photo_selection_beyond_the_first_forty_gallery_photos_is_still_shown_and_kept(): void
    {
        $this->startSpokane();
        $website = Website::where('business_id', $this->business->id)->firstOrFail();
        for ($i = 0; $i < 45; $i++) {
            \App\Models\WebsiteAsset::forceCreate(['website_id' => $website->id, 'disk' => 'public', 'path' => "images/bulk{$i}.png", 'mime_type' => 'image/png', 'size' => 1, 'width' => 1, 'height' => 1, 'content_hash' => hash('sha256', "bulk{$i}"), 'purpose' => 'gallery', 'sort_order' => 1000 + $i]);
        }
        $this->continueStep('base_location', ['value' => ['city' => 'Spokane', 'region' => 'WA', 'country_code' => 'US']])->assertOk();
        $deep = $website->assets()->where('path', 'images/bulk44.png')->firstOrFail()->uid;
        $shallow = $website->assets()->where('path', 'images/bulk43.png')->firstOrFail()->uid;
        $this->saveAreas([["Coeur d'Alene, ID", ['venues' => self::VENUE, 'photos' => [$deep]]]])->assertOk();
        $this->assertSame([$deep], $this->wizardSession()->answers[ServiceAreaDetails::ANSWER_KEY]['coeur-dalene-id']['photos']);

        $page = $this->get($this->url('step', 'service_area_cities'))->assertOk()->getContent();
        $this->assertStringContainsString('value="' . $deep . '" data-area-photo checked', preg_replace('/\s+/', ' ', $page), 'The saved photo is offered and ticked, however deep in the gallery.');
        $this->assertStringNotContainsString($shallow, $page, 'Unselected photos beyond the first 40 are not rendered.');
        $this->assertStringContainsString('of your 49 gallery photos', $page, 'The owner is told the list is partial.');

        // Saving what the form posts back keeps it.
        $this->saveAreas([["Coeur d'Alene, ID", ['venues' => self::VENUE, 'photos' => [$deep]]]])->assertOk();
        $this->assertSame([$deep], $this->wizardSession()->answers[ServiceAreaDetails::ANSWER_KEY]['coeur-dalene-id']['photos']);
    }

    public function test_more_than_six_photos_for_an_area_is_refused_and_the_earlier_selection_is_kept(): void
    {
        $this->startSpokane();
        $website = Website::where('business_id', $this->business->id)->firstOrFail();
        for ($i = 0; $i < 4; $i++) {
            \App\Models\WebsiteAsset::forceCreate(['website_id' => $website->id, 'disk' => 'public', 'path' => "images/six{$i}.png", 'mime_type' => 'image/png', 'size' => 1, 'width' => 1, 'height' => 1, 'content_hash' => hash('sha256', "six{$i}"), 'purpose' => 'gallery', 'sort_order' => 500 + $i]);
        }
        $uids = $website->assets()->where('purpose', 'gallery')->orderBy('id')->pluck('uid')->all();
        $this->assertGreaterThanOrEqual(8, count($uids));
        $this->continueStep('base_location', ['value' => ['city' => 'Spokane', 'region' => 'WA', 'country_code' => 'US']])->assertOk();

        $this->saveAreas([["Coeur d'Alene, ID", ['venues' => self::VENUE, 'photos' => array_slice($uids, 0, 6)]]])->assertOk();
        $this->assertSame(array_slice($uids, 0, 6), $this->wizardSession()->answers[ServiceAreaDetails::ANSWER_KEY]['coeur-dalene-id']['photos']);
        $revision = $this->wizardSession()->answers_revision;

        // A seventh distinct valid photo (and a duplicate, which is not a seventh) over autosave and the debounced draft.
        $tooMany = $this->areaPayload([["Coeur d'Alene, ID", ['venues' => 'Changed venue', 'photos' => array_slice($uids, 0, 7)]]]);
        $draft = $this->jpost($this->url('draft', 'service_area_cities'), $tooMany + ['answers_revision' => $revision, '__present' => ['service_area_cities']]);
        $draft->assertStatus(422);
        $this->assertStringContainsString('at most 6 photos', $draft->json('message'));
        $this->jpost($this->url('autosave', 'service_area_cities'), $tooMany + ['answers_revision' => $revision, '__present' => ['service_area_cities']])->assertStatus(422);

        $kept = $this->wizardSession();
        $this->assertSame($revision, $kept->answers_revision, 'Nothing was written.');
        $this->assertSame(array_slice($uids, 0, 6), $kept->answers[ServiceAreaDetails::ANSWER_KEY]['coeur-dalene-id']['photos']);
        $this->assertSame([self::VENUE], $kept->answers[ServiceAreaDetails::ANSWER_KEY]['coeur-dalene-id']['venues']);

        // Six distinct photos with a repeated uid is still six: accepted.
        $this->saveAreas([["Coeur d'Alene, ID", ['venues' => self::VENUE, 'photos' => array_merge(array_slice($uids, 0, 6), [$uids[0]])]]])->assertOk();

        // The panel shows the count and disables the rest at the limit.
        $page = $this->get($this->url('step', 'service_area_cities'))->assertOk()->getContent();
        $this->assertStringContainsString('6 of 6 photos selected', $page);
        $this->assertMatchesRegularExpression('/value="' . preg_quote($uids[6], '/') . '" data-area-photo\s+disabled/', $page);
    }

    public function test_publishing_is_untouched_until_the_owner_rebuilds(): void
    {
        $photos = $this->startSpokane();
        $website = $this->generateAndPublish($this->spokaneRows($photos));
        $revisionId = $website->published_revision_id;
        $snapshot = WebsiteRevision::findOrFail($revisionId)->snapshot;
        $home = $this->html('/');

        // The owner edits their details afterwards (the wizard is still reachable): nothing live moves.
        $response = \App\Models\QuestionnaireResponse::where('business_id', $this->business->id)->latest('id')->firstOrFail();
        $response->forceFill(['answers' => $response->answers + [ServiceAreaDetails::ANSWER_KEY => ['cheney-wa' => ['name' => 'Cheney, WA', 'logistics' => 'Cheney is 20 minutes from us with no travel fee at all.']]]])->save();

        $this->assertSame($revisionId, $website->fresh()->published_revision_id);
        $this->assertEquals($snapshot, WebsiteRevision::findOrFail($revisionId)->snapshot, 'The published snapshot is immutable.');
        $this->assertSame($home, $this->html('/'));
        $this->get('http://' . self::DOMAIN . '/serving-cheney-wa')->assertNotFound();
    }

    public function test_qualified_areas_without_room_are_shown_to_the_owner_not_silently_dropped(): void
    {
        $this->startSpokane();
        $this->completeGuidedSetup([
            'service_area_cities' => fn () => $this->saveAreas(array_map(fn ($n) => ["Town {$n}, WA", ['logistics' => "Town {$n} is a {$n}0 minute drive with a flat travel fee."]], range(1, 12))),
        ]);
        $website = Website::where('business_id', $this->business->id)->firstOrFail();
        $template = \App\Models\WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $summary = app(WebsitePlanSummary::class)->forResponse($this->business, $website, $template, $this->wizardSession());

        $this->assertLessThanOrEqual(WebsitePageStrategy::MAX_AREA_PAGES, count($summary['areas']['planned_list']));
        $this->assertNotEmpty($summary['areas']['over_budget'], 'Qualified areas that did not fit are listed with the reason.');
        $this->assertSame(12, count($summary['areas']['planned_list']) + count($summary['areas']['over_budget']), 'Every qualified area is either planned or explained.');
        $this->assertStringContainsString('Not planned', $summary['areas']['over_budget'][0]['reason']);
        $this->assertStringContainsString("qualify for a page but can't be included", html_entity_decode(view('customer.business.website.wizard.steps._plan', ['plan' => $summary])->render(), ENT_QUOTES));
    }

    public function test_examples_and_markup_never_become_facts(): void
    {
        $entry = LocalAreaUsefulness::normalize(['name' => 'Cheney', 'details' => [
            'venues' => ['e.g. The Davenport Hotel', 'Riverfront Pavilion', '<script>alert(1)</script>Eastern Washington University Hall'],
            'logistics' => ServiceAreaDetails::EXAMPLES['logistics'],
        ]]);

        $this->assertSame(['venues' => ['alert(1)Eastern Washington University Hall']], $entry['details']);
        $this->assertFalse(LocalAreaUsefulness::assess(LocalAreaUsefulness::normalize(['name' => 'Cheney', 'details' => ['logistics' => ServiceAreaDetails::EXAMPLES['logistics']]]), 'Spokane')['qualifies']);
    }
}
