<?php

namespace Tests\Feature\Seo\Rank;

use App\Enums\Seo\SeoRankObservationStatus;
use App\Enums\Website\WebsiteDomainStatus;
use App\Library\Seo\Rank\SeoRankIdentity;
use App\Library\Seo\Rank\SeoRankMatcher;
use App\Models\Website;
use App\Models\WebsiteDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Seo\Rank\Concerns\CreatesRankFixtures;
use Tests\TestCase;

/**
 * Pure matching of provider rows to our Business, plus identity resolution.
 */
class SeoRankMatcherTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRankFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rankSetUp();
    }

    private function matcher(): SeoRankMatcher
    {
        return new SeoRankMatcher();
    }

    private function id(?string $domain = 'example.com', ?string $phone = null, ?string $cid = null): SeoRankIdentity
    {
        return new SeoRankIdentity($domain, $phone, $cid);
    }

    // ----------------------------- ORGANIC -----------------------------

    public function test_organic_exact_domain_matches(): void
    {
        $r = $this->matcher()->organic($this->id(), [$this->item(4, 'example.com')], 100);

        $this->assertSame(SeoRankObservationStatus::Found, $r['status']);
        $this->assertSame(4, $r['position']);
        $this->assertSame('domain', $r['basis']);
        $this->assertSame('example.com', $r['domain']);
    }

    public function test_organic_sub_page_of_the_canonical_domain_matches(): void
    {
        $r = $this->matcher()->organic($this->id(), [$this->item(3, 'example.com', 'https://example.com/services/rentals?x=1')], 100);

        $this->assertSame(SeoRankObservationStatus::Found, $r['status']);
        $this->assertSame('/services/rentals', $r['path']);
    }

    public function test_organic_www_and_scheme_are_ignored(): void
    {
        $this->assertSame(2, $this->matcher()->organic($this->id(), [$this->item(2, 'www.example.com', 'http://www.example.com/')], 100)['position']);
        $this->assertSame(5, $this->matcher()->organic($this->id(), [$this->item(5, null, 'HTTPS://WWW.Example.COM/Page')], 100)['position']);
        $this->assertSame(6, $this->matcher()->organic($this->id(), [$this->item(6, 'EXAMPLE.com')], 100)['position']);
    }

    public function test_organic_foreign_sibling_and_lookalike_hosts_do_not_match(): void
    {
        foreach (['other.com', 'blog.example.com', 'example.com.evil.com', 'notexample.com', 'example.org', 'evil.com'] as $host) {
            $r = $this->matcher()->organic($this->id(), [$this->item(1, $host)], 100);

            $this->assertSame(SeoRankObservationStatus::NotFound, $r['status'], $host);
            $this->assertNull($r['position'], $host);
        }
    }

    public function test_organic_a_lookalike_in_the_url_path_or_query_does_not_match(): void
    {
        $r = $this->matcher()->organic($this->id(), [$this->item(1, null, 'https://evil.com/example.com')], 100);
        $this->assertSame(SeoRankObservationStatus::NotFound, $r['status']);

        $r = $this->matcher()->organic($this->id(), [$this->item(1, null, 'https://evil.com/?u=https://example.com/')], 100);
        $this->assertSame(SeoRankObservationStatus::NotFound, $r['status']);
    }

    public function test_the_result_item_carries_no_title_so_title_text_cannot_match(): void
    {
        $this->assertFalse(property_exists(\App\Library\Seo\Rank\Provider\SeoRankResultItem::class, 'title'));

        // A row for another domain is never matched however the business is described.
        $r = $this->matcher()->organic($this->id(), [$this->item(1, 'directory.com', 'https://directory.com/example.com-review')], 100);
        $this->assertSame(SeoRankObservationStatus::NotFound, $r['status']);
    }

    public function test_organic_rank_is_the_first_lowest_position_matching_row_regardless_of_input_order(): void
    {
        $items = [
            $this->item(40, 'example.com', 'https://example.com/b'),
            $this->item(9, 'example.com', 'https://example.com/a'),
            $this->item(1, 'other.com'),
        ];

        $r = $this->matcher()->organic($this->id(), $items, 100);

        $this->assertSame(9, $r['position']);
        $this->assertSame('/a', $r['path']);
    }

    public function test_organic_top_100_boundary(): void
    {
        $at100 = $this->matcher()->organic($this->id(), [$this->item(100, 'example.com')], 100);
        $this->assertSame(SeoRankObservationStatus::Found, $at100['status']);
        $this->assertSame(100, $at100['position']);

        $at101 = $this->matcher()->organic($this->id(), [$this->item(101, 'example.com')], 100);
        $this->assertSame(SeoRankObservationStatus::NotFound, $at101['status']);
        $this->assertNull($at101['position']);

        // A deeper row never shadows an in-depth one.
        $both = $this->matcher()->organic($this->id(), [$this->item(101, 'example.com'), $this->item(99, 'example.com')], 100);
        $this->assertSame(99, $both['position']);
    }

    public function test_organic_no_result_is_not_found_with_null_everything_never_zero_or_101(): void
    {
        $r = $this->matcher()->organic($this->id(), [], 100);

        $this->assertSame(SeoRankObservationStatus::NotFound, $r['status']);
        $this->assertNull($r['position']);
        $this->assertNull($r['url']);
        $this->assertNull($r['domain']);
        $this->assertNull($r['path']);
        $this->assertNull($r['basis']);
    }

    public function test_organic_without_a_canonical_domain_is_not_matched_not_not_found(): void
    {
        $r = $this->matcher()->organic($this->id(null, '312-555-0100'), [$this->item(1, 'example.com')], 100);

        $this->assertSame(SeoRankObservationStatus::NotMatched, $r['status']);
        $this->assertNull($r['position']);
    }

    // ------------------------------ LOCAL ------------------------------

    public function test_local_cid_has_precedence_over_domain_and_phone(): void
    {
        $identity = $this->id('example.com', '312-555-0100', 'cid-123');
        $items = [
            $this->item(1, 'example.com', null, '312-555-0100'),
            $this->item(2, 'cidowner.com', null, null, 'cid-123'),
        ];

        $r = $this->matcher()->local($identity, $items, 10);

        $this->assertSame(2, $r['position']);
        $this->assertSame('cid', $r['basis']);
    }

    public function test_local_domain_has_precedence_over_phone(): void
    {
        $identity = $this->id('example.com', '312-555-0100');
        $items = [
            $this->item(1, 'phoneonly.com', null, '(312) 555-0100'),
            $this->item(3, 'www.example.com'),
        ];

        $r = $this->matcher()->local($identity, $items, 10);

        $this->assertSame(3, $r['position']);
        $this->assertSame('domain', $r['basis']);
    }

    public function test_local_phone_matches_after_normalization(): void
    {
        $identity = new SeoRankIdentity(null, SeoRankIdentity::normalizePhone('312-555-0100'));
        $r = $this->matcher()->local($identity, [$this->item(2, null, null, '+1 (312) 555-0100')], 10);

        $this->assertSame(SeoRankObservationStatus::Found, $r['status']);
        $this->assertSame(2, $r['position']);
        $this->assertSame('phone', $r['basis']);
    }

    public function test_local_a_similarly_named_wrong_business_is_not_matched(): void
    {
        $identity = $this->id('example.com', '312-555-0100');
        $items = [
            $this->item(1, 'example-photo-booths.com', null, '312-555-9999'),
            $this->item(2, 'blog.example.com', null, '773-555-0100'),
        ];

        $r = $this->matcher()->local($identity, $items, 10);

        $this->assertSame(SeoRankObservationStatus::NotFound, $r['status']);
        $this->assertNull($r['position']);
    }

    public function test_local_absent_listing_is_not_found(): void
    {
        $r = $this->matcher()->local($this->id('example.com', '312-555-0100', 'cid-1'), [], 10);

        $this->assertSame(SeoRankObservationStatus::NotFound, $r['status']);
        $this->assertNull($r['position']);
    }

    public function test_local_respects_the_depth(): void
    {
        $r = $this->matcher()->local($this->id(), [$this->item(11, 'example.com')], 10);
        $this->assertSame(SeoRankObservationStatus::NotFound, $r['status']);

        $r = $this->matcher()->local($this->id(), [$this->item(10, 'example.com')], 10);
        $this->assertSame(10, $r['position']);
    }

    public function test_local_with_no_identity_at_all_is_not_matched_distinct_from_not_found(): void
    {
        $r = $this->matcher()->local($this->id(null, null, null), [$this->item(1, 'example.com', null, '312-555-0100')], 10);

        $this->assertSame(SeoRankObservationStatus::NotMatched, $r['status']);
        $this->assertNotSame(SeoRankObservationStatus::NotFound, $r['status']);
        $this->assertNull($r['position']);
    }

    public function test_a_business_name_is_never_part_of_the_identity(): void
    {
        $this->assertSame(['domain', 'phone', 'cid'], array_map(fn ($p) => $p->getName(), (new \ReflectionClass(SeoRankIdentity::class))->getProperties()));
    }

    // ------------------------ Identity normalization --------------------

    public function test_normalize_host_edge_cases(): void
    {
        $this->assertSame('example.com', SeoRankIdentity::normalizeHost('https://user:pw@www.Example.com:8443/path?q=1#f'));
        $this->assertSame('example.com', SeoRankIdentity::normalizeHost('  www.example.com. '));
        $this->assertSame('blog.example.com', SeoRankIdentity::normalizeHost('http://blog.example.com'));
        $this->assertNull(SeoRankIdentity::normalizeHost(null));
        $this->assertNull(SeoRankIdentity::normalizeHost(''));
        $this->assertNull(SeoRankIdentity::normalizeHost('   '));
        $this->assertNull(SeoRankIdentity::normalizeHost('localhost'));
        $this->assertNull(SeoRankIdentity::normalizeHost('https:///'));
    }

    public function test_normalize_phone_edge_cases(): void
    {
        $this->assertSame('3125550100', SeoRankIdentity::normalizePhone('+1 (312) 555-0100'));
        $this->assertSame('3125550100', SeoRankIdentity::normalizePhone('312-555-0100'));
        $this->assertSame('3125550100', SeoRankIdentity::normalizePhone('1.312.555.0100'));
        $this->assertNull(SeoRankIdentity::normalizePhone('555-0100'));
        $this->assertNull(SeoRankIdentity::normalizePhone('555-010-123'), '9 digits is not an identity');
        $this->assertNull(SeoRankIdentity::normalizePhone(''));
        $this->assertNull(SeoRankIdentity::normalizePhone(null));
        $this->assertNull(SeoRankIdentity::normalizePhone('call us'));
    }

    // --------------------------- forBusiness ---------------------------

    public function test_for_business_uses_the_active_primary_website_domain_and_business_phone(): void
    {
        [, $business] = $this->rankTenant(domain: 'WWW.PhotoBoothCo.com');

        $identity = SeoRankIdentity::forBusiness($business->fresh());

        $this->assertSame('photoboothco.com', $identity->domain);
        $this->assertSame('5550101234', $identity->phone);
        $this->assertNull($identity->cid);
    }

    public function test_for_business_ignores_inactive_and_non_primary_domains_and_website_url(): void
    {
        [, $business] = $this->rankTenant(domain: null);
        DB::table('businesses')->where('id', $business->id)->update(['website_url' => 'https://freetext.example.net']);

        $website = $this->publishWebsite($business, [$this->snapshotPage('home', 'Home', [], [], true, 'home')]);

        WebsiteDomain::create([
            'uid' => (string) Str::uuid(), 'website_id' => $website->id, 'domain' => 'inactive-primary.com',
            'is_primary' => true, 'status' => WebsiteDomainStatus::PendingVerification,
        ]);
        WebsiteDomain::create([
            'uid' => (string) Str::uuid(), 'website_id' => $website->id, 'domain' => 'active-secondary.com',
            'is_primary' => false, 'status' => WebsiteDomainStatus::Active,
        ]);

        $identity = SeoRankIdentity::forBusiness($business->fresh());

        $this->assertNull($identity->domain, 'Only the ACTIVE PRIMARY domain is canonical.');
        $this->assertFalse($identity->canMatchOrganic());
        $this->assertTrue($identity->canMatchLocal(), 'The NAP phone still identifies the local listing.');

        // Promote one to active primary: now it wins.
        WebsiteDomain::query()->where('domain', 'inactive-primary.com')->update(['status' => WebsiteDomainStatus::Active->value]);
        $this->assertSame('inactive-primary.com', SeoRankIdentity::forBusiness($business->fresh())->domain);
        $this->assertSame(1, Website::query()->where('business_id', $business->id)->count());
    }
}
