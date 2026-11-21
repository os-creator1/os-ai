<?php

namespace Tests\Feature\Website\Concerns;

use App\Enums\Business\BusinessServiceStatus;
use App\Enums\Website\WebsiteDomainStatus;
use App\Library\Website\GuidedGeneration\GuidedGenerationCommitService;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Library\Website\WebsitePublisher;
use App\Models\BusinessLocation;
use App\Models\BusinessService;
use App\Models\Website;
use App\Models\WebsiteGuidedGenerationAttempt;
use App\Models\WebsiteTemplate;
use Illuminate\Support\Str;

/**
 * Generate (fake AI only), publish and fetch a whole local-business website over its custom domain. The using test class
 * defines the DOMAIN and AREAS constants and seeds the wizard (DrivesSmartWizard) and fixtures (CreatesWebsiteFixtures).
 */
trait PublishesGeneratedSites
{
    use FakesGuidedWebsiteAi;

    /** A published, generated site: [Website, array<string, string> page slug => page_type]. */
    /** @param array<int, string> $differentiators owner-CONFIRMED facts (the knowledge profile's differentiators), confirmed before generation */
    private function publishedSite(string $niche = 'booth', bool $withAreas = true, bool $withLocation = true, bool $publish = true, array $differentiators = [], ?callable $beforeGeneration = null): array
    {
        [$customer, $business] = $this->entitledTenant(['name' => $niche === 'booth' ? 'Harbor Booths' : 'Petal Tutoring', 'industry' => $niche === 'booth' ? 'photo_booth_service' : 'professional_services']);
        $names = $niche === 'booth' ? ['Magic Mirror Photo Booth', 'Open-Air Photo Booth', '360 Video Booth'] : ['SAT Preparation', 'Algebra Tutoring', 'Reading Support'];

        foreach ($names as $i => $name) {
            BusinessService::create(['business_id' => $business->id, 'name' => $name, 'slug' => Str::slug($name), 'description' => "What {$name} is: a specific, bookable service with its own details, requirements and outcomes for customers.", 'status' => BusinessServiceStatus::Active->value, 'sort_order' => $i + 1]);
        }

        if ($withLocation) {
            (new BusinessLocation())->forceFill(['business_id' => $business->id, 'is_primary' => true, 'service_mode' => 'service_area', 'name' => 'Main', 'city' => 'Petal', 'region' => 'Mississippi', 'country_code' => 'US', 'service_area_cities' => ['Hattiesburg', 'Laurel', 'Biloxi']])->save();
        }
        \Illuminate\Support\Facades\DB::table('businesses')->where('id', $business->id)->update(['phone' => '6015550142', 'email' => 'hello@harborbooths.example']);

        // The owner has already let search engines in, so generated pages are born indexable.
        if ($differentiators !== []) {
            app(\App\Library\Business\BusinessKnowledgeProfileManager::class)->updateFields($business->fresh(), ['differentiators' => $differentiators], 'manual_edit', (int) $customer->user_id, true);
        }

        $website = $this->createWebsite($business->fresh(), ['name' => 'Harbor', 'indexing_released_at' => now()]);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $this->fakeProvider();

        // A test may seed records the generator must leave alone (e.g. blog articles) once the Website exists, before it runs.
        $beforeGeneration && $beforeGeneration($website, $business->fresh());

        $attempt = app(GuidedGenerationCommitService::class)->generateFull($business->fresh(), $website, $template, $customer->user_id, 'idem-3b-' . $niche, null, null, null, null, $withAreas ? self::AREAS : null);
        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status, (string) $attempt->failure_reason);

        if (! $publish) {
            return [$website->fresh(), $business->fresh()];
        }

        app(WebsitePublisher::class)->publish($website->fresh(), $this->platformAdminId());
        $website->fresh()->domains()->create(['domain' => self::DOMAIN, 'is_primary' => true, 'status' => WebsiteDomainStatus::Active, 'verification_token' => 'x', 'verified_at' => now(), 'activated_at' => now()]);

        return [$website->fresh(), $business->fresh()];
    }

    private function url(string $path = '/'): string
    {
        return 'http://' . self::DOMAIN . $path;
    }

    /** @return array<string, string> slug => html for every page the published snapshot has */
    private function render(Website $website): array
    {
        $pages = [];

        foreach ($website->fresh()->publishedRevision->snapshot['pages'] ?? [] as $page) {
            $path = ! empty($page['is_home']) ? '/' : '/' . $page['slug'];
            $pages[$path] = $this->get($this->url($path))->assertOk()->getContent();
        }

        return $pages;
    }

    /** @return array<int, string> every href path of internal links in the body */
    private function links(string $html): array
    {
        preg_match_all('#href="(?:https://' . preg_quote(self::DOMAIN, '#') . ')?(/[^"\#?]*)"#', $html, $m);

        return array_values(array_unique($m[1]));
    }
}
