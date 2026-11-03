<?php

namespace Tests\Unit\Website;

use App\Library\Website\LocalAreaUsefulness;
use App\Library\Website\Seo\WebsiteSeoText;
use Tests\TestCase;

class WebsiteSeoTextAndAreaUsefulnessTest extends TestCase
{
    public function test_text_that_fits_is_left_alone_and_longer_text_ends_on_a_whole_sentence_or_word_never_an_ellipsis(): void
    {
        $this->assertSame('Short and finished.', WebsiteSeoText::fit('  Short   and finished. ', 160));

        $text = 'Glow Booths offers photo booth rentals in Irvine. Choose from the Open Air Booth, the Mirror Booth and the Glam Booth. Request a quote for your date.';
        $fitted = WebsiteSeoText::fit($text, 120);
        $this->assertSame('Glow Booths offers photo booth rentals in Irvine. Choose from the Open Air Booth, the Mirror Booth and the Glam Booth.', $fitted);
        $this->assertLessThanOrEqual(120, mb_strlen($fitted));

        $title = WebsiteSeoText::fit('Wedding Photo Booth Rental for Receptions and Private Celebrations in Spokane and', 70);
        $this->assertLessThanOrEqual(70, mb_strlen($title));
        $this->assertDoesNotMatchRegularExpression('/\b(and|in|for|of)$/i', $title, 'No dangling joiner.');
        $this->assertStringNotContainsString('…', $title);
        $this->assertStringNotContainsString('...', $title);
    }

    public function test_a_stored_description_that_visibly_stops_mid_thought_is_recognised(): void
    {
        $this->assertTrue(WebsiteSeoText::looksCutOff('luxury photo booth rentals throughout Liberty...'));
        $this->assertTrue(WebsiteSeoText::looksCutOff('luxury photo booth rentals throughout Liberty…'));
        $this->assertFalse(WebsiteSeoText::looksCutOff('A finished sentence.'));
        $this->assertFalse(WebsiteSeoText::looksCutOff(''));
    }

    public function test_an_area_is_a_page_only_with_a_confirmed_local_fact_and_never_for_the_base_city(): void
    {
        $bare = LocalAreaUsefulness::normalize('Cheney, WA');
        $assessment = LocalAreaUsefulness::assess($bare, 'Spokane');
        $this->assertFalse($assessment['qualifies']);
        $this->assertStringContainsString('doorway', $assessment['reason']);

        $withVenue = LocalAreaUsefulness::normalize(['name' => 'Cheney', 'details' => ['venues' => ['<i>EWU Pavilion</i>', ' ']]]);
        $this->assertSame(['venues' => ['EWU Pavilion']], $withVenue['details']);
        $this->assertSame(['venues'], LocalAreaUsefulness::assess($withVenue, 'Spokane')['signals']);
        $this->assertTrue(LocalAreaUsefulness::assess($withVenue, 'Spokane')['qualifies']);

        $base = LocalAreaUsefulness::normalize(['name' => 'Spokane', 'details' => ['logistics' => 'We are based here.']]);
        $this->assertFalse(LocalAreaUsefulness::assess($base, 'spokane')['qualifies'], 'The home page already owns the base city.');

        $this->assertNull(LocalAreaUsefulness::normalize('   '));
        $this->assertSame([], LocalAreaUsefulness::normalize(['name' => 'X', 'details' => ['photos' => ['not-a-uid']]])['details']);
    }
}
