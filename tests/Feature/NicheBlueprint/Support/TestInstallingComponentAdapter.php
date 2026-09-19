<?php

namespace Tests\Feature\NicheBlueprint\Support;

use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapter;
use App\Library\NicheBlueprint\Adapters\InstalledComponentReference;
use App\Models\Business;
use App\Models\CrmPipeline;

/**
 * Contract 20 §12.C — a TEST-ONLY installing adapter. No real adapter exists
 * until Sub-slice D, and this deliberately is not one: it lives under tests/
 * and is never registered by application code.
 *
 * It writes a genuinely Business-owned row (`crm_pipelines`, an existing
 * Business-owned table with a cascade FK to `businesses`) rather than a
 * fake/in-memory marker, because the assertions that matter most in this
 * sub-slice are the NEGATIVE ones: "an unentitled component creates ZERO
 * business-owned state", "a Planned feature creates ZERO business-owned
 * state", "an upgrade writes ZERO business-owned rows". Those only mean
 * something if a successful install genuinely writes one.
 *
 * It writes that row directly rather than through `BusinessTemplateApplier`,
 * because delegating to a real module seam is Sub-slice D's job and §9.3
 * forbids this contract from touching the CRM library at all.
 */
final class TestInstallingComponentAdapter implements BlueprintComponentAdapter
{
    public const TYPE = 'test_installing';

    public function __construct(private readonly string $componentType = self::TYPE)
    {
    }

    public function componentType(): string
    {
        return $this->componentType;
    }

    public function validateDescriptor(array $payload): void
    {
        // Publish-time validation is not this sub-slice's subject; the
        // publisher's own gate (§6.2 check 5) is covered by Sub-slice B's
        // tests. Accepting any payload here keeps these fixtures focused on
        // the installation engine.
    }

    public function install(Business $business, array $payload, ?int $actorUserId): InstalledComponentReference
    {
        $pipeline = CrmPipeline::create([
            'business_id' => $business->id,
            'name' => (string) ($payload['name'] ?? 'Blueprint Component'),
            'position' => 0,
            'template_key' => 'test_blueprint',
            'template_version' => 1,
            'template_pipeline_key' => (string) ($payload['pipeline_key'] ?? 'default'),
            'created_by_user_id' => $actorUserId,
        ]);

        return new InstalledComponentReference('crm_pipeline', (int) $pipeline->id);
    }
}
