<?php

namespace Tests\Feature\NicheBlueprint\Support;

use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapter;
use App\Library\NicheBlueprint\Adapters\InstalledComponentReference;
use App\Models\Business;
use App\Models\CrmPipeline;
use RuntimeException;

/**
 * Contract 20 §7.4/§12.C — a TEST-ONLY throwing adapter, for the partial-
 * failure proof.
 *
 * It writes a Business-owned row FIRST and only then throws. That ordering is
 * the whole point: §7.4 promises "an adapter that throws rolls back its own
 * component's transaction only — nothing it partially wrote survives", and an
 * adapter that threw before writing anything could never prove it. The test
 * asserts the half-written pipeline is genuinely absent afterwards, which is
 * only meaningful because this adapter really did write it inside the
 * transaction.
 *
 * `$failuresRemaining` lets one test prove the retry story end to end: the
 * first run fails and records `failed`, a later run with the counter exhausted
 * installs the same component successfully.
 */
final class TestThrowingComponentAdapter implements BlueprintComponentAdapter
{
    public const TYPE = 'test_throwing';

    public static int $installAttempts = 0;

    public function __construct(
        private readonly string $componentType = self::TYPE,
        private int $failuresRemaining = PHP_INT_MAX,
    ) {
    }

    public static function resetAttempts(): void
    {
        self::$installAttempts = 0;
    }

    public function componentType(): string
    {
        return $this->componentType;
    }

    public function validateDescriptor(array $payload): void
    {
    }

    public function install(Business $business, array $payload, ?int $actorUserId): InstalledComponentReference
    {
        self::$installAttempts++;

        // A real, Business-owned write inside the installer's per-component
        // transaction — so the rollback assertion afterwards is about genuine
        // persistence, not about an adapter that never tried.
        $pipeline = CrmPipeline::create([
            'business_id' => $business->id,
            'name' => (string) ($payload['name'] ?? 'Half-written Component'),
            'position' => 0,
            'template_key' => 'test_blueprint',
            'template_version' => 1,
            'template_pipeline_key' => (string) ($payload['pipeline_key'] ?? 'throwing'),
            'created_by_user_id' => $actorUserId,
        ]);

        if ($this->failuresRemaining > 0) {
            $this->failuresRemaining--;

            throw new RuntimeException('Test adapter failure, after a partial write.');
        }

        return new InstalledComponentReference('crm_pipeline', (int) $pipeline->id);
    }
}
