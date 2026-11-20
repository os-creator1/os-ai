<?php

namespace Tests\Unit\Website;

use App\Library\Website\Design\WebsiteDesigns;
use App\Library\Website\GuidedGeneration\TrustFacts;
use PHPUnit\Framework\TestCase;

/** The deterministic choice of homepage trust-strip facts, and where the strip sits. No AI, no invention: only the facts handed in. */
class TrustFactsTest extends TestCase
{
    private function facts(array $differentiators): array
    {
        return ['differentiators' => $differentiators];
    }

    public function test_selection_is_deterministic_verbatim_and_capped_at_four(): void
    {
        $input = ['An on-site attendant', 'Digital sharing for every guest', 'Custom print designs', 'Unlimited prints', 'Setup in about an hour', 'A dedicated planner'];

        $a = TrustFacts::select($this->facts($input));
        $b = TrustFacts::select($this->facts($input));

        $this->assertSame($a, $b);
        $this->assertSame(['An on-site attendant', 'Digital sharing for every guest', 'Custom print designs', 'Unlimited prints'], $a['strip']);
        $this->assertSame(['Setup in about an hour', 'A dedicated planner'], $a['rest']);
    }

    public function test_fewer_than_two_suitable_facts_means_no_strip_and_everything_stays_available(): void
    {
        $this->assertSame(['strip' => [], 'rest' => []], TrustFacts::select(null));
        $this->assertSame(['strip' => [], 'rest' => []], TrustFacts::select($this->facts([])));
        $this->assertSame(['strip' => [], 'rest' => ['Only one']], TrustFacts::select($this->facts(['Only one'])));

        $long = 'a very long confirmed advantage that runs well past the compact limit of one strip item';
        $result = TrustFacts::select($this->facts(['Short fact', $long, 'a second long confirmed advantage that also runs well past the compact limit of one item']));
        $this->assertSame([], $result['strip'], 'one short fact is not a strip');
        $this->assertCount(3, $result['rest'], 'the long ones are kept whole for the panels');
    }

    public function test_the_same_advantage_said_twice_is_one_fact(): void
    {
        $result = TrustFacts::select($this->facts(['Free setup.', 'free setup', 'Free setup and delivery', 'Instant prints', '  Instant   prints!  ', 'ab', '', '   ']));

        $this->assertSame(['Free setup.', 'Instant prints'], $result['strip']);
        $this->assertSame([], $result['rest']);
    }

    public function test_non_text_entries_are_ignored(): void
    {
        $result = TrustFacts::select($this->facts(['Real fact one', 42, null, ['x'], 'Real fact two']));

        $this->assertSame(['Real fact one', 'Real fact two'], $result['strip']);
    }

    public function test_every_template_places_the_strip_directly_under_the_hero(): void
    {
        foreach (['photo_booth_modern', 'photo_booth_editorial', 'photo_booth_luxury', 'photo_booth_conversion'] as $key) {
            $design = WebsiteDesigns::resolve($key, []);
            $sections = [
                ['type' => 'faq', 'data' => []], ['type' => 'services', 'data' => []], ['type' => 'trust_strip', 'data' => ['facts' => ['a b c', 'd e f']]],
                ['type' => 'hero', 'data' => []], ['type' => 'cta', 'data' => []], ['type' => 'text', 'data' => []],
            ];

            $types = array_column($design->orderHomeSections($sections), 'type');

            $this->assertSame('hero', $types[0], $key);
            $this->assertSame('trust_strip', $types[1], "{$key}: directly under the hero");
            $this->assertSame(1, count(array_keys($types, 'trust_strip', true)), $key);
        }
    }
}
