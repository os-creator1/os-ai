<?php

namespace App\Console\Commands;

use App\Enums\Business\BusinessIndustry;
use App\Enums\NicheBlueprint\NicheBlueprintVersionState;
use App\Library\NicheBlueprint\Adapters\CrmPipelineComponentAdapter;
use App\Library\NicheBlueprint\NicheBlueprintPublisher;
use App\Models\NicheBlueprint;
use App\Models\NicheBlueprintVersion;
use App\Models\User;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Contract 20 §5.5/§12.D — seeds and publishes the platform's Photo Booth
 * Blueprint v1: the ONE component this sub-slice ships, the default CRM
 * sales pipeline in §9's exact stage sequence.
 *
 * IDEMPOTENT, SAFE TO RUN REPEATEDLY. If `photo_booth` already has a
 * published version, this is a no-op success. It never edits an issued
 * version and never publishes a v2 — publishing a later version that changes
 * or adds a component is a deliberate, separate operator action (§5.2's
 * authoring-content immutability), and this command does not perform it.
 *
 * RESOLVES VIA `broad_industry`, NOT `vertical_key`. `business_verticals`
 * ships with no seeded rows, so a Blueprint bound to a `vertical_key` could
 * never resolve for any Business (§7.1 step 1) until an operator seeds one.
 * `broad_industry = 'photo_booth_service'` (§7.1 step 2) matches
 * `BusinessIndustry::PhotoBoothService` and every fixture/default Business
 * industry value already in use, so this is the resolvable shape §5.5 asks
 * for today.
 *
 * Per `CLAUDE.md`'s route-3 rules this command is never run against anything
 * but a `TestDatabaseSafety`-approved database during development.
 */
class SeedPhotoBoothBlueprintCommand extends Command
{
    private const BLUEPRINT_KEY = 'photo_booth';

    protected $signature = 'blueprint:seed-photo-booth
        {--actor= : Platform administrator user id to publish as; defaults to the first admin user found}';

    protected $description = 'Create and publish the Photo Booth niche Blueprint v1 (Contract 20 §5.5), if not already published.';

    public function handle(NicheBlueprintPublisher $publisher): int
    {
        $blueprint = NicheBlueprint::query()->where('key', self::BLUEPRINT_KEY)->first();

        if ($blueprint !== null && $this->hasPublishedVersion($blueprint)) {
            $this->info('The photo_booth Blueprint already has a published version; nothing to do.');

            return self::SUCCESS;
        }

        $actorUserId = $this->resolveActorUserId();

        if ($blueprint === null) {
            $blueprint = $publisher->createBlueprint(
                $actorUserId,
                self::BLUEPRINT_KEY,
                'Photo Booth',
                null,
                BusinessIndustry::PhotoBoothService->value,
            );
        }

        $version = $publisher->createDraftVersion($actorUserId, $blueprint);

        $publisher->addDraftComponent(
            $actorUserId,
            $version,
            'photo_booth_default_pipeline',
            CrmPipelineComponentAdapter::TYPE,
            'crm',
            $this->componentPayload(),
        );

        $published = $publisher->publishVersion($actorUserId, $version);

        $this->info("Published photo_booth Blueprint version {$published->version_number}.");

        return self::SUCCESS;
    }

    private function hasPublishedVersion(NicheBlueprint $blueprint): bool
    {
        return NicheBlueprintVersion::query()
            ->where('blueprint_id', $blueprint->id)
            ->where('state', NicheBlueprintVersionState::Published->value)
            ->exists();
    }

    private function resolveActorUserId(): int
    {
        $option = $this->option('actor');

        if ($option !== null) {
            return (int) $option;
        }

        $adminId = User::query()->where('is_admin', true)->value('id');

        if ($adminId === null) {
            throw new RuntimeException(
                'No platform administrator user exists to publish the photo_booth Blueprint as. Pass --actor=<user id>.'
            );
        }

        return (int) $adminId;
    }

    /**
     * Contract 20 §5.5's exact component descriptor: Blueprint §9's default
     * pipeline, "New Lead" over the canonical `new_inquiry` semantic key.
     *
     * @return array<string, mixed>
     */
    private function componentPayload(): array
    {
        return [
            'template_key' => self::BLUEPRINT_KEY,
            'template_version' => 1,
            'pipeline_key' => 'sales',
            'name' => 'Sales pipeline',
            'stages' => [
                ['name' => 'New Lead', 'semantic_key' => 'new_inquiry'],
                ['name' => 'Auto Follow-Up', 'semantic_key' => 'auto_follow_up'],
                ['name' => 'In Contact', 'semantic_key' => 'in_contact'],
                ['name' => 'Proposal Sent', 'semantic_key' => 'proposal_sent'],
                ['name' => 'Invoice Sent', 'semantic_key' => 'invoice_sent'],
                ['name' => 'Questionnaire Sent', 'semantic_key' => 'questionnaire_sent'],
                ['name' => 'Questionnaire Submitted', 'semantic_key' => 'questionnaire_submitted'],
                ['name' => 'Done', 'semantic_key' => 'done'],
            ],
        ];
    }
}
