<?php

namespace Tests\Unit\Growth;

use App\Library\Growth\GrowthHomeBand;
use App\Models\Opportunity;
use Tests\TestCase;

class GrowthHomeBandSeoHeadTest extends TestCase
{
    private function opp(string $type): Opportunity
    {
        return new Opportunity(['type' => $type]);
    }

    public function test_growth_prefers_an_eligible_seo_opportunity_over_a_routine_non_seo_one(): void
    {
        $top = collect([$this->opp('crm.stale_opportunities:v1'), $this->opp('seo.technical_findings:v1')]);

        $this->assertSame('seo.technical_findings:v1', GrowthHomeBand::preferredHead($top, true)->type);
    }

    public function test_without_the_growth_flag_the_queue_head_is_unchanged(): void
    {
        $top = collect([$this->opp('crm.stale_opportunities:v1'), $this->opp('seo.technical_findings:v1')]);

        $this->assertSame('crm.stale_opportunities:v1', GrowthHomeBand::preferredHead($top, false)->type);
    }

    public function test_with_no_seo_opportunity_nothing_is_invented_and_the_existing_head_is_kept(): void
    {
        $top = collect([$this->opp('crm.stale_opportunities:v1'), $this->opp('booking.type_not_ready:v1')]);

        $this->assertSame('crm.stale_opportunities:v1', GrowthHomeBand::preferredHead($top, true)->type);
        $this->assertNull(GrowthHomeBand::preferredHead(collect(), true));
    }

    public function test_an_operational_lead_response_row_keeps_its_place(): void
    {
        $top = collect([$this->opp('crm.unanswered_new_leads:v1'), $this->opp('seo.technical_findings:v1')]);

        $this->assertSame('crm.unanswered_new_leads:v1', GrowthHomeBand::preferredHead($top, true)->type);
    }
}
