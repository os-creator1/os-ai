<?php

namespace Tests\Unit\Growth;

use App\Enums\Growth\GrowthFactStatus;
use App\Enums\Growth\GrowthRuleOutcomeStatus as S;
use App\Library\Growth\GrowthEvaluationService;
use App\Library\Growth\GrowthFactSet;
use App\Library\Growth\GrowthFactSnapshot;
use App\Library\Growth\GrowthRuleRegistry;
use App\Library\Growth\GrowthThresholds;
use App\Models\Business;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Rule semantics against synthetic fact sets — no database. Each rule's
 * true case, false case, insufficient-data case and unavailable-module case,
 * plus the shared guarantees (closed registry, pure evaluation).
 */
class GrowthRulesTest extends TestCase
{
    private function bucket(int $count, ?int $value = null, string $currency = 'USD'): array
    {
        return [
            'count' => $count,
            'value_minor' => $value ?? 0,
            'currency' => $value === null ? null : $currency,
            'mixed_currency' => false,
            'uids' => array_map(fn ($i) => "uid-{$i}", range(1, max(1, min($count, 10)))),
        ];
    }

    private function empty(): array
    {
        return ['count' => 0, 'value_minor' => 0, 'currency' => null, 'mixed_currency' => false, 'uids' => []];
    }

    /** @param  array<string, GrowthFactSet>  $sets */
    private function snapshot(array $sets): GrowthFactSnapshot
    {
        return new GrowthFactSnapshot(new Business(), CarbonImmutable::parse('2026-10-04 12:00:00'), new GrowthThresholds(), $sets, [1 => 'Main', 2 => 'Second']);
    }

    private function outcome(string $ruleKey, GrowthFactSnapshot $facts)
    {
        return app(GrowthEvaluationService::class)->evaluateRules($facts)[$ruleKey];
    }

    private function crm(array $byLocation, int $open): GrowthFactSet
    {
        return GrowthFactSet::available('crm', ['open_count' => $open, 'truncated' => false, 'by_location' => $byLocation, 'new_leads' => ['current' => 0, 'previous' => 0]]);
    }

    private function crmBuckets(?array $unanswered = null, ?array $stale = null, ?array $high = null): array
    {
        return ['unanswered' => $unanswered ?? $this->empty(), 'stale' => $stale ?? $this->empty(), 'high_value_stale' => $high ?? $this->empty()];
    }

    public function test_registry_is_closed_and_every_key_is_versioned_and_unique(): void
    {
        $keys = array_keys(GrowthRuleRegistry::all());

        $this->assertCount(21, $keys);
        $this->assertSame($keys, array_values(array_unique($keys)));

        foreach (GrowthRuleRegistry::all() as $key => $rule) {
            $this->assertMatchesRegularExpression('/^[a-z_]+\.[a-z0-9_]+:v[0-9]+$/', $key);
            $this->assertSame($key, $rule->definition()->key);
            $this->assertContains($rule->definition()->scope, ['location', 'business']);
            $this->assertNotEmpty($rule->definition()->why);
        }
    }

    public function test_unanswered_leads_true_false_and_insufficient(): void
    {
        $key = 'crm.unanswered_new_leads:v1';

        $hit = $this->outcome($key, $this->snapshot(['crm' => $this->crm([1 => $this->crmBuckets($this->bucket(3, 210000))], 5)]));
        $this->assertSame(S::Finding, $hit->status);
        $this->assertSame(1, $hit->findings[0]->locationId);
        $this->assertSame(210000, $hit->findings[0]->evidence['value_minor']);
        $this->assertSame(5, $hit->findings[0]->impact);

        $clean = $this->outcome($key, $this->snapshot(['crm' => $this->crm([1 => $this->crmBuckets()], 5)]));
        $this->assertSame(S::Passing, $clean->status);

        $none = $this->outcome($key, $this->snapshot(['crm' => $this->crm([], 0)]));
        $this->assertSame(S::Insufficient, $none->status, 'no deals is not "all clear"');
    }

    public function test_no_dollar_value_is_reported_when_the_deals_carry_none(): void
    {
        $hit = $this->outcome('crm.unanswered_new_leads:v1', $this->snapshot(['crm' => $this->crm([1 => $this->crmBuckets($this->bucket(2))], 2)]));

        $this->assertNull($hit->findings[0]->evidence['value_minor']);
        $this->assertNull($hit->findings[0]->evidence['currency']);
    }

