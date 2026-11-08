<?php

namespace Tests\Unit\Seo;

use App\Library\Seo\SeoTitleProposal;
use PHPUnit\Framework\TestCase;

class SeoTitleProposalTest extends TestCase
{
    public function test_a_page_title_and_business_name_make_a_short_proposal(): void
    {
        $this->assertSame('Photo Booth Rentals | Acme', SeoTitleProposal::for('Photo Booth Rentals', 'Acme', false, 60));
    }

    public function test_the_home_page_uses_the_business_name(): void
    {
        $this->assertSame('Acme Events', SeoTitleProposal::for('Home', 'Acme Events', true, 60));
    }

    public function test_an_over_long_proposal_is_cut_to_the_limit_never_beyond_the_editor_maximum(): void
    {
        $title = SeoTitleProposal::for(str_repeat('Wedding photo booth ', 6), 'Acme', false, 200);

        $this->assertLessThanOrEqual(70, mb_strlen($title));
    }

    public function test_nothing_to_build_from_gives_no_proposal(): void
    {
        $this->assertNull(SeoTitleProposal::for('', '', false, 60));
    }
}
