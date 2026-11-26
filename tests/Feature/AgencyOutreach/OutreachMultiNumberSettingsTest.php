<?php

namespace Tests\Feature\AgencyOutreach;

use App\Jobs\Outreach\OutreachInitialSendJob;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectingChannel;
use App\Models\AgencyProspectMessage;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AgencyOutreach\Concerns\BuildsAgencyByoChannels;
use Tests\Feature\AgencyOutreach\Concerns\BuildsOutreachFixtures;
use Tests\TestCase;

/**
 * The owner-facing side of multi-number Outreach: putting numbers in the Outreach pool, choosing a
 * campaign's sending numbers on Script & Settings, what that page and Analytics show, and that
 * existing data needs no reconfiguration. Telnyx HTTP is faked; nothing leaves the test.
 */
class OutreachMultiNumberSettingsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsOutreachFixtures;
    use BuildsAgencyByoChannels;

    /** Distinct sender numbers across every agency in a test (a number has one live owner). */
    private int $senderSeq = 100;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeAdapter();
        $this->ensureRequiredAppConfigRowsExist();
        User::create([
            'first_name' => 'Placeholder', 'last_name' => 'SuperAdmin',
            'email' => 'placeholder-superadmin' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        $this->fakeTelnyx();
    }

    /** @return array{0: Customer, 1: \App\Models\Business, 2: Workspace, 3: list<AgencyProspectingChannel>} */
    private function agencyWithNumbers(int $count, string $name = 'Snap Booth Co', bool $inPool = true): array
    {
        [$customer, $business, $workspace] = $this->agency($name);
        $this->saveScript($workspace);
        $channels = [];

        for ($i = 0; $i < $count; $i++) {
            $channel = $this->byoChannel($workspace, ['sender' => '12025553' . str_pad((string) ++$this->senderSeq, 3, '0', STR_PAD_LEFT), 'default' => $i === 0]);
            $channel->forceFill(['outreach_enabled' => $inPool])->save();
            $channels[] = $channel->fresh();
        }

        return [$customer, $business, $workspace, $channels];
    }

    private function actAs(Customer $customer): void
    {
        $customer->user->forceFill(['email_verified_at' => now()])->save();
        $this->withSession(['permissions' => collect(array_merge(['access_backend'], array_keys(config('customer-permissions'))))]);
        $this->actingAs($customer->user);
    }

    private function sendersUrl(Workspace $workspace, AgencyProspectCampaign $campaign): string
    {
        return route('customer.workspaces.prospecting.campaigns.senders', [$workspace->uid, $campaign->uid]);
    }

    public function test_the_owner_selects_one_some_or_all_numbers_and_the_selection_persists_without_recreating_the_campaign(): void
    {
        [$customer, , $workspace, [$a, $b, $c, $d]] = $this->agencyWithNumbers(4);
        $d->forceFill(['credentials_verified_at' => null])->save();
        $campaign = $this->managedCampaign($workspace, ['status' => 'draft']);
        $this->actAs($customer);

        $this->post($this->sendersUrl($workspace, $campaign), ['channels' => [$b->uid]])->assertSessionHas('flash_success');
        $this->assertSame([(int) $b->id], $campaign->fresh()->sender_channel_ids);

        $this->post($this->sendersUrl($workspace, $campaign), ['channels' => [$a->uid, $c->uid]])->assertSessionHas('flash_success');
        $this->assertSame([(int) $a->id, (int) $c->id], $campaign->fresh()->sender_channel_ids);

        // "Use all available numbers": every usable pool number, not the unverified one; no limit of three.
        $this->post($this->sendersUrl($workspace, $campaign), ['select_all' => 1])->assertSessionHas('flash_success');
        $this->assertSame([(int) $a->id, (int) $b->id, (int) $c->id], $campaign->fresh()->sender_channel_ids);

        $this->post($this->sendersUrl($workspace, $campaign), ['channels' => []])->assertSessionHas('flash_error');
        $this->assertSame([(int) $a->id, (int) $b->id, (int) $c->id], $campaign->fresh()->sender_channel_ids, 'An empty selection is refused.');
        $this->assertSame(1, AgencyProspectCampaign::where('workspace_id', $workspace->id)->count());
    }

    public function test_numbers_of_another_workspace_or_outside_the_pool_cannot_be_selected(): void
    {
        [$customer, , $workspace, [$a]] = $this->agencyWithNumbers(1);
        $notInPool = $this->byoChannel($workspace, ['sender' => '12025553999']);
        [$otherCustomer, , $otherWorkspace, [$foreign]] = $this->agencyWithNumbers(1, 'Other Agency');
        $campaign = $this->managedCampaign($workspace, ['status' => 'draft']);
        $otherCampaign = $this->managedCampaign($otherWorkspace, ['status' => 'draft']);
        $this->actAs($customer);

        $this->post($this->sendersUrl($workspace, $campaign), ['channels' => [$a->uid, $foreign->uid]])->assertSessionHas('flash_error');
        $this->post($this->sendersUrl($workspace, $campaign), ['channels' => [$notInPool->uid]])->assertSessionHas('flash_error');
        $this->assertNull($campaign->fresh()->sender_channel_ids, 'Nothing was saved.');

        // Another tenant's campaign is not reachable through this workspace, and vice versa.
        $this->post($this->sendersUrl($workspace, $otherCampaign), ['channels' => [$a->uid]])->assertStatus(404);
        $this->post(route('customer.workspaces.prospecting.channels.outreach', [$workspace->uid, $foreign->uid]), ['enabled' => 0])->assertStatus(404);
        $this->assertTrue($foreign->fresh()->outreach_enabled);
        $this->assertNull($otherCampaign->fresh()->sender_channel_ids);
    }

    public function test_numbers_are_added_to_and_removed_from_outreach_with_the_existing_verification(): void
    {
        [$customer, , $workspace, [$a, $b]] = $this->agencyWithNumbers(2, 'Snap Booth Co', false);
        $noKey = $this->byoChannel($workspace, ['sender' => '12025553998', 'webhook_key' => false]);
        $this->actAs($customer);
        $url = fn ($c) => route('customer.workspaces.prospecting.channels.outreach', [$workspace->uid, $c->uid]);

        $this->post($url($b), ['enabled' => 1])->assertSessionHas('flash_success');
        $this->assertTrue($b->fresh()->outreach_enabled);
        $this->post($url($noKey), ['enabled' => 1])->assertSessionHas('flash_error');
        $this->assertFalse($noKey->fresh()->outreach_enabled);

        $this->post($url($b), ['enabled' => 0])->assertSessionHas('flash_success');
        $this->assertFalse($b->fresh()->outreach_enabled);

        $this->get(route('customer.workspaces.prospecting.phone-messaging', $workspace->uid))
            ->assertOk()
            ->assertSee('Use for Outreach')
            ->assertSee($b->sender_number);
    }

    public function test_script_settings_shows_each_number_its_availability_and_the_estimated_split(): void
    {
        [$customer, , $workspace, [$a, $b, $c]] = $this->agencyWithNumbers(3);
        $c->forceFill(['status' => AgencyProspectingChannel::STATUS_DISABLED])->save();
        $outside = $this->byoChannel($workspace, ['sender' => '12025553997']);
        \App\Models\AgencyProspectingSetting::query()->where('workspace_id', $workspace->id)->update(['opener_daily_cap' => 50]);
        $campaign = $this->managedCampaign($workspace, ['name' => 'Venues']);
        $campaign->update(['sender_channel_ids' => [(int) $a->id, (int) $b->id, (int) $c->id]]);
        $this->actAs($customer);

        $page = $this->get(route('customer.workspaces.prospecting.script.show', $workspace->uid) . '?senders_campaign=' . $campaign->uid)->assertOk();

        $page->assertSee('Sending numbers')
            ->assertSee($a->sender_number)->assertSee($b->sender_number)->assertSee($c->sender_number)->assertSee($outside->sender_number)
            ->assertSee('This sender is turned off')
            ->assertSee('Not added to Outreach')
            ->assertSee('about 25', false);
    }

    public function test_analytics_shows_results_per_sending_number_from_the_existing_ledger(): void
    {
        [$customer, , $workspace, [$a, $b]] = $this->agencyWithNumbers(2);
        $campaign = $this->managedCampaign($workspace);
        $campaign->update(['sender_channel_ids' => [(int) $a->id, (int) $b->id]]);

        foreach (['12025551101', '12025551102', '12025551103'] as $phone) {
            $member = $this->enroll($workspace, $campaign, $this->prospect($workspace, $phone));
            app()->call([new OutreachInitialSendJob($member->id), 'handle']);
        }

        $onA = AgencyProspectCampaignMember::where('campaign_id', $campaign->id)->where('sender_channel_id', $a->id)->first();
        $this->signedWebhook($a, $this->receivedPayload($a, 'who is this?', 'in-1', '+' . $onA->prospect->phone))->assertOk();

        $rows = collect((new \App\Library\AgencyOutreach\Analytics\OutreachAnalytics($workspace, new \App\Library\AgencyOutreach\Analytics\AnalyticsFilters(period: 'all')))->numbers())->keyBy('number');

        $this->assertSame(2, $rows[$a->sender_number]['assigned']);
        $this->assertSame(1, $rows[$b->sender_number]['assigned']);
        $this->assertSame(AgencyProspectMessage::where('channel_id', $a->id)->where('status', 'sent')->count(), $rows[$a->sender_number]['sent']);
        $this->assertSame(1, $rows[$b->sender_number]['sent']);
        $this->assertSame(1, $rows[$a->sender_number]['replies']);
        $this->assertSame(0, $rows[$b->sender_number]['replies']);
        $this->assertSame(0, $rows[$a->sender_number]['failed']);

        $this->actAs($customer);
        $this->get(route('customer.workspaces.prospecting.analytics.index', $workspace->uid) . '?tab=messages&period=all')
            ->assertOk()->assertSee('Sending number')->assertSee($a->sender_number);
    }

    public function test_an_automated_faq_answer_goes_out_from_the_assigned_number(): void
    {
        [, , $workspace, [$a, $b]] = $this->agencyWithNumbers(2);
        $campaign = $this->managedCampaign($workspace);
        $campaign->update(['sender_channel_ids' => [(int) $b->id]]);
        $member = $this->enroll($workspace, $campaign, $this->prospect($workspace, '12025551000'));
        app()->call([new OutreachInitialSendJob($member->id), 'handle']);

        $this->signedWebhook($b, $this->receivedPayload($b, 'How much does it cost?', 'in-faq'))->assertOk();

        $requests = $this->telnyxMessageRequests();
        $this->assertCount(2, $requests);
        $this->assertSame('+' . $b->sender_number, $requests[1]['from']);
        $this->assertStringContainsString('9%', (string) $requests[1]['text']);
    }
}