    public function test_mixed_currency_buckets_never_report_a_summed_value(): void
    {
        $bucket = $this->bucket(2, 5000) + [];
        $bucket['mixed_currency'] = true;

        $hit = $this->outcome('crm.unanswered_new_leads:v1', $this->snapshot(['crm' => $this->crm([1 => $this->crmBuckets($bucket)], 2)]));

        $this->assertNull($hit->findings[0]->evidence['value_minor']);
    }

    public function test_a_finding_with_no_location_is_business_wide(): void
    {
        $hit = $this->outcome('crm.unanswered_new_leads:v1', $this->snapshot(['crm' => $this->crm([0 => $this->crmBuckets($this->bucket(1))], 1)]));

        $this->assertNull($hit->findings[0]->locationId);
    }

    public function test_one_finding_per_location(): void
    {
        $hit = $this->outcome('crm.unanswered_new_leads:v1', $this->snapshot(['crm' => $this->crm([
            1 => $this->crmBuckets($this->bucket(1)),
            2 => $this->crmBuckets($this->bucket(2)),
        ], 3)]));

        $this->assertSame([1, 2], array_map(fn ($f) => $f->locationId, $hit->findings));
    }

    public function test_stale_and_high_value_stale_are_separate_rules(): void
    {
        $facts = $this->snapshot(['crm' => $this->crm([1 => $this->crmBuckets(null, $this->bucket(2), $this->bucket(1, 120000))], 6)]);

        $this->assertSame(S::Finding, $this->outcome('crm.stale_opportunities:v1', $facts)->status);
        $high = $this->outcome('crm.high_value_stale_opportunities:v1', $facts);
        $this->assertSame(S::Finding, $high->status);
        $this->assertSame(5, $high->findings[0]->impact);
        $this->assertSame(120000, $high->findings[0]->evidence['value_minor']);
    }

    public function test_conversations_awaiting_reply(): void
    {
        $key = 'conversations.inbound_awaiting_reply:v1';
        $set = fn (array $by, int $active) => GrowthFactSet::available('conversations', ['active_count' => $active, 'truncated' => false, 'by_location' => $by]);
        $bucket = $this->bucket(2) + ['oldest_hours' => 40];

        $hit = $this->outcome($key, $this->snapshot(['conversations' => $set([1 => $bucket], 6)]));
        $this->assertSame(S::Finding, $hit->status);
        $this->assertSame(0.8, $hit->findings[0]->confidence, 'a deterministic inference, not a direct fact');

        $this->assertSame(S::Passing, $this->outcome($key, $this->snapshot(['conversations' => $set([], 6)]))->status);
        $this->assertSame(S::Insufficient, $this->outcome($key, $this->snapshot(['conversations' => $set([], 0)]))->status);
    }

    public function test_booking_rules(): void
    {
        $facts = fn (array $d) => $this->snapshot(['booking' => GrowthFactSet::available('booking', $d + ['active_type_count' => 2, 'not_ready' => [], 'capacity' => []])]);

        $notReady = $this->outcome('booking.type_not_ready:v1', $facts(['not_ready' => [1 => ['count' => 1, 'uids' => ['t1']]]]));
        $this->assertSame(S::Finding, $notReady->status);

        $this->assertSame(S::Passing, $this->outcome('booking.type_not_ready:v1', $facts([]))->status);
        $this->assertSame(S::Insufficient, $this->outcome('booking.type_not_ready:v1', $facts(['active_type_count' => 0]))->status);

        $low = $this->outcome('booking.low_near_term_availability:v1', $facts(['capacity' => [1 => ['weekly_minutes' => 600, 'booked_minutes' => 480]]]));
        $this->assertSame(S::Finding, $low->status);
        $this->assertSame(120, $low->findings[0]->evidence['open_minutes']);

        $this->assertSame(S::Passing, $this->outcome('booking.low_near_term_availability:v1', $facts(['capacity' => [1 => ['weekly_minutes' => 2400, 'booked_minutes' => 240]]]))->status);
        $this->assertSame(S::Insufficient, $this->outcome('booking.low_near_term_availability:v1', $facts([]))->status, 'no ready staff capacity means nothing to measure');
    }

