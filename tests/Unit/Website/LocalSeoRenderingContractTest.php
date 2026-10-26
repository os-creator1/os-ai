<?php

namespace Tests\Unit\Website;

use App\Library\Website\Design\WebsiteLegalLinks;
use App\Library\Website\Seo\WebsiteHeadMeta;
use App\Library\Website\Seo\WebsiteRedirectMap;
use Tests\TestCase;

/**
 * Renderer-level SEO contract fixes found on the live Transparent Photo Booths site. They need no content change and
 * apply to every already-published site as soon as the code is deployed.
 */
class LocalSeoRenderingContractTest extends TestCase
{
    public function test_a_title_that_already_names_the_brand_in_a_singular_or_plural_variant_is_never_suffixed_again(): void
    {
        $this->assertSame('Privacy Policy | Transparent Photo Booth', WebsiteHeadMeta::title('Privacy Policy | Transparent Photo Booth', 'Privacy Policy', 'Transparent Photo Booths'));
        $this->assertSame('Terms | transparent photo booths', WebsiteHeadMeta::title('Terms | transparent photo booths', '', 'Transparent Photo Booths'));
        $this->assertSame('Wedding Photo Booth Rental Spokane | Transparent Photo Booths', WebsiteHeadMeta::title('Wedding Photo Booth Rental Spokane', '', 'Transparent Photo Booths'));
        $this->assertFalse(WebsiteHeadMeta::mentionsBrand('Photo Booth Gallery', 'Transparent Photo Booths'));
        $this->assertFalse(WebsiteHeadMeta::mentionsBrand('Anything', ''));
    }

    private function snapshot(array $slugs): array
    {
        return ['pages' => array_merge([['uid' => 'h', 'slug' => null, 'is_home' => true]], array_map(fn ($slug) => ['uid' => $slug, 'slug' => $slug, 'is_home' => false], $slugs))];
    }

    public function test_legacy_one_word_and_punctuation_spellings_find_the_single_matching_live_page(): void
    {
        $snapshot = $this->snapshot(['wedding-photo-booth-rental', 'corporate-photo-booth-rental', 'party-photo-booth-rental', 'terms-and-conditions', 'privacy-policy', 'spokane']);

        $this->assertSame('wedding-photo-booth-rental', WebsiteRedirectMap::target($snapshot, 'weddingphotoboothrental'));
        $this->assertSame('corporate-photo-booth-rental', WebsiteRedirectMap::target($snapshot, 'corporatephotoboothrental'));
        $this->assertSame('party-photo-booth-rental', WebsiteRedirectMap::target($snapshot, 'PartyPhotoBoothRental'));
        $this->assertSame('terms-and-conditions', WebsiteRedirectMap::target($snapshot, 'Terms-Conditions'));
        $this->assertNull(WebsiteRedirectMap::target($snapshot, 'privacy'), 'a different word is never a spelling variant');
        $this->assertNull(WebsiteRedirectMap::target($snapshot, 'spokane-wa'));
        $this->assertNull(WebsiteRedirectMap::target($snapshot, 'andand'));
    }

    public function test_an_ambiguous_spelling_a_live_page_and_the_homepage_never_redirect(): void
    {
        $ambiguous = $this->snapshot(['photo-booths', 'photo-and-booths']);
        $this->assertNull(WebsiteRedirectMap::target($ambiguous, 'photobooths'), 'one key but two pages is a guess, not a redirect');

        $live = $this->snapshot(['photobooths', 'photo-booths']);
        $this->assertNull(WebsiteRedirectMap::target($live, 'photobooths'), 'a live address is served, never redirected');
    }

    public function test_an_explicit_redirect_still_wins_over_a_spelling_match(): void
    {
        $snapshot = $this->snapshot(['wedding-photo-booth-rental', 'weddings']) + ['redirects' => ['weddingphotoboothrental' => 'weddings']];

        $this->assertSame('weddings', WebsiteRedirectMap::target($snapshot, 'weddingphotoboothrental'));
    }

    public function test_the_footer_terms_link_points_at_the_real_page_when_the_owner_pasted_a_legacy_spelling(): void
    {
        $pageUrls = [
            '' => 'https://transparentphotobooths.com/',
            'terms-and-conditions' => 'https://transparentphotobooths.com/terms-and-conditions',
            'privacy-policy' => 'https://transparentphotobooths.com/privacy-policy',
        ];
        $theme = ['privacy_notice_url' => 'https://transparentphotobooths.com/Privacy-Policy', 'terms_url' => 'https://transparentphotobooths.com/Terms-Conditions'];

        $links = WebsiteLegalLinks::fromTheme($theme, $pageUrls);

        $this->assertSame('https://transparentphotobooths.com/privacy-policy', $links['privacy']);
        $this->assertSame('https://transparentphotobooths.com/terms-and-conditions', $links['terms']);
    }

    public function test_external_or_unknown_legal_addresses_are_left_exactly_as_the_owner_wrote_them(): void
    {
        $pageUrls = ['' => 'https://example.com/', 'terms-and-conditions' => 'https://example.com/terms-and-conditions'];

        $links = WebsiteLegalLinks::fromTheme(['privacy_notice_url' => 'https://policies.example.org/Privacy', 'terms_url' => 'https://example.com/Something-Else'], $pageUrls);

        $this->assertSame('https://policies.example.org/Privacy', $links['privacy']);
        $this->assertSame('https://example.com/Something-Else', $links['terms']);
        $this->assertSame(['privacy' => null, 'terms' => null], WebsiteLegalLinks::fromTheme(['terms_url' => 'http://example.com/terms'], $pageUrls));
    }
}
