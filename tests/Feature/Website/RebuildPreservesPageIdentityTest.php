<?php

namespace Tests\Feature\Website;

use App\Enums\Business\BusinessServiceStatus;
use App\Library\Seo\Content\ArticleMarkdown;
use App\Library\Seo\Content\ArticleSiteInventory;
use App\Library\Website\GuidedGeneration\GuidedGenerationCommitService;
use App\Library\Website\WebsiteDraftPageService;
use App\Library\Website\WebsitePublisher;
use App\Models\BusinessLocation;
use App\Models\BusinessService;
use App\Models\Website;
use App\Models\WebsiteArticle;
use App\Models\WebsiteTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesSmartWizard;
use Tests\TestCase;

/**
 * A rebuild keeps the uid of every page that is the SAME page as one in the live revision, so published articles that point at a
 * page (supports_page_uid, `page:<uid>` links) still resolve after the owner publishes the rebuilt site. The live revision itself is
 * never touched by a rebuild, and a rollback simply makes the old, immutable revision live again.
 */
class RebuildPreservesPageIdentityTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use DrivesSmartWizard;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSmartWizard();
    }

    private function tenant(): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->bindDistinctAiClient();
        $this->authenticateAsCustomer($customer);
        $this->completeV2Setup($workspace, $business);
        foreach ([1, 2] as $n) {
            BusinessService::create(['business_id' => $business->id, 'name' => 'Booth ' . $n, 'slug' => 'booth-' . $n, 'status' => BusinessServiceStatus::Active->value, 'sort_order' => $n, 'description' => 'A described booth number ' . $n . ' with its own features.']);
        }
        $primary = $business->locations()->create(['name' => 'Primary Location', 'service_mode' => 'service_area', 'country_code' => 'US', 'city' => 'Manhattan', 'region' => 'NY', 'service_area_cities' => ['Portland, OR', 'Portland, ME']]);
        $primary->forceFill(['is_primary' => true])->save();
        foreach (['OR', 'ME'] as $region) {
            BusinessLocation::create(['business_id' => $business->id, 'service_mode' => 'service_area', 'city' => 'Portland', 'region' => $region, 'service_area_cities' => ['Portland', 'Suburb ' . $region], 'public_address' => true, 'address_line_1' => '1 Main St ' . $region]);
        }

        return [$customer, $business];
    }

    private function build(object $business, bool $rebuild): void
    {
        $website = Website::where('business_id', $business->id)->first() ?? $this->createWebsite($business);
        $service = app(GuidedGenerationCommitService::class);
        $args = [$business->fresh(), $website->fresh(), WebsiteTemplate::findActiveOrFail('photo_booth_modern'), (int) $business->customer_id, 'k-' . uniqid(), null, null, null, null, [], null];
        $attempt = $rebuild ? $service->rebuild(...$args) : $service->generateFull(...$args);
        $this->assertSame('succeeded', $attempt->status);
    }

    private function publish(object $business): \App\Models\WebsiteRevision
    {
        $website = Website::where('business_id', $business->id)->sole();
        // Publishing demands real gallery photos; two owned assets satisfy it deterministically.
        while ($website->assets()->where('purpose', 'gallery')->count() < 3) {
            $website->assets()->create(['disk' => 'public', 'path' => 'images/websites/' . $website->uid . '/' . uniqid('', true) . '.png', 'mime_type' => 'image/png', 'size' => 1024, 'purpose' => 'gallery', 'alt_text' => 'A photo booth at an event']);
        }
        app(\App\Library\Website\WebsiteSearchVisibility::class)->release($website);

        return app(WebsitePublisher::class)->publish($website->fresh(), $this->platformAdminId());
    }

    /** @return array<string, string> slug => uid of the live revision's pages ('' for home) */
    private function live(object $business): array
    {
        $pages = Website::where('business_id', $business->id)->sole()->publishedRevision->snapshot['pages'];

        return collect($pages)->mapWithKeys(fn ($page) => [(string) ($page['slug'] ?? '') => $page['uid']])->all();
    }

    private function article(object $business, string $supportsUid, string $linkUid): WebsiteArticle
    {
        $article = (new WebsiteArticle())->forceFill([
            'business_id' => $business->id, 'website_id' => Website::where('business_id', $business->id)->value('id'),
            'title' => 'Planning your booth', 'slug' => 'planning-your-booth', 'status' => 'published', 'noindex' => false,
            'body' => "Read about [our booth](page:{$linkUid}) today.", 'supports_page_uid' => $supportsUid, 'published_at' => now(),
        ]);
        $article->save();

        return $article;
    }

    public function test_a_rebuilt_and_republished_site_keeps_the_uids_published_articles_point_at(): void
    {
        [, $business] = $this->tenant();
        $this->build($business, false);
        $this->publish($business);
        $old = $this->live($business);
        $servicePage = collect($old)->keys()->first(fn ($slug) => str_starts_with($slug, 'service-'));
        $article = $this->article($business, $old[$servicePage], $old[$servicePage]);

        $this->build($business, true);
        $this->publish($business);
        $new = $this->live($business);

        $this->assertSame($old[$servicePage], $new[$servicePage], 'The same service keeps its page uid.');
        $this->assertSame($old[''], $new[''], 'The home page keeps its uid.');
        $this->assertSame($old['serving-portland-or'], $new['serving-portland-or']);
        $this->assertSame($old['serving-portland-me'], $new['serving-portland-me']);
        $this->assertNotSame($new['serving-portland-or'], $new['serving-portland-me'], 'Portland, OR and Portland, ME never share an identity.');
        $this->assertSame(count($new), count(array_unique($new)), 'No uid is used twice.');

        // supports_page_uid still resolves to the same service page, and the Markdown reference still becomes a working link.
        $found = app(ArticleSiteInventory::class)->find($business->fresh(), $article->supports_page_uid);
        $this->assertNotNull($found);
        $this->assertSame($servicePage, $found['slug']);
        $snapshotPages = collect(Website::where('business_id', $business->id)->sole()->publishedRevision->snapshot['pages'])->keyBy('uid');
        $html = (string) ArticleMarkdown::toHtml($article->body, fn ($type, $uid) => $type === 'page' && $snapshotPages->has($uid) ? '/' . $snapshotPages[$uid]['slug'] : null);
        $this->assertStringContainsString('<a href="/' . $servicePage . '"', $html);
    }

    public function test_a_new_page_gets_a_new_uid_and_a_removed_page_is_not_given_to_another(): void
    {
        [, $business] = $this->tenant();
        $this->build($business, false);
        $this->publish($business);
        $old = $this->live($business);

        BusinessService::where('business_id', $business->id)->where('slug', 'booth-2')->update(['status' => BusinessServiceStatus::Inactive->value]);
        BusinessService::create(['business_id' => $business->id, 'name' => 'Booth 3', 'slug' => 'booth-3', 'status' => BusinessServiceStatus::Active->value, 'sort_order' => 3, 'description' => 'A described third booth with its own features.']);
        $this->build($business, true);
        $this->publish($business);
        $new = $this->live($business);

        $removed = array_diff_key($old, $new);
        $added = array_diff_key($new, $old);
        $this->assertNotEmpty($removed);
        $this->assertNotEmpty($added);
        $this->assertSame([], array_intersect(array_values($removed), array_values($new)), 'A removed page\'s uid is not reused by any page.');
        $this->assertSame([], array_intersect(array_values($added), array_values($old)), 'A genuinely new page gets a new uid.');
    }

    public function test_rebuilding_never_touches_the_live_revision_and_a_rollback_restores_it_intact(): void
    {
        [, $business] = $this->tenant();
        $this->build($business, false);
        $first = $this->publish($business);
        $old = $this->live($business);
        $snapshotBefore = json_encode($first->fresh()->snapshot);

        $this->build($business, true);
        $this->assertSame($old, $this->live($business), 'Until the owner publishes, the live site is exactly as it was.');
        $this->assertSame($snapshotBefore, json_encode($first->fresh()->snapshot));

        $this->publish($business);
        app(WebsitePublisher::class)->rollback(Website::where('business_id', $business->id)->sole(), $first->uid);

        $this->assertSame($old, $this->live($business), 'The original published pages, with their original uids, are live again.');
    }

    public function test_another_websites_uids_are_never_reused_and_a_request_cannot_choose_a_uid(): void
    {
        [, $business] = $this->tenant();
        $this->build($business, false);
        $page = Website::where('business_id', $business->id)->sole()->pages()->where('is_home', false)->firstOrFail();
        $foreignUid = $page->uid;

        [, $other] = $this->entitledTenant();
        $otherWebsite = $this->createWebsite($other);
        $pages = app(WebsiteDraftPageService::class);
        $text = [['type' => 'text', 'data' => ['heading' => 'H', 'body' => 'Body']]];

        $asked = $pages->createPage($otherWebsite, ['title' => 'Mine', 'slug' => 'mine', 'sections' => $text, 'uid' => $foreignUid]);
        $this->assertNotSame($foreignUid, $asked->uid, 'A uid in the request attributes is ignored.');

        $kept = $pages->createPage($otherWebsite, ['title' => 'Also mine', 'slug' => 'also-mine', 'sections' => $text], $foreignUid);
        $this->assertNotSame($foreignUid, $kept->uid, 'A uid already held by another website\'s page is never reused.');

        $free = $pages->createPage($otherWebsite, ['title' => 'Free', 'slug' => 'free', 'sections' => $text], '11111111-1111-4111-8111-111111111111');
        $this->assertSame('11111111-1111-4111-8111-111111111111', $free->uid, 'The rebuild path can keep a uid that nothing else holds.');
    }
}
