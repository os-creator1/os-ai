<?php

namespace Tests\Feature\MetaAds\Http;

use App\Enums\Entitlement\WorkspacePlanTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\MetaAds\Http\Concerns\CreatesMetaAdsHttpFixtures;
use Tests\TestCase;

/**
 * Browser acceptance finding: while an Agency views a client, every Meta
 * state-changing route is View-As prohibited (MetaAdsAccessMatrixTest), but the
 * pages still drew Pause / Resume / Save / Refresh / Disconnect controls that
 * could only bounce. The controls are now withheld in View As; the same pages
 * keep them for the owner.
 */
class MetaAdsViewAsControlsTest extends TestCase
{
    use CreatesMetaAdsHttpFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareMetaHttp();
    }

    protected function tearDown(): void
    {
        $this->finishMetaHttp();
        parent::tearDown();
    }

    private const ACTION_MARKERS = [
        'data-role="save-settings"',
        'data-role="refresh-now"',
        'data-role="disconnect-meta-ads"',
    ];

    public function test_the_owner_sees_the_controls_that_view_as_hides(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant(WorkspacePlanTier::Growth, 'Owner Co');
        $this->seedMetaOctober($this->metaSelected($business));
        $this->asMetaUser($customer);

        $settings = $this->get($this->metaPage($workspace, $business, 'settings'))->assertOk()->getContent();

        foreach (self::ACTION_MARKERS as $marker) {
            $this->assertStringContainsString($marker, $settings);
        }

        $this->assertStringContainsString('/pause', $this->get($this->metaPage($workspace, $business, 'campaigns.index'))->assertOk()->getContent());
    }

    public function test_view_as_withholds_every_state_changing_control_but_keeps_the_figures(): void
    {
        [$agency, $client, $clientWorkspace] = $this->tenant(WorkspacePlanTier::Agency, 'Viewed Client', 'Northwind Agency');
        $this->seedMetaOctober($this->metaSelected($client));

        $this->authenticateAs($agency);
        $this->startViewAs($clientWorkspace, $client)->assertRedirect(route('user.home'));

        $settings = $this->get($this->metaPage($clientWorkspace, $client, 'settings'))->assertOk()->getContent();

        foreach (self::ACTION_MARKERS as $marker) {
            $this->assertStringNotContainsString($marker, $settings, 'View As must not offer ' . $marker);
        }
        $this->assertStringContainsString('cannot be changed while viewing a client account', $settings);

        foreach (['campaigns.index', 'ad-sets.index', 'ads.index'] as $name) {
            $html = $this->get($this->metaPage($clientWorkspace, $client, $name))->assertOk()->getContent();
            $this->assertStringNotContainsString('/pause"', $html, "[{$name}] must not offer pause");
            $this->assertStringNotContainsString('/resume"', $html, "[{$name}] must not offer resume");
        }

        $this->get($this->metaPage($clientWorkspace, $client))->assertOk();
        $this->assertSame(0, $this->fakeMeta->callCount());
    }
}
