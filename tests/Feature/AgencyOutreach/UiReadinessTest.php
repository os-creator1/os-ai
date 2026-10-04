<?php

namespace Tests\Feature\AgencyOutreach;

use App\Jobs\Outreach\OutreachInitialSendJob;
use App\Library\AgencyOutreach\AgencyOutreachReadiness;
use App\Library\AgencyOutreach\OutreachScriptManager;
use App\Models\AgencyProspectMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\AgencyOutreach\Concerns\CreatesOutreachUiFixtures;
use Tests\TestCase;

/**
 * The readiness checklist: one failure at a time, with the exact reason, and
 * the campaign Start refused/allowed accordingly.
 */
class UiReadinessTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOutreachUiFixtures;

    private function readiness(array $agency)
    {
        $this->authenticateAs($agency['customer']);

        return app(AgencyOutreachReadiness::class)->forWorkspace($agency['workspace']);
    }

    private function assertBlockedBy(string $key, array $agency, string $reasonFragment): void
    {
        $readiness = $this->readiness($agency);
        $item = $readiness->item($key);

        $this->assertFalse($readiness->isReady());
        $this->assertFalse($item['ok'], "{$key} should be blocked");
        $this->assertStringContainsString($reasonFragment, (string) $item['reason']);

        $this->get($this->outreachRoute('overview', $agency['workspace']))->assertOk()->assertSee($reasonFragment, false);

        Queue::fake();
        $this->post($this->outreachRoute('campaigns.start', $agency['workspace'], [$agency['campaign']->uid]))->assertRedirect();
        Queue::assertNotPushed(OutreachInitialSendJob::class);

        {
            $this->assertSame('draft', $agency['campaign']->fresh()->status->value, 'A blocked campaign must stay a draft.');
        }
    }

    public function test_a_complete_agency_is_ready_and_start_activates_and_queues_one_job_per_member(): void
    {
        $a = $this->outreachAgency('Alpha');
        $readiness = $this->readiness($a);

        $this->assertTrue($readiness->isReady(), (string) $readiness->firstBlockingReason());
        $this->assertNull($readiness->firstBlockingReason());

        Queue::fake();
        $this->post($this->outreachRoute('campaigns.start', $a['workspace'], [$a['campaign']->uid]))
            ->assertRedirect()->assertSessionHas('flash_success');

        $this->assertSame('active', $a['campaign']->fresh()->status->value);
        Queue::assertPushed(OutreachInitialSendJob::class, 1);
    }

    public function test_no_number_blocks(): void
    {
        $a = $this->outreachAgency('Alpha', ['number' => false, 'registration' => null]);

        $this->assertBlockedBy('number', $a, 'do not have a sending number');
    }

    public function test_unfinished_verification_blocks(): void
    {
        $a = $this->outreachAgency('Alpha', ['registration' => 'pending']);

        $this->assertBlockedBy('verification', $a, 'verification is not complete');
    }

    public function test_an_unresolvable_business_blocks(): void
    {
        $a = $this->outreachAgency('Alpha');
        DB::table('businesses')->where('id', $a['business']->id)->update(['status' => 'inactive']);

        $this->assertBlockedBy('business', $a, 'could be found');
    }

    public function test_a_paused_or_suspended_wallet_and_unpaid_debt_block(): void
    {
        foreach ([
            ['paid_activity_paused_at' => now()],
            ['billing_status' => 'suspended'],
            ['debt_balance_micro' => 5000],
        ] as $change) {
            $a = $this->outreachAgency('Alpha' . uniqid());
            DB::table('business_usage_wallets')->where('business_id', $a['business']->id)->update($change);

            $item = $this->readiness($a)->item('wallet');
            $this->assertFalse($item['ok'], json_encode($change));
            $this->assertNotEmpty($item['reason']);
        }
    }

    public function test_a_low_balance_blocks_only_once_a_rate_is_active_and_cost_is_shown_then(): void
    {
        $a = $this->outreachAgency('Alpha', ['balance' => 0]);

        // No rate yet: sending is not billed, so a zero balance does not block and no cost is invented.
        $readiness = $this->readiness($a);
        $this->assertTrue($readiness->item('wallet')['ok']);
        $this->assertArrayNotHasKey('cost_per_message', array_filter($readiness->info(), fn ($v) => $v !== null));

        $this->priceOutreachTransport(10_000);

        $readiness = $this->readiness($a);
        $this->assertFalse($readiness->item('wallet')['ok']);
        $this->assertStringContainsString('balance is too low', $readiness->item('wallet')['reason']);
        $this->assertNotNull($readiness->info()['cost_per_message']);

        $this->get($this->outreachRoute('overview', $a['workspace']))->assertSee('Estimated cost per text')->assertSee('Add funds');

        $this->fundOutreachWallet($a['business'], 5_000_000);
        $this->assertTrue($this->readiness($a)->item('wallet')['ok']);
    }

    public function test_a_missing_calendar_link_or_a_message_three_without_it_blocks(): void
    {
        $a = $this->outreachAgency('Alpha', ['calendar' => null]);
        $this->assertBlockedBy('script', $a, 'calendar link');

        $b = $this->outreachAgency('Bravo');
        $this->authenticateAs($b['customer']);
        OutreachScriptManager::save($b['workspace'], ['message_3' => 'Book whenever you like.']);
        $item = app(AgencyOutreachReadiness::class)->forWorkspace($b['workspace'])->item('script');
        $this->assertFalse($item['ok']);
        $this->assertStringContainsString('Message 3 must include your calendar link', $item['reason']);
    }

    public function test_no_prospects_blocks(): void
    {
        $a = $this->outreachAgency('Alpha', ['enrolled' => false]);

        $this->assertBlockedBy('prospects', $a, 'Add at least one prospect');
    }

    public function test_paused_for_funds_shows_the_banner_and_resume_sending_is_a_post_that_needs_readiness(): void
    {
        $a = $this->outreachAgency('Alpha');
        $this->authenticateAs($a['customer']);
        AgencyProspectMessage::create([
            'workspace_id' => $a['workspace']->id, 'campaign_member_id' => $a['member']->id, 'direction' => 'outbound',
            'purpose' => 'ai_reply', 'operation_key' => 'outreach:reply:1', 'body' => 'x', 'status' => 'paused', 'failure_reason' => 'insufficient_balance',
        ]);

        $this->get($this->outreachRoute('overview', $a['workspace']))->assertSee('Paused — add funds')->assertSee('Resume sending');
        $this->get($this->outreachRoute('campaigns.index', $a['workspace']))->assertSee('Paused — add funds');

        $this->post($this->outreachRoute('sending.resume', $a['workspace']))->assertRedirect()->assertSessionHas('flash_success');

        DB::table('business_usage_wallets')->where('business_id', $a['business']->id)->update(['paid_activity_paused_at' => now()]);
        $this->post($this->outreachRoute('sending.resume', $a['workspace']))->assertRedirect()->assertSessionHas('flash_error');
    }

    public function test_a_channel_campaign_start_is_untouched_by_the_readiness_gate(): void
    {
        $a = $this->outreachAgency('Alpha', ['number' => false, 'registration' => null]);
        $a['campaign']->update(['sending_mode' => 'channel']);
        $this->authenticateAs($a['customer']);

        $this->post($this->outreachRoute('campaigns.start', $a['workspace'], [$a['campaign']->uid]))
            ->assertSessionHas('flash_error', 'Select a channel before starting this campaign.');
    }
}