    public function test_website_not_published_ignores_businesses_with_an_external_site(): void
    {
        $key = 'website.not_published:v1';
        $site = fn (array $d) => $this->snapshot(['website' => GrowthFactSet::available('website', $d + ['has_external_site' => false, 'exists' => false, 'status' => null, 'published' => false])]);

        $this->assertSame(S::Finding, $this->outcome($key, $site([]))->status);
        $this->assertSame(S::Finding, $this->outcome($key, $site(['exists' => true, 'status' => 'draft']))->status);
        $this->assertSame(S::Passing, $this->outcome($key, $site(['has_external_site' => true]))->status);
        $this->assertSame(S::Passing, $this->outcome($key, $site(['exists' => true, 'status' => 'published', 'published' => true]))->status);
    }

    public function test_seo_keyword_coverage_and_technical_findings(): void
    {
        $seo = fn (array $d) => $this->snapshot(['seo' => GrowthFactSet::available('seo', $d + [
            'keyword_count' => 4, 'coverage_known' => true, 'covered_count' => 3, 'not_covered' => [],
            'audit_ran' => true, 'audit_findings' => ['critical' => 0, 'warning' => 0, 'rules' => []],
        ])]);

        $hit = $this->outcome('seo.keywords_not_covered:v2', $seo(['not_covered_total' => 1, 'not_covered_examples' => ['photo booth chicago']]));
        $this->assertSame(S::Finding, $hit->status);
        $this->assertSame(['photo booth chicago'], $hit->findings[0]->evidence['phrases']);
        $this->assertNull($hit->findings[0]->locationId, 'A site-wide content gap is ONE Business-wide finding.');

        $this->assertSame(S::Insufficient, $this->outcome('seo.keywords_not_covered:v2', $seo(['coverage_known' => false]))->status, 'no published site: coverage cannot be judged');
        $this->assertSame(S::Passing, $this->outcome('seo.keywords_not_covered:v2', $seo(['keyword_count' => 9]))->status);

        $tech = $this->outcome('seo.technical_findings:v1', $seo(['audit_findings' => ['critical' => 1, 'warning' => 2, 'rules' => ['x']]]));
        $this->assertSame(S::Finding, $tech->status);
        $this->assertSame(4, $tech->findings[0]->impact);
        $this->assertSame(S::Insufficient, $this->outcome('seo.technical_findings:v1', $seo(['audit_ran' => false]))->status, 'never audited is not "no issues"');
    }

    public function test_review_rules_are_workflow_only(): void
    {
        $r = fn (array $loc) => $this->snapshot(['reviews' => GrowthFactSet::available('reviews', ['locations' => $loc])]);

        $this->assertSame(S::Finding, $this->outcome('reviews.no_review_link:v1', $r([1 => ['has_link' => false, 'requests_in_window' => 0]]))->status);
        $this->assertSame(S::Passing, $this->outcome('reviews.no_review_link:v1', $r([1 => ['has_link' => true, 'requests_in_window' => 0]]))->status);

        $this->assertSame(S::Finding, $this->outcome('reviews.no_recent_requests:v1', $r([1 => ['has_link' => true, 'requests_in_window' => 0]]))->status);
        $this->assertSame(S::Passing, $this->outcome('reviews.no_recent_requests:v1', $r([1 => ['has_link' => true, 'requests_in_window' => 2]]))->status);
        $this->assertSame(S::Insufficient, $this->outcome('reviews.no_recent_requests:v1', $r([1 => ['has_link' => false, 'requests_in_window' => 0]]))->status, 'no link: the request rule has nothing to judge');
    }

    public function test_citations_never_confuse_not_checked_with_a_mismatch(): void
    {
        $c = fn (array $loc) => $this->snapshot(['citations' => GrowthFactSet::available('citations', ['locations' => $loc])]);
        $untouched = [1 => ['not_checked' => 5, 'needs_attention' => 0, 'directories' => 5]];

        $this->assertSame(S::Finding, $this->outcome('citations.directories_not_checked:v1', $c($untouched))->status);
        $this->assertSame(S::Insufficient, $this->outcome('citations.needs_attention:v1', $c($untouched))->status, 'nothing recorded: no verdict on mismatches');

        $mismatch = [1 => ['not_checked' => 3, 'needs_attention' => 1, 'directories' => 5]];
        $this->assertSame(S::Finding, $this->outcome('citations.needs_attention:v1', $c($mismatch))->status);
    }

