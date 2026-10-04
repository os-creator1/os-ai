<?php

namespace Tests\Feature\AgencyOutreach;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AgencyOutreach\Concerns\BuildsOutreachFixtures;
use Tests\TestCase;

/**
 * Contract §12 — "number not verified: do not send". The readiness the Text messaging page shows is
 * re-read at send time, so a campaign that was Active while the number and its verification were fine
 * stops sending the moment either is not, and nothing is lost: once it is fixed the next reply is answered.
 */
class OutreachReadinessGateTest extends TestCase
{
    use RefreshDatabase;
    use BuildsOutreachFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootOutreach();
    }

    public function test_an_unverified_number_sends_nothing_and_the_reason_is_recorded(): void
    {
        [, $business, $workspace] = $this->agency('Alpha Agency');
        $this->saveScript($workspace);
        $member = $this->enroll($workspace, $this->managedCampaign($workspace), $this->prospect($workspace));

        DB::table('business_messaging_registrations')->where('business_id', $business->id)->update(['status' => 'pending', 'approved_at' => null]);

        $this->inbound($business, 'who is this?');

        $this->assertSame([], $this->sentBodies(), 'An unverified number must never send.');
        $this->assertSame(1, $this->member($member)->stage->value);
        $this->assertSame('verification_incomplete', $this->ledger($member, 'inbound')[0]->failure_reason);
        $this->assertSame([], $this->ledger($member, 'outbound'), 'No outbound row is claimed for a send that cannot happen.');
    }

    public function test_a_suspended_number_sends_nothing(): void
    {
        [, $business, $workspace] = $this->agency('Beta Agency');
        $this->saveScript($workspace);
        $member = $this->enroll($workspace, $this->managedCampaign($workspace), $this->prospect($workspace));

        DB::table('business_messaging_numbers')->update(['status' => 'suspended']);

        $this->inbound($business, 'who is this?');

        $this->assertSame([], $this->sentBodies());
        $this->assertSame('no_sending_number', $this->ledger($member, 'inbound')[0]->failure_reason);
    }

    public function test_once_verification_is_approved_again_the_next_reply_is_answered(): void
    {
        [, $business, $workspace] = $this->agency('Gamma Agency');
        $this->saveScript($workspace);
        $member = $this->enroll($workspace, $this->managedCampaign($workspace), $this->prospect($workspace));

        DB::table('business_messaging_registrations')->where('business_id', $business->id)->update(['status' => 'pending', 'approved_at' => null]);
        $this->inbound($business, 'who is this?');
        $this->assertSame([], $this->sentBodies());

        DB::table('business_messaging_registrations')->where('business_id', $business->id)->update(['status' => 'approved', 'approved_at' => now()]);
        $this->inbound($business, 'hello?');

        $this->assertCount(1, $this->sentBodies());
        $this->assertSame(2, $this->member($member)->stage->value);
    }
}
