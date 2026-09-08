<?php

namespace Tests\Feature\Usage\Slice5;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Usage\UsageWalletManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Usage\Slice5\Concerns\Slice5Fixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 5 — E-14 / brief §11: the raw feature-key
 * input is gone. Customers pick a capability from a curated catalogue
 * with readable names and explanations; internal keys never render as the
 * primary label; unknown, Planned or Workspace-scoped keys are refused
 * server-side (404); saved limits and historical ledger rows keep
 * resolving to readable labels.
 */
class CapabilityLimitCatalogTest extends TestCase
{
    use RefreshDatabase;
    use Slice5Fixtures;

    public function test_no_free_text_feature_key_input_renders_and_only_catalogue_capabilities_are_offered(): void
    {
        [$owner, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->authenticateAs($owner);

        $html = $this->get($this->usageBillingUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringNotContainsString('feature_key_display', $html);
        $this->assertStringNotContainsString('e.g. crm', $html);
        $this->assertStringNotContainsString('Feature key', $html);
        $this->assertStringContainsString('<select class="form-select form-control transition-fast" id="usage-billing-capability" name="capability"', $html);

        preg_match_all('/<option value="([^"]+)">/', $html, $options);
        $this->assertSame(app(UsageWalletManager::class)->customerCapabilityCatalog(), $options[1]);
        $this->assertContains('crm', $options[1]);
        $this->assertNotContains('prospect_outreach', $options[1], 'Workspace-scoped features are never offered.');
        $this->assertNotContains('calendar', $options[1], 'Planned features are never offered.');
        $this->assertStringContainsString('Contacts &amp; CRM', $html);
        $this->assertStringContainsString('Contact storage, enrichment and list operations.', $html);
    }

    public function test_crafted_unknown_planned_or_workspace_scoped_keys_are_refused_with_404(): void
    {
        [$owner, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Agency House', 'Northwind Agency');
        $this->authenticateAs($owner);

        foreach (['not_a_feature', 'calendar', 'prospect_outreach', 'white_label', '../crm', 'CRM'] as $key) {
            $this->post($this->usageBillingRoute('feature-limit', $workspace, $business, [$key]), ['monthly_limit' => '10.00'])
                ->assertNotFound();
        }

        $this->assertDatabaseMissing('business_feature_usage_limits', ['business_id' => $business->id]);
    }

    public function test_a_catalogue_capability_saves_with_exact_conversion_and_renders_its_label(): void
    {
        [$owner, $business, $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Growth);
        $this->authenticateAs($owner);

        $this->post($this->usageBillingRoute('feature-limit', $workspace, $business, ['crm']), ['monthly_limit' => '12.50'])
            ->assertRedirect($this->usageBillingUrl($workspace, $business))
            ->assertSessionHas('flash_success');

        $this->assertDatabaseHas('business_feature_usage_limits', ['business_id' => $business->id, 'feature_key' => 'crm', 'monthly_limit_micro' => 12_500_000]);
        $this->assertStringContainsString('Contacts & CRM', session('flash_success'));

        $html = $this->get($this->usageBillingUrl($workspace, $business))->assertOk()->getContent();
        $this->assertStringContainsString('data-capability="crm"', $html);
        $this->assertStringContainsString('<td>Contacts &amp; CRM</td>', $html);
        $this->assertStringNotContainsString('<td>crm</td>', $html);
        $this->assertStringContainsString('USD 12.50', $html);

        // Blank clears the limit.
        $this->post($this->usageBillingRoute('feature-limit', $workspace, $business, ['crm']), ['monthly_limit' => ''])->assertSessionHas('flash_success');
        $this->assertDatabaseMissing('business_feature_usage_limits', ['business_id' => $business->id, 'feature_key' => 'crm']);
    }

    public function test_a_historical_key_without_a_catalogue_entry_still_renders_readably(): void
    {
        $manager = app(UsageWalletManager::class);

        $this->assertSame('Contacts & CRM', $manager->capabilityLabel('crm'));
        $this->assertSame('Google Business Profile', $manager->capabilityLabel('google_business_profile_module'));
        $this->assertSame('Legacy sms bundle', $manager->capabilityLabel('legacy_sms_bundle'));
        $this->assertSame('General', $manager->capabilityLabel(null));
        $this->assertNull($manager->capabilityHelp('legacy_sms_bundle'));
    }
}
