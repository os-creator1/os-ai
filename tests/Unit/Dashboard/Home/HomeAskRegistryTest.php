<?php

namespace Tests\Unit\Dashboard\Home;

use App\Library\Dashboard\Home\HomeAskRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Home V2 §F — Ask MotionGrove resolves only against a closed registry, and
 * never pretends an unsupported or unavailable request was carried out.
 */
class HomeAskRegistryTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?string}> */
    public static function phrases(): array
    {
        return [
            'follow up' => ['Follow up with leads', 'follow_up'],
            'follow-up hyphen' => ['please do my follow-up now', 'follow_up'],
            'booking type' => ['Create a booking type', 'booking_type'],
            'book appointment' => ['book an appointment for Sam', 'appointment'],
            'upcoming' => ['Show upcoming appointments', 'upcoming'],
            'ads' => ['Improve my ads', 'ads'],
            'website' => ['Update my website', 'website'],
            'contact' => ['add a new contact', 'contact'],
            'explain' => ["Explain this month's results", 'explain'],
            'unknown' => ['order me a pizza', null],
            'empty' => ['   ', null],
        ];
    }

    #[DataProvider('phrases')]
    public function test_it_matches_the_supported_phrases(string $phrase, ?string $expected): void
    {
        $this->assertSame($expected, (new HomeAskRegistry())->match($phrase));
    }

    public function test_an_unknown_request_is_an_explicit_limitation_not_an_execution(): void
    {
        $r = (new HomeAskRegistry())->resolve(null, [], null);

        $this->assertSame('unsupported', $r['status']);
        $this->assertNull($r['drawerUrl']);
        $this->assertNull($r['url']);
        $this->assertStringContainsString("can't do that", $r['title']);
    }

    public function test_an_intent_the_actor_may_not_use_is_refused_plainly(): void
    {
        $r = (new HomeAskRegistry())->resolve('follow_up', ['follow-ups' => null], null);

        $this->assertSame('unsupported', $r['status']);
        $this->assertStringContainsString('not available', $r['title']);
    }

    public function test_an_available_drawer_opens_a_drawer_and_a_pageonly_action_links(): void
    {
        $actions = [
            'follow-ups' => ['drawer' => 'follow-ups', 'drawerUrl' => '/d', 'fullUrl' => '/full', 'label' => 'Review follow-ups'],
            'website' => ['drawer' => null, 'drawerUrl' => null, 'fullUrl' => '/site', 'label' => 'Update your website'],
        ];
        $registry = new HomeAskRegistry();

        $this->assertSame('drawer', $registry->resolve('follow_up', $actions, null)['status']);
        $this->assertSame('/d', $registry->resolve('follow_up', $actions, null)['drawerUrl']);
        $link = $registry->resolve('website', $actions, null);
        $this->assertSame('link', $link['status']);
        $this->assertSame('/site', $link['url']);
    }

    public function test_the_ai_capable_explain_path_is_an_explicit_confirmed_post_and_only_when_offered(): void
    {
        $registry = new HomeAskRegistry();

        $this->assertSame('unsupported', $registry->resolve('explain', [], null)['status']);

        $post = $registry->resolve('explain', [], '/advisor/ask');
        $this->assertSame('post', $post['status']);
        $this->assertSame('/advisor/ask', $post['url']);
        $this->assertSame(['question' => 'what_changed'], $post['post']);
    }
}