    public function test_document_and_payment_rules(): void
    {
        $d = fn (array $b, int $sent = 6) => $this->snapshot(['documents' => GrowthFactSet::available('documents', ['sent_total' => $sent, 'by_location' => [1 => $b + [
            'unsigned' => $this->empty(), 'signed_unpaid' => $this->empty(), 'overdue' => $this->empty(), 'failed_payment' => $this->empty(),
        ]]])]);

        $this->assertSame(S::Finding, $this->outcome('documents.proposal_unsigned:v1', $d(['unsigned' => $this->bucket(1, 50000)]))->status);
        $this->assertSame(S::Finding, $this->outcome('documents.signed_unpaid:v1', $d(['signed_unpaid' => $this->bucket(1, 50000)]))->status);

        $overdue = $this->outcome('payments.overdue_balance:v1', $d(['overdue' => $this->bucket(2, 80000)]));
        $this->assertSame(5, $overdue->findings[0]->impact);

        $failed = $this->outcome('payments.failed_payment:v1', $d(['failed_payment' => $this->bucket(1, 30000)]));
        $this->assertSame(5, $failed->findings[0]->urgency);

        $this->assertSame(S::Passing, $this->outcome('payments.overdue_balance:v1', $d([]))->status);
        $this->assertSame(S::Insufficient, $this->outcome('payments.overdue_balance:v1', $d([], 0))->status, 'nothing ever sent: no basis for "paid on time"');
    }

    public function test_automation_failures_need_repeated_durable_failures(): void
    {
        $a = fn (array $d) => $this->snapshot(['automations' => GrowthFactSet::available('automations', $d + ['window_days' => 7, 'failed_steps' => 0, 'failed_runs' => 0, 'workflows' => 0, 'attempted' => 10])]);

        $this->assertSame(S::Finding, $this->outcome('automations.repeated_failures:v1', $a(['failed_steps' => 2, 'failed_runs' => 1, 'workflows' => 1]))->status);
        $this->assertSame(S::Passing, $this->outcome('automations.repeated_failures:v1', $a(['failed_steps' => 2]))->status, 'two failures is not "repeated"');
        $this->assertSame(S::Insufficient, $this->outcome('automations.repeated_failures:v1', $a(['attempted' => 0]))->status, 'no workflow activity: never infer a broken automation');
    }

    public function test_an_unavailable_domain_is_not_applicable_never_zero(): void
    {
        $facts = $this->snapshot([
            'crm' => GrowthFactSet::withStatus('crm', GrowthFactStatus::NotEntitled),
            'documents' => GrowthFactSet::withStatus('documents', GrowthFactStatus::NotConnected),
        ]);

        $outcomes = app(GrowthEvaluationService::class)->evaluateRules($facts);

        $this->assertSame(S::NotApplicable, $outcomes['crm.unanswered_new_leads:v1']->status);
        $this->assertSame(S::NotApplicable, $outcomes['payments.overdue_balance:v1']->status);
        // A domain nobody supplied (Ads, rank, Search Console on this main) is unavailable too.
        $this->assertSame(S::NotApplicable, $outcomes['website.not_published:v1']->status);
    }

    public function test_headlines_are_built_only_from_evidence_and_never_invent_a_figure(): void
    {
        $rule = GrowthRuleRegistry::find('crm.unanswered_new_leads:v1');

        $this->assertSame(
            '3 new leads have had no reply for 24+ hours — $2,100 in pipeline value.',
            $rule->headline(['count' => 3, 'value_minor' => 210000, 'currency' => 'USD', 'threshold_hours' => 24]),
        );
        $this->assertSame(
            '1 new lead has had no reply for 24+ hours.',
            $rule->headline(['count' => 1, 'value_minor' => null, 'currency' => null, 'threshold_hours' => 24]),
        );
    }

    public function test_thresholds_are_clamped_and_centralised(): void
    {
        config(['growth.thresholds.unanswered_lead_hours' => 0, 'growth.thresholds.stale_deal_days' => 99999, 'growth.thresholds.min_sample' => 'abc']);
        $t = new GrowthThresholds();

        $this->assertSame(1, $t->get('unanswered_lead_hours'));
        $this->assertSame(180, $t->get('stale_deal_days'));
        $this->assertSame(8, $t->get('min_sample'));
        $this->expectException(\InvalidArgumentException::class);
        $t->get('not_a_threshold');
    }
}
