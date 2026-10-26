<?php

namespace Tests\Unit\Website;

use App\Library\Website\EventPageUsefulness;
use Tests\TestCase;

class EventPageUsefulnessTest extends TestCase
{
    public function test_an_event_page_needs_the_owners_own_words_and_the_unchanged_example_does_not_count(): void
    {
        $this->assertFalse(EventPageUsefulness::assess('Weddings', null)['qualifies']);
        $this->assertFalse(EventPageUsefulness::assess('Weddings', '   ')['qualifies']);

        $example = EventPageUsefulness::assess('Weddings', 'Add a fun, memorable moment to your wedding — a way for guests to celebrate with you and keep something special from the day.');
        $this->assertFalse($example['qualifies'], 'The prewritten example sentence is not something the owner confirmed.');
        $this->assertStringContainsString('example sentence', $example['reason']);
        $this->assertStringContainsString('Describe in a few sentences what you do for Weddings', $example['reason']);
        $this->assertTrue(EventPageUsefulness::isExample('ADD A FUN memorable moment to your wedding a way for guests to celebrate with you and keep something special from the day'), 'Case and punctuation do not disguise the example.');

        $this->assertTrue(EventPageUsefulness::assess('Weddings', 'Two booths side by side for receptions, set up before the first dance.')['qualifies']);
        $this->assertTrue(EventPageUsefulness::assess('Weddings', "- Unlimited prints\n- Guest book")['qualifies'], 'A list of what is included, in the owner\'s words, is enough.');
        $this->assertTrue(EventPageUsefulness::assess('Weddings', 'Add a fun, memorable moment to your wedding — a way for guests to celebrate with you and keep something special from the day. We also bring a guest book.')['qualifies'], 'Edited text is the owner\'s.');
    }

    public function test_the_address_and_label_come_from_the_saved_name_with_clean_descriptive_words(): void
    {
        $this->assertSame('wedding-photo-booth-rental', EventPageUsefulness::slug('Weddings'));
        $this->assertSame('corporate-photo-booth-rental', EventPageUsefulness::slug('Corporate Events'));
        $this->assertSame('party-photo-booth-rental', EventPageUsefulness::slug('Parties'));
        $this->assertSame('birthday-party-photo-booth-rental', EventPageUsefulness::slug('Birthday Parties'));
        $this->assertSame('brand-activation-photo-booth-rental', EventPageUsefulness::slug('Brand Activations'));
        $this->assertSame('Corporate', EventPageUsefulness::label('Corporate Events'));
        $this->assertNull(EventPageUsefulness::slug('Events'), 'A name that is only generic words has nothing to build an address from.');
        $this->assertSame(['wedding'], EventPageUsefulness::words('Weddings'));
    }
}
