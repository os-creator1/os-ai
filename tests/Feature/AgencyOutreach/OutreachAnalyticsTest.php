<?php

namespace Tests\Feature\AgencyOutreach;

use App\Library\AgencyOutreach\Analytics\AnalyticsFilters;
use App\Library\AgencyOutreach\Analytics\AnalyticsPresenter;
use App\Library\AgencyOutreach\Analytics\OutreachAnalytics;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectingSetting;
use App\Models\AgencyProspectMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Feature\AgencyOutreach\Concerns\CreatesOutreachUiFixtures;
use Tests\TestCase;

class OutreachAnalyticsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOutreachUiFixtures;

    private \Illuminate\Support\Carbon $t0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t0 = now()->subDays(2)->startOfHour();
    }

    private function msg(AgencyProspectCampaignMember $m, string $dir, \DateTimeInterface $at, array $extra = []): AgencyProspectMessage
    {
        return AgencyProspectMessage::create(array_merge([
            'workspace_id' => $m->workspace_id, 'campaign_member_id' => $m->id, 'direction' => $dir,
            'status' => $dir === 'inbound' ? 'received' : 'sent', 'body' => $dir === 'inbound' ? 'reply' : 'text',
            $dir === 'inbound' ? 'received_at' : 'sent_at' => $at,
        ], $extra));
    }

    private function prospect(array $a, string $name, string $phone, array $attrs = [], ?AgencyProspectCampaign $campaign = null): AgencyProspectCampaignMember
    {
        $p = AgencyProspect::create(array_merge(['workspace_id' => $a['workspace']->id, 'company_name' => $name, 'phone' => $phone, 'status' => 'active'], $attrs));

        return AgencyProspectCampaignMember::create(['workspace_id' => $a['workspace']->id, 'campaign_id' => ($campaign ?? $a['campaign'])->id, 'prospect_id' => $p->id, 'enrolled_at' => $this->t0]);
    }

    /** @return array{a: array, m1: AgencyProspectCampaignMember, m2: AgencyProspectCampaignMember, m3: AgencyProspectCampaignMember, m4: AgencyProspectCampaignMember} */
    private function world(string $name = 'Ana'): array
    {
        $a = $this->outreachAgency($name, ['enrolled' => false]);
        $a['campaign']->update(['status' => 'active']);
        $t = $this->t0;

        // P1: opener, replied in 30 min, step s-a sent, replied after s-a in 80 min, step s-b sent, then booked.
        $m1 = $this->prospect($a, 'Snap Happy', '17206640969', ['business_type' => 'Photo booth', 'google_rating' => 4.9, 'review_count' => 87, 'has_website' => true, 'state' => 'CO', 'status' => 'booked', 'booked_at' => $t->copy()->addHours(5)]);
        $m1->update(['stage' => 6]);
        $this->msg($m1, 'outbound', $t, ['purpose' => 'initial', 'body' => 'Hi Snap, opener v1', 'script_version' => 1]);
        $this->msg($m1, 'inbound', $t->copy()->addMinutes(30));
        $this->msg($m1, 'outbound', $t->copy()->addMinutes(40), ['purpose' => 'ai_reply', 'sequence_step_id' => 's-a', 'body' => 'Step A text', 'script_version' => 1]);
        $this->msg($m1, 'inbound', $t->copy()->addHours(2));
        $this->msg($m1, 'outbound', $t->copy()->addHours(3), ['purpose' => 'ai_reply', 'sequence_step_id' => 's-b', 'body' => 'Step B text', 'script_version' => 1]);

        // P2: opener, no reply; later a hand-written text and a reply to THAT (unattributed to any step).
        $m2 = $this->prospect($a, 'Glow Booth', '17205550101', ['business_type' => 'Photo booth', 'google_rating' => 4.2, 'review_count' => 12, 'has_website' => false, 'state' => 'CO']);
        $this->msg($m2, 'outbound', $t, ['purpose' => 'initial', 'body' => 'Hi Glow, opener v1', 'script_version' => 1]);
        $this->msg($m2, 'outbound', $t->copy()->addHours(5), ['source' => 'manual', 'body' => 'Manual hello']);
        $this->msg($m2, 'inbound', $t->copy()->addHours(6));

        // P3: opener, rejected.
        $m3 = $this->prospect($a, 'Mile High', '13035550177', ['business_type' => 'Party equipment', 'google_rating' => 4.4, 'review_count' => 30, 'status' => 'stopped', 'stop_reason' => 'rejected', 'stopped_at' => $t->copy()->addHour()]);
        $m3->update(['stage' => 99]);
        $this->msg($m3, 'outbound', $t, ['purpose' => 'initial', 'body' => 'Hi Mile, opener v1', 'script_version' => 1]);
        $this->msg($m3, 'inbound', $t->copy()->addHour());

        // P4: never contacted.
        $m4 = $this->prospect($a, 'Flash Frame', '17205550444', ['business_type' => 'Photography']);

        return compact('a', 'm1', 'm2', 'm3', 'm4');
    }

    private function analytics(array $a, array $query = []): OutreachAnalytics
    {
        return new OutreachAnalytics($a['workspace'], AnalyticsFilters::fromRequest(Request::create('/', 'GET', $query)));
    }

    private function step(array $steps, string $key): array
    {
        foreach ($steps as $s) {
            if ($s['step_key'] === $key) {
                return $s;
            }
        }
        $this->fail("step {$key} missing");
    }

    public function test_kpis_denominators_and_unique_counting(): void
    {
        $w = $this->world();
        $k = $this->analytics($w['a'])->kpis();

        $this->assertSame(4, $k['imported']);
        $this->assertSame(3, $k['eligible'], 'a stopped prospect is not eligible');
        $this->assertSame(3, $k['contacted']);
        $this->assertSame(3, $k['replied']);
        $this->assertSame(1, $k['rejected']);
        $this->assertSame(1, $k['booked']);
        $this->assertSame(1, $k['interested'], 'a booked call is interested; it is not auto-promoted to a won client');
        $this->assertSame(0, $k['won']);
        $this->assertSame(3600, $k['median_reply_sec']);
        $this->assertSame(4800, $k['p75_reply_sec']);

        // The same business reached by a second campaign is still ONE contacted prospect.
        $second = AgencyProspectCampaign::create(['workspace_id' => $w['a']['workspace']->id, 'name' => 'Second', 'status' => 'active', 'sending_mode' => 'managed', 'opening_message' => 'Hi']);
        $dup = AgencyProspectCampaignMember::create(['workspace_id' => $w['a']['workspace']->id, 'campaign_id' => $second->id, 'prospect_id' => $w['m1']->prospect_id, 'enrolled_at' => $this->t0]);
        $this->msg($dup, 'outbound', $this->t0->copy()->addDay(), ['purpose' => 'initial', 'body' => 'second opener']);

        $an = $this->analytics($w['a']);
        $this->assertSame(3, $an->kpis()['contacted']);
        $opener = $this->step($an->steps(), 'opener');
        $this->assertSame(4, $opener['reached'], 'conversations');
        $this->assertSame(3, $opener['prospects'], 'distinct businesses');

        // Marking the client as won: counted once, workspace-wide.
        AgencyProspect::whereKey($w['m1']->prospect_id)->update(['client_won_at' => $this->t0->copy()->addDays(1)]);
        $this->assertSame(1, $this->analytics($w['a'])->kpis()['won']);
    }

    public function test_reply_attribution_for_default_steps_and_custom_sequences(): void
    {
        $w = $this->world();
        $steps = $this->analytics($w['a'])->steps();

        $opener = $this->step($steps, 'opener');
        $this->assertSame(3, $opener['reached']);
        $this->assertSame(2, $opener['replied'], 'P1 and P3 replied directly after the opener; P2 replied to a manual text');
        $this->assertSame(1, $opener['rejected']);
        $this->assertSame(1, $opener['interested']);
        $this->assertSame(1, $opener['no_response'], 'no reply is "no response yet", never a rejection');
        $this->assertSame(1, $opener['advanced']);

        $a = $this->step($steps, 's-a');
        $this->assertSame([1, 1, 1], [$a['reached'], $a['replied'], $a['interested']]);
        $this->assertSame(4800, $a['median_sec'], 'latency = confirmed send to inbound timestamp (T0+40m → T0+2h)');

        $b = $this->step($steps, 's-b');
        $this->assertSame([1, 0, 1, 1], [$b['reached'], $b['replied'], $b['no_response'], $b['booked']], 'booking attributed to the step sent before it');

        // A custom sequence with a reordered/disabled step and one deleted from the script: history is kept.
        AgencyProspectingSetting::where('workspace_id', $w['a']['workspace']->id)->update(['sequence_steps' => [
            ['id' => 's-a', 'body' => 'Edited A', 'advance_on' => 'reply', 'delay_hours' => null, 'enabled' => false],
        ]]);
        $labelled = AnalyticsPresenter::steps($w['a']['workspace']->refresh(), $this->analytics($w['a'])->steps());
        $byKey = collect($labelled)->keyBy('step_key');

        $this->assertSame('First message', $byKey['opener']['label']);
        $this->assertSame('Follow-up 1', $byKey['s-a']['label']);
        $this->assertSame('disabled', $byKey['s-a']['state']);
        $this->assertSame('removed', $byKey['s-b']['state']);
        $this->assertSame(1, $byKey['s-b']['reached'], 'a deleted step keeps its history');
    }

    public function test_edited_scripts_never_rewrite_the_text_that_was_sent(): void
    {
        $w = $this->world();
        AgencyProspectingSetting::where('workspace_id', $w['a']['workspace']->id)->update(['message_1' => 'Totally new opener', 'script_version' => 9]);

        $texts = $this->analytics($w['a'])->stepTexts('opener');
        $this->assertCount(3, $texts, 'three personalised openers, as sent');
        $this->assertContains('Hi Snap, opener v1', array_column($texts, 'body'));
        $this->assertSame([1], array_values(array_unique(array_map(fn ($t) => (int) $t['script_version'], $texts))));
        $this->assertStringNotContainsString('Totally new opener', json_encode($texts));
    }

    public function test_replies_are_never_credited_to_an_earlier_step_after_a_later_message(): void
    {
        $w = $this->world();
        // A late inbound after s-b is attributed to s-b, not to the opener or s-a.
        $this->msg($w['m1'], 'inbound', $this->t0->copy()->addHours(4));
        $steps = $this->analytics($w['a'])->steps();

        $this->assertSame(2, $this->step($steps, 'opener')['replied']);
        $this->assertSame(1, $this->step($steps, 's-a')['replied']);
        $this->assertSame(1, $this->step($steps, 's-b')['replied']);

        // An inbound with no earlier confirmed outbound is unattributed.
        $m5 = $this->prospect($w['a'], 'Cold Replier', '17205550555');
        $this->msg($m5, 'inbound', $this->t0);
        $this->assertSame(2, $this->step($this->analytics($w['a'])->steps(), 'opener')['replied']);
    }

    public function test_queued_or_failed_messages_are_never_counted_as_sent(): void
    {
        $w = $this->world();
        $m5 = $this->prospect($w['a'], 'Queued Co', '17205550666');
        $this->msg($m5, 'outbound', $this->t0, ['status' => 'paused', 'purpose' => 'initial']);
        $this->msg($m5, 'outbound', $this->t0, ['status' => 'failed', 'purpose' => 'initial']);
        $this->msg($m5, 'outbound', $this->t0, ['status' => 'pending', 'purpose' => 'initial']);

        $an = $this->analytics($w['a']);
        $this->assertSame(3, $this->step($an->steps(), 'opener')['reached']);
        $this->assertSame(3, $an->kpis()['contacted']);
    }

    public function test_kpis_reconcile_with_explorer_and_step_drilldown_under_the_same_filters(): void
    {
        $w = $this->world();

        foreach ([[], ['type' => 'Photo booth'], ['website' => 'yes'], ['rating' => '4.5+'], ['reviews' => '50+'], ['geo' => 'CO'], ['period' => 'all']] as $query) {
            $an = $this->analytics($w['a'], $query);
            $k = $an->kpis();

            foreach (['contacted', 'replied', 'interested', 'rejected', 'opted_out', 'booked', 'won'] as $stage) {
                $this->assertSame($k[$stage], $an->explorer(1, 25, $stage)['total'], 'KPI ' . $stage . ' must equal its explorer list under ' . json_encode($query));
            }

            foreach ($an->steps() as $s) {
                $list = $an->stepProspects($s['step_key'], 'all', 1);
                $this->assertSame($s['reached'], $list['total'], 'step card = drill-down total for ' . $s['step_key']);
                $this->assertSame($s['replied'], $an->stepProspects($s['step_key'], 'replied', 1)['total']);
                $this->assertSame($s['rejected'], $an->stepProspects($s['step_key'], 'rejected', 1)['total']);
                $this->assertSame($s['no_response'], $an->stepProspects($s['step_key'], 'no_response', 1)['total']);
            }
        }

        $this->assertSame(2, $this->analytics($w['a'], ['type' => 'Photo booth'])->kpis()['contacted']);
        $this->assertSame(1, $this->analytics($w['a'], ['website' => 'no'])->kpis()['contacted']);
    }

    public function test_period_filter_uses_first_contact_and_custom_ranges(): void
    {
        $w = $this->world();
        $this->assertSame(3, $this->analytics($w['a'], ['period' => '7'])->kpis()['contacted']);
        $this->assertSame(0, $this->analytics($w['a'], ['period' => 'custom', 'from' => now()->subDay()->toDateString(), 'to' => now()->toDateString()])->kpis()['contacted']);
        $this->assertSame(3, $this->analytics($w['a'], ['period' => 'custom', 'from' => now()->subDays(3)->toDateString(), 'to' => now()->toDateString()])->kpis()['contacted']);
    }

    public function test_segments_flag_small_samples_and_filters_for_channel_and_script_apply(): void
    {
        $w = $this->world();
        $seg = collect($this->analytics($w['a'])->segments('type'))->keyBy('segment');

        $this->assertSame(2, $seg['Photo booth']['n']);
        $this->assertTrue($seg['Photo booth']['small'], 'a tiny sample is "too few to tell", never a winner');
        $this->assertSame(1, $seg['Party equipment']['rejected']);

        $this->assertSame(3, $this->analytics($w['a'], ['script' => '1'])->kpis()['contacted']);
        $this->assertSame(0, $this->analytics($w['a'], ['script' => '7'])->kpis()['contacted']);
    }

    public function test_analytics_are_isolated_to_the_workspace(): void
    {
        $w = $this->world('Iso');
        $other = $this->outreachAgency('Other', ['enrolled' => false]);
        $other['campaign']->update(['status' => 'active']);
        $om = $this->prospect($other, 'Other Co', '17205559999');
        $this->msg($om, 'outbound', $this->t0, ['purpose' => 'initial']);
        $this->msg($om, 'inbound', $this->t0->copy()->addMinutes(5));

        $this->assertSame(3, $this->analytics($w['a'])->kpis()['contacted']);
        $this->assertSame(1, $this->analytics($other)->kpis()['contacted']);
        // A foreign campaign uid in the filter matches nothing instead of widening the scope.
        $this->assertSame(0, $this->analytics($w['a'], ['campaign' => $other['campaign']->uid])->kpis()['contacted']);
        $this->assertSame(0, $this->analytics($w['a'], ['campaign' => $other['campaign']->uid])->explorer(1)['total']);
    }

    public function test_timing_reports_the_recorded_evidence(): void
    {
        $w = $this->world();
        $ai = $this->msg($w['m3'], 'outbound', $this->t0->copy()->addHours(2), ['purpose' => 'ai_reply', 'reply_due_at' => $this->t0->copy()->addHours(2)->subMinutes(2)]);
        \Illuminate\Support\Facades\DB::table('agency_prospect_messages')->where('id', $ai->id)->update(['created_at' => $this->t0->copy()->addHours(2)->subMinutes(5)]);

        $t = $this->analytics($w['a'])->timing();
        $this->assertSame(3600, $t['overall']['p50']);
        $this->assertSame(1, $t['ai']['paced']);
        $this->assertSame(180, $t['ai']['avg_planned_wait']);
        $this->assertSame(120, $t['ai']['avg_send_lag']);
        $this->assertSame(1, $t['manual']['count']);
        $this->assertNotEmpty($t['distribution']);
        $this->assertNotEmpty($t['hours']);
    }

    public function test_every_analytics_screen_renders_in_the_real_app(): void
    {
        $w = $this->world('Render');
        $a = $w['a'];
        $this->authenticateAs($a['customer']);

        foreach (['performance', 'messages', 'types', 'timing', 'explorer'] as $tab) {
            $r = $this->get($this->outreachRoute('analytics.index', $a['workspace']) . '?tab=' . $tab)->assertOk();
            $html = $r->getContent();
            $this->assertStringNotContainsString('content-header-title', $html, "single heading on analytics/{$tab}");
            $this->assertStringContainsString('data-role="analytics-filters"', $html);
        }

        $this->get($this->outreachRoute('analytics.index', $a['workspace']))
            ->assertOk()->assertSee('data-role="analytics-kpis"', false)->assertSee('Where prospects drop off')->assertSee('First message');

        AgencyProspectingSetting::where('workspace_id', $a['workspace']->id)->update(['sequence_steps' => [
            ['id' => 's-a', 'body' => 'Edited A', 'advance_on' => 'reply', 'delay_hours' => null, 'enabled' => true],
            ['id' => 's-b', 'body' => 'Edited B', 'advance_on' => 'time', 'delay_hours' => 48, 'enabled' => true],
        ]]);
        $this->get($this->outreachRoute('analytics.step', $a['workspace'], ['s-b']))->assertOk()->assertSee('Follow-up 2')->assertSee('2 days');
        $this->get($this->outreachRoute('analytics.step', $a['workspace'], ['s-a']))
            ->assertOk()->assertSee('Follow-up 1')->assertSee('script?step=s-a', false)->assertSee('Edit this message')->assertSee('Step A text')->assertSee('Snap Happy');
        $this->get($this->outreachRoute('analytics.step', $a['workspace'], ['opener']) . '?tab=rejected')->assertOk()->assertSee('Mile High')->assertDontSee('Snap Happy');
        $this->get($this->outreachRoute('analytics.step', $a['workspace'], ['nonexistent']))->assertNotFound();

        $this->get($this->outreachRoute('analytics.index', $a['workspace']) . '?tab=explorer&reached=rejected')
            ->assertOk()->assertSee('Mile High')->assertDontSee('Snap Happy');

        $csv = $this->get($this->outreachRoute('analytics.export', $a['workspace']) . '?period=all')->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
        $this->assertStringContainsString('Snap Happy', $csv->streamedContent());

        // Another workspace's analytics are not reachable.
        $b = $this->outreachAgency('Nosy');
        $this->authenticateAs($b['customer']);
        $this->get($this->outreachRoute('analytics.index', $a['workspace']))->assertNotFound();
        $this->get($this->outreachRoute('analytics.step', $b['workspace'], ['s-a']))->assertNotFound();
    }

    public function test_one_client_won_is_a_single_step_win_in_the_acquisition_campaign_only(): void
    {
        $w = $this->world('Win');
        $ws = $w['a']['workspace'];
        $t = $this->t0;

        // P1 (booked) is also reached by a second campaign with its own opener + step.
        $second = AgencyProspectCampaign::create(['workspace_id' => $ws->id, 'name' => 'Second', 'status' => 'active', 'sending_mode' => 'managed', 'opening_message' => 'Hi']);
        $m1b = AgencyProspectCampaignMember::create(['workspace_id' => $ws->id, 'campaign_id' => $second->id, 'prospect_id' => $w['m1']->prospect_id, 'enrolled_at' => $t]);
        $this->msg($m1b, 'outbound', $t->copy()->addHours(1), ['purpose' => 'initial', 'body' => 'second opener']);
        $this->msg($m1b, 'outbound', $t->copy()->addHours(2), ['purpose' => 'ai_reply', 'sequence_step_id' => 's-b', 'body' => 'second step b']);

        // Won after both conversations, credited (as the app records it) to the FIRST campaign's member.
        AgencyProspect::whereKey($w['m1']->prospect_id)->update([
            'client_won_at' => $t->copy()->addHours(6), 'acquisition_member_id' => $w['m1']->id, 'acquisition_campaign_id' => $w['m1']->campaign_id,
        ]);

        $steps = $this->analytics($w['a'])->steps();
        $totalWon = array_sum(array_column($steps, 'won'));
        $this->assertSame(1, $totalWon, 'one client won = exactly one step-level win across every campaign');
        $this->assertSame(1, $this->step($steps, 's-b')['won'], 'credited to the step sent before the win in the acquisition conversation');
        $this->assertSame(0, $this->step($steps, 'opener')['won']);
        $this->assertSame(1, $this->analytics($w['a'])->kpis()['won'], 'workspace-wide count is still one');

        // Only the acquisition campaign sees the win.
        $second2 = $this->analytics($w['a'], ['campaign' => $second->uid])->steps();
        $this->assertSame(0, array_sum(array_column($second2, 'won')));

        // A win with no recorded acquisition member is never guessed onto a step.
        AgencyProspect::whereKey($w['m1']->prospect_id)->update(['acquisition_member_id' => null]);
        $this->assertSame(0, array_sum(array_column($this->analytics($w['a'])->steps(), 'won')));
    }

    public function test_explorer_export_keeps_the_active_kpi_filter(): void
    {
        $w = $this->world('Exp');
        $a = $w['a'];
        $this->authenticateAs($a['customer']);
        $base = $this->outreachRoute('analytics.index', $a['workspace']);
        $export = $this->outreachRoute('analytics.export', $a['workspace']);

        foreach (['booked' => ['Snap Happy'], 'rejected' => ['Mile High']] as $reached => $only) {
            $html = $this->get($base . '?tab=explorer&reached=' . $reached)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/analytics\/export\?[^"]*reached=' . $reached . '/', html_entity_decode($html), 'export link carries reached=' . $reached);

            $csv = $this->get($export . '?period=all&reached=' . $reached)->streamedContent();
            foreach (['Snap Happy', 'Glow Booth', 'Mile High', 'Flash Frame'] as $name) {
                in_array($name, $only, true) ? $this->assertStringContainsString($name, $csv) : $this->assertStringNotContainsString($name, $csv, "{$name} must not be in the {$reached} export");
            }
        }

        // Replied: P1, P2 and P3 all replied; the never-contacted P4 is excluded.
        $csv = $this->get($export . '?period=all&reached=replied')->streamedContent();
        $this->assertStringContainsString('Snap Happy', $csv);
        $this->assertStringContainsString('Mile High', $csv);
        $this->assertStringNotContainsString('Flash Frame', $csv);

        // Unfiltered export has everyone; the Performance tab's export link has no reached param.
        $this->assertStringContainsString('Flash Frame', $this->get($export . '?period=all')->streamedContent());
        preg_match('/data-role="export-csv" href="([^"]+)"/', $this->get($base)->getContent(), $m);
        $this->assertNotEmpty($m, 'header export link present');
        $this->assertStringNotContainsString('reached=', html_entity_decode($m[1]), 'no stray reached filter on the Performance tab export');
    }
}
