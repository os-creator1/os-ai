<?php

namespace Tests\Unit\Growth;

use App\Enums\Opportunity\OpportunityFreshness;
use App\Enums\Opportunity\OpportunityStatus;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Library\Growth\GrowthMoney;
use App\Library\Growth\GrowthOpportunityPresenter;
use App\Models\Opportunity;
use Tests\TestCase;

/**
 * Owner-facing wording: honest labels, no pseudo-precision, no invented money.
 */
class GrowthPresenterTest extends TestCase
{
    private function opportunity(array $overrides = []): Opportunity
    {
        $o = new Opportunity(array_merge([
            'worker_key' => OpportunityWorkerKey::Sales,
            'type' => 'crm.unanswered_new_leads:v1',
            'title' => 'New leads are waiting for a first reply',
            'summary' => 'x',
            'impact' => 4,
            'urgency' => 4,
            'effort' => 1,
            'confidence' => '1.00',
            'priority_score' => 80,
            'evidence' => [[
                'summary' => 'Open deals marked "No contact".', 'retrieved_at' => now()->toIso8601String(),
                'observed_value' => ['count' => 3, 'value_minor' => 210000, 'currency' => 'USD', 'uids' => ['a', 'b', 'c'], 'threshold_hours' => 24],
            ]],
            'first_detected_at' => now()->subDays(2),
            'occurrence_number' => 1,
        ], $overrides));
        $o->uid = 'uid-1';
        $o->status = OpportunityStatus::Open;
        $o->freshness = OpportunityFreshness::Current;
        $o->location_id = null;

        return $o;
    }

    private function present(Opportunity $o): array
    {
        return app(GrowthOpportunityPresenter::class)->present($o, [], 'w-uid', 'b-uid');
    }

    public function test_impact_words_are_high_medium_low(): void
    {
        $p = app(GrowthOpportunityPresenter::class);

        $this->assertSame('High', $p->impactLevel(5));
        $this->assertSame('High', $p->impactLevel(4));
        $this->assertSame('Medium', $p->impactLevel(3));
        $this->assertSame('Low', $p->impactLevel(2));
        $this->assertSame('Low', $p->impactLevel(0));
    }

    public function test_confidence_is_three_honest_labels_never_a_percentage(): void
    {
        $p = app(GrowthOpportunityPresenter::class);

        $this->assertSame('High confidence', $p->confidenceLabel(1.0));
        $this->assertSame('Moderate confidence', $p->confidenceLabel(0.8));
        $this->assertSame('Limited data', $p->confidenceLabel(0.6));
        $this->assertSame('Limited data', $p->confidenceLabel(0.0));
    }

    public function test_the_card_is_rendered_only_from_stored_evidence(): void
    {
        $card = $this->present($this->opportunity());

        $this->assertSame('3 new leads have had no reply for 24+ hours — $2,100 in pipeline value.', $card['headline']);
        $this->assertSame('High', $card['impact']);
        $this->assertSame('High confidence', $card['confidence']);
        $this->assertSame('Lead response', $card['category_label']);
        $this->assertSame('$2,100', $card['evidence']['value']);
        $this->assertSame(3, $card['evidence']['count']);
        $this->assertSame('open', $card['state']);
        $this->assertNotEmpty($card['why']);
        $this->assertNotEmpty($card['expected']);
        $this->assertSame('View leads', $card['action_label']);
        $this->assertSame('read_only', $card['safety_class']);
        $this->assertStringEndsWith('/growth/opportunities/uid-1', $card['detail_url']);
    }

    public function test_no_value_in_the_evidence_means_no_dollar_figure_on_the_card(): void
    {
        $o = $this->opportunity();
        $o->evidence = [[
            'summary' => 's', 'retrieved_at' => now()->toIso8601String(),
            'observed_value' => ['count' => 2, 'value_minor' => null, 'currency' => null, 'uids' => [], 'threshold_hours' => 24],
        ]];

        $card = $this->present($o);

        $this->assertArrayNotHasKey('value', $card['evidence']);
        $this->assertStringNotContainsString('$', $card['headline']);
    }

    public function test_resolved_dismissed_snoozed_and_in_progress_map_to_owner_states(): void
    {
        $p = app(GrowthOpportunityPresenter::class);
        $make = function (OpportunityStatus $status, OpportunityFreshness $freshness) {
            $o = $this->opportunity();
            $o->status = $status;
            $o->freshness = $freshness;

            return $o;
        };

        $this->assertSame('resolved', $p->state($make(OpportunityStatus::Open, OpportunityFreshness::Stale)), 'no longer detected');
        $this->assertSame('resolved', $p->state($make(OpportunityStatus::Completed, OpportunityFreshness::Current)));
        $this->assertSame('dismissed', $p->state($make(OpportunityStatus::Dismissed, OpportunityFreshness::Current)));
        $this->assertSame('snoozed', $p->state($make(OpportunityStatus::Snoozed, OpportunityFreshness::Current)));
        $this->assertSame('in_progress', $p->state($make(OpportunityStatus::InProgress, OpportunityFreshness::Current)));
        $this->assertSame('open', $p->state($make(OpportunityStatus::Open, OpportunityFreshness::Current)));
    }

    public function test_a_resolved_headline_reads_as_history_not_a_live_claim(): void
    {
        $o = $this->opportunity();
        $o->freshness = OpportunityFreshness::Stale;

        $this->assertStringStartsWith('Earlier: 3 new leads', $this->present($o)['headline']);
    }

    public function test_money_formatting_never_invents_a_figure(): void
    {
        $this->assertSame('$2,100', GrowthMoney::format(210000, 'USD'));
        $this->assertSame('$7.50', GrowthMoney::format(750, 'USD'));
        $this->assertNull(GrowthMoney::format(null, 'USD'));
        $this->assertNull(GrowthMoney::format(500, null));
        $this->assertSame('1 lead', GrowthMoney::plural(1, 'lead'));
        $this->assertSame('3 leads', GrowthMoney::plural(3, 'lead'));
    }
}
