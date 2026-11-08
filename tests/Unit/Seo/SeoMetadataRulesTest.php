<?php

namespace Tests\Unit\Seo;

use App\Library\Seo\SeoAuditRuleRegistry as R;
use App\Library\Seo\SeoMetadataRules;
use App\Library\Seo\SeoTitleProposal;
use PHPUnit\Framework\TestCase;

class SeoMetadataRulesTest extends TestCase
{
    public function test_duplicates_use_the_audits_own_normalisation(): void
    {
        $this->assertFalse(SeoMetadataRules::satisfies(R::DUPLICATE_SEO_TITLE, 'Photo Booths', 'Home', 'Acme', ['  photo   BOOTHS '], 60, 70));
        $this->assertTrue(SeoMetadataRules::satisfies(R::DUPLICATE_SEO_TITLE, 'Event Photo Booths', 'Home', 'Acme', ['Photo Booths'], 60, 70));
        $this->assertFalse(SeoMetadataRules::satisfies(R::DUPLICATE_META_DESCRIPTION, '', 'Home', 'Acme', [], 60, 70), 'Blank is never unique.');
    }

    public function test_the_composed_title_length_decides_not_the_field_length(): void
    {
        $title = 'Photo Booth Rentals for Weddings and Birthdays'; // 46 characters alone, 64 once " | Acme Events Ltd" is added
        $this->assertFalse(SeoMetadataRules::satisfies(R::SEO_TITLE_OVER_RECOMMENDED, $title, 'Home', 'Acme Events Ltd', [], 60, 70));
        $this->assertTrue(SeoMetadataRules::satisfies(R::SEO_TITLE_OVER_RECOMMENDED, 'Photo Booth Rentals', 'Home', 'Acme Events Ltd', [], 60, 70));
    }

    public function test_a_shortened_proposal_fits_once_composed_and_an_impossible_one_is_null(): void
    {
        $short = SeoTitleProposal::shortened('Photo Booth Rentals for Weddings, Birthdays and Corporate Events', 'Acme Events', 60);
        $this->assertNotNull($short);
        $this->assertLessThanOrEqual(60, mb_strlen(SeoMetadataRules::effectiveTitle($short, $short, 'Acme Events')));
        $this->assertNull(SeoTitleProposal::shortened('Extraordinarilylongwordthatcannotbecutatawordboundaryanywhereatall', 'Acme Events', 20));
    }

    public function test_a_distinct_proposal_is_never_one_that_is_already_taken(): void
    {
        $taken = [SeoMetadataRules::normalize('Photo Booth Rentals | Acme'), SeoMetadataRules::normalize('Photo Booth Rentals')];
        $this->assertNull(SeoTitleProposal::distinct('Photo Booth Rentals', 'Acme', false, 60, $taken));
        $this->assertSame('Photo Booth Rentals | Acme', SeoTitleProposal::distinct('Photo Booth Rentals', 'Acme', false, 60, []));
    }
}
