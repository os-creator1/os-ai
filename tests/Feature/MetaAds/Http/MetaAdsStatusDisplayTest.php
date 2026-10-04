<?php

namespace Tests\Feature\MetaAds\Http;

use App\Library\MetaAds\MetaAdsDisplay;
use Tests\TestCase;

/**
 * Browser acceptance finding: right after a pause / resume the configured
 * `status` changes immediately while the provider-reported `effective_status`
 * stays stale until the next sync, so a paused campaign showed an "Active"
 * chip next to a "Resume" button. The chip must follow the configured switch
 * whenever the effective status only repeats the opposite plain ACTIVE/PAUSED.
 */
class MetaAdsStatusDisplayTest extends TestCase
{
    public function test_a_paused_row_with_a_stale_active_effective_status_reads_paused(): void
    {
        $this->assertSame('Paused', MetaAdsDisplay::statusLabel('PAUSED', 'ACTIVE'));
        $this->assertSame('neutral', MetaAdsDisplay::statusVariant('PAUSED', 'ACTIVE'));
        $this->assertSame('resume', MetaAdsDisplay::actionFor('PAUSED', 'ACTIVE'));
    }

    public function test_an_active_row_with_a_stale_paused_effective_status_reads_active(): void
    {
        $this->assertSame('Active', MetaAdsDisplay::statusLabel('ACTIVE', 'PAUSED'));
        $this->assertSame('success', MetaAdsDisplay::statusVariant('ACTIVE', 'PAUSED'));
        $this->assertSame('pause', MetaAdsDisplay::actionFor('ACTIVE', 'PAUSED'));
    }

    public function test_provider_reported_effective_statuses_still_win(): void
    {
        $this->assertSame('Paused (campaign paused)', MetaAdsDisplay::statusLabel('ACTIVE', 'CAMPAIGN_PAUSED'));
        $this->assertSame('Paused (ad set paused)', MetaAdsDisplay::statusLabel('ACTIVE', 'ADSET_PAUSED'));
        $this->assertSame('Active, with issues', MetaAdsDisplay::statusLabel('ACTIVE', 'WITH_ISSUES'));
        $this->assertSame('Not approved', MetaAdsDisplay::statusLabel('PAUSED', 'DISAPPROVED'));
        $this->assertSame('Active', MetaAdsDisplay::statusLabel('ACTIVE', 'ACTIVE'));
        $this->assertSame('Paused', MetaAdsDisplay::statusLabel('PAUSED', null));
        $this->assertSame('Archived', MetaAdsDisplay::statusLabel('ARCHIVED', 'ARCHIVED'));
        $this->assertSame('Deleted', MetaAdsDisplay::statusLabel('DELETED', 'ACTIVE'));
    }
}
