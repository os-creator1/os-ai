<?php

namespace Tests\Feature\Theme;

use Tests\TestCase;

/**
 * Customer Experience Slice 2 — the shared empty-state foundation
 * (contract §9.2, §17.3; brief §8) delivered through the allowlisted
 * layout surface: `layouts.partials.empty-state`. It answers what the
 * area is, why it is empty or unavailable, what to do next, and who can
 * change it; distinguishes empty / unconfigured / locked with a visible
 * word, not colour alone; escapes every field; and keeps its icon
 * decorative unless given an accessible name.
 */
class EmptyStateFoundationTest extends TestCase
{
    private function render(array $data): string
    {
        return view('layouts.partials.empty-state', $data)->render();
    }

    public function test_it_answers_the_four_questions_with_both_actions(): void
    {
        $html = $this->render([
            'title' => 'No campaigns yet',
            'explanation' => 'Campaigns you send to your contacts appear here.',
            'state' => 'empty',
            'primary' => ['label' => 'Create a campaign', 'url' => '/campaigns/new'],
            'secondary' => ['label' => 'See how campaigns work', 'url' => '/help/campaigns'],
            'ownerHint' => 'Anyone on your team with the Campaigns permission can create one.',
        ]);

        $this->assertStringContainsString('data-role="empty-state"', $html);
        $this->assertStringContainsString('data-state="empty"', $html);
        $this->assertStringContainsString('Nothing here yet', $html);
        $this->assertMatchesRegularExpression('/<h2[^>]*id="empty-state-title"[^>]*>No campaigns yet<\/h2>/', $html);
        $this->assertStringContainsString('Campaigns you send to your contacts appear here.', $html);
        $this->assertMatchesRegularExpression('/<a[^>]+href="\/campaigns\/new"[^>]*data-role="empty-state-primary"[^>]*>Create a campaign<\/a>/', $html);
        $this->assertMatchesRegularExpression('/<a[^>]+href="\/help\/campaigns"[^>]*data-role="empty-state-secondary"[^>]*>See how campaigns work<\/a>/', $html);
        $this->assertStringContainsString('Anyone on your team with the Campaigns permission can create one.', $html);
        $this->assertStringContainsString('aria-labelledby="empty-state-title"', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html, 'The default icon is decorative.');
    }

    public function test_locked_and_unconfigured_states_carry_a_visible_word(): void
    {
        $locked = $this->render(['title' => 'Website', 'state' => 'locked', 'ownerHint' => 'Your account owner can add it to your plan.']);
        $this->assertStringContainsString('data-state="locked"', $locked);
        $this->assertStringContainsString('Not included in your plan', $locked);
        $this->assertStringContainsString('Your account owner can add it to your plan.', $locked);

        $unconfigured = $this->render(['title' => 'Google Business Profile', 'state' => 'unconfigured', 'primary' => ['label' => 'Connect', 'url' => '/gbp/connect']]);
        $this->assertStringContainsString('data-state="unconfigured"', $unconfigured);
        $this->assertStringContainsString('Not set up yet', $unconfigured);
        $this->assertStringContainsString('Connect', $unconfigured);

        $unknown = $this->render(['title' => 'Anything', 'state' => 'weird']);
        $this->assertStringContainsString('data-state="empty"', $unknown, 'An unknown state degrades to empty.');
    }

    public function test_optional_parts_are_omitted_cleanly(): void
    {
        $html = $this->render(['title' => 'No conversations']);

        $this->assertStringContainsString('No conversations', $html);
        $this->assertStringNotContainsString('data-role="empty-state-primary"', $html);
        $this->assertStringNotContainsString('data-role="empty-state-secondary"', $html);
        $this->assertStringNotContainsString('data-role="empty-state-owner"', $html);
        $this->assertStringNotContainsString('customer-empty-state__explanation', $html);
        $this->assertStringNotContainsString('locale.', $html);
    }

    public function test_every_field_is_escaped_and_the_icon_can_be_named(): void
    {
        $html = $this->render([
            'title' => '<script>alert(1)</script>',
            'explanation' => '<img src=x onerror=alert(1)>',
            'ownerHint' => '</section><b>x</b>',
            'primary' => ['label' => '<i>go</i>', 'url' => 'javascript:alert(1)'],
            'iconLabel' => 'Locked feature',
            'state' => 'locked',
        ]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertStringNotContainsString('<i>go</i>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('aria-label="Locked feature"', $html);
        $this->assertStringContainsString('role="img"', $html);
    }
}
