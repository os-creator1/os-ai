<?php

namespace Tests\Feature\NicheBlueprint;

use App\Enums\NicheBlueprint\BlueprintComponentInstallationState;
use App\Models\Business;
use App\Models\BusinessBlueprintComponentInstallation;
use App\Models\NicheBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Implementation Contract 20 §5.1/§5.4, §12.A — the model write boundary.
 *
 * Sub-slice A ships models with casts and relations only, and no services.
 * That makes the mass-assignment allowlist the ONLY thing standing between a
 * future caller and fields that later sub-slices own. These tests pin that
 * boundary now, so the publisher (B) and installer (C) can be built on the
 * guarantee that no casual route around them already exists.
 *
 * The threat is concrete, not theoretical. An installation row's outcome
 * fields are facts the installer established; because
 * (business_id, blueprint_id, component_key) is unique and an automated run
 * never revisits an `installed` row (§7.2), a forged outcome written once
 * would be permanent.
 */
class BlueprintModelWriteBoundaryTest extends TestCase
{
    use CreatesBusinessTestData;
    use RefreshDatabase;

    private function business(): Business
    {
        return $this->createBusinessWithWorkspace($this->createCustomer(), $this->businessAttributes());
    }

    private function blueprint(): NicheBlueprint
    {
        return NicheBlueprint::create([
            'key' => 'photo_booth',
            'display_name' => 'Photo Booth',
            'broad_industry' => 'photo_booth_service',
        ]);
    }

    /**
     * A persisted record created the way the Sub-slice C installer will: the
     * descriptor mass-assigned, the outcome set through a trusted write.
     *
     * `state` is NOT NULL with no database default (§5.4), so it must always
     * be supplied by that trusted write — which is exactly the point, and is
     * why these tests never rely on `create()` alone to persist a record.
     *
     * @param  array<string, mixed>  $forged  outcome fields a caller tries to
     *                                        smuggle in through mass assignment
     */
    private function persistedRecord(
        Business $business,
        NicheBlueprint $blueprint,
        BlueprintComponentInstallationState $state,
        array $forged = [],
    ): BusinessBlueprintComponentInstallation {
        $record = new BusinessBlueprintComponentInstallation();

        $record->fill(array_merge([
            'business_id' => $business->id,
            'blueprint_id' => $blueprint->id,
            'component_key' => 'photo_booth_default_pipeline',
            'component_type' => 'crm_pipeline',
            'installed_from_version' => 1,
            'required_feature_key' => 'crm',
        ], $forged));

        // The one sanctioned write: the installer establishing the outcome.
        $record->forceFill(['state' => $state->value])->save();

        return $record;
    }

    // ------------------------------------------- NicheBlueprint.is_active

    /**
     * §5.1 — whether a Blueprint is live is a platform lifecycle decision
     * owned by the later platform manager. A mass-assignable `is_active`
     * would let an ordinary create/update silently retire (or publish) a
     * niche for every future signup that resolves to it.
     */
    public function test_is_active_is_not_mass_assignable_on_a_blueprint(): void
    {
        $this->assertFalse((new NicheBlueprint())->isFillable('is_active'));
    }

    public function test_a_new_blueprint_takes_the_database_default_of_active(): void
    {
        $blueprint = NicheBlueprint::create([
            'key' => 'photo_booth',
            'display_name' => 'Photo Booth',
            'broad_industry' => 'photo_booth_service',
            // Ignored: not in the allowlist.
            'is_active' => false,
        ]);

        $this->assertSame(
            1,
            (int) DB::table('niche_blueprints')->where('id', $blueprint->id)->value('is_active'),
            'A Blueprint created through mass assignment must take the database default (active).'
        );
    }

    public function test_fill_cannot_deactivate_a_blueprint(): void
    {
        $blueprint = $this->blueprint();
        $this->assertTrue($blueprint->fresh()->is_active);

        $blueprint->fill(['is_active' => false]);
        $blueprint->save();

        $this->assertTrue(
            $blueprint->fresh()->is_active,
            'fill() must not be able to deactivate a Blueprint.'
        );
        $this->assertSame(
            1,
            (int) DB::table('niche_blueprints')->where('id', $blueprint->id)->value('is_active'),
            'The persisted control value must be unchanged by ordinary mass assignment.'
        );
    }

    public function test_update_cannot_deactivate_a_blueprint(): void
    {
        $blueprint = $this->blueprint();

        $blueprint->update(['display_name' => 'Renamed', 'is_active' => false]);

        $fresh = $blueprint->fresh();
        $this->assertSame('Renamed', $fresh->display_name, 'An allowlisted field still updates normally.');
        $this->assertTrue($fresh->is_active, 'update() must not carry is_active through.');
    }

    /**
     * The escape hatch the later platform manager will use exists and works —
     * the boundary is a deliberate allowlist, not an accidental dead end.
     */
    public function test_the_platform_manager_can_still_set_is_active_through_a_trusted_write(): void
    {
        $blueprint = $this->blueprint();

        $blueprint->forceFill(['is_active' => false])->save();

        $this->assertFalse($blueprint->fresh()->is_active);
    }

    // ------------------------ BusinessBlueprintComponentInstallation outcome

    public function test_every_installer_owned_outcome_field_is_blocked_from_mass_assignment(): void
    {
        $model = new BusinessBlueprintComponentInstallation();

        foreach ([
            'state',
            'decision_reason',
            'installed_record_type',
            'installed_record_id',
            'error_code',
            'installed_at',
            'installed_by_user_id',
        ] as $field) {
            $this->assertFalse(
                $model->isFillable($field),
                $field . ' is an installer-owned outcome field and must not be mass-assignable.'
            );
        }
    }

    public function test_the_descriptor_inputs_remain_mass_assignable(): void
    {
        $model = new BusinessBlueprintComponentInstallation();

        foreach ([
            'business_id',
            'blueprint_id',
            'component_key',
            'component_type',
            'installed_from_version',
            'required_feature_key',
        ] as $field) {
            $this->assertTrue(
                $model->isFillable($field),
                $field . ' is a descriptor input the future manager legitimately starts from.'
            );
        }
    }

    /**
     * THE ATTACK THIS BOUNDARY EXISTS TO STOP: one mass assignment claiming a
     * component was installed, pointing at a row it never created, attributed
     * to an actor that never acted, at a time it never happened.
     *
     * Because (business_id, blueprint_id, component_key) is unique and an
     * automated run never revisits an `installed` row, such a forgery would be
     * permanent and would silently suppress the component forever.
     */
    public function test_mass_assignment_cannot_forge_an_installed_outcome(): void
    {
        $business = $this->business();
        $blueprint = $this->blueprint();

        // The caller tries to smuggle a complete, fabricated "installed"
        // outcome in alongside the legitimate descriptor. The record is then
        // persisted with the only outcome the installer actually decided.
        $record = $this->persistedRecord(
            $business,
            $blueprint,
            BlueprintComponentInstallationState::SkippedUnentitled,
            [
                // Every one of these must be discarded.
                'state' => BlueprintComponentInstallationState::Installed->value,
                'decision_reason' => 'totally_legitimate',
                'installed_record_type' => 'crm_pipeline',
                'installed_record_id' => 4242,
                'error_code' => 'none',
                'installed_at' => now(),
                'installed_by_user_id' => 999,
            ],
        );

        $row = DB::table('business_blueprint_component_installations')->where('id', $record->id)->first();

        $this->assertSame(
            BlueprintComponentInstallationState::SkippedUnentitled->value,
            $row->state,
            'Mass assignment must never be able to claim a component was installed.'
        );
        $this->assertNull($row->decision_reason, 'A decision reason must come from the entitlement authority.');
        $this->assertNull($row->installed_record_type, 'An installed target must come from the adapter.');
        $this->assertNull($row->installed_record_id, 'An installed target id must come from the adapter.');
        $this->assertNull($row->error_code);
        $this->assertNull($row->installed_at, 'An installation timestamp must record a write that actually happened.');
        $this->assertNull($row->installed_by_user_id, 'An actor must never be forged by mass assignment.');
    }

    public function test_mass_assignment_cannot_forge_a_skipped_outcome(): void
    {
        $business = $this->business();
        $blueprint = $this->blueprint();

        $record = new BusinessBlueprintComponentInstallation();
        $record->fill([
            'business_id' => $business->id,
            'blueprint_id' => $blueprint->id,
            'component_key' => 'photo_booth_default_pipeline',
            'component_type' => 'crm_pipeline',
            'installed_from_version' => 1,
            'required_feature_key' => 'crm',
            'state' => BlueprintComponentInstallationState::SkippedUnentitled->value,
            'decision_reason' => 'not_entitled_by_plan',
        ]);

        $this->assertNull($record->state, 'fill() must not set a skip state.');
        $this->assertNull($record->decision_reason, 'fill() must not set a decision reason.');
    }

    /**
     * A skip state is what §8.1 re-evaluates on every render; forging one is
     * less catastrophic than forging `installed`, but it still fabricates a
     * decision the entitlement authority never made, so it is blocked too.
     */
    public function test_update_cannot_flip_an_existing_record_to_installed(): void
    {
        $business = $this->business();
        $blueprint = $this->blueprint();

        $record = $this->persistedRecord(
            $business,
            $blueprint,
            BlueprintComponentInstallationState::SkippedUnentitled,
        );

        $record->update([
            'state' => BlueprintComponentInstallationState::Installed->value,
            'installed_record_id' => 1,
            'installed_by_user_id' => 7,
        ]);

        $row = DB::table('business_blueprint_component_installations')->where('id', $record->id)->first();

        $this->assertSame(
            BlueprintComponentInstallationState::SkippedUnentitled->value,
            $row->state,
            'update() must not be able to reverse a skip into an install.'
        );
        $this->assertNull($row->installed_record_id);
        $this->assertNull($row->installed_by_user_id);
    }

    /**
     * The installer's own sanctioned path still works — §5.4's outcome fields
     * are reachable by a trusted write, just never by a casual one.
     */
    public function test_the_installer_can_still_record_an_outcome_through_a_trusted_write(): void
    {
        $business = $this->business();
        $blueprint = $this->blueprint();

        $record = $this->persistedRecord(
            $business,
            $blueprint,
            BlueprintComponentInstallationState::Failed,
        );

        $record->forceFill([
            'state' => BlueprintComponentInstallationState::Installed->value,
            'installed_record_type' => 'crm_pipeline',
            'installed_record_id' => 4242,
            'installed_at' => now(),
            'installed_by_user_id' => null,
        ])->save();

        $fresh = $record->fresh();
        $this->assertSame(BlueprintComponentInstallationState::Installed, $fresh->state);
        $this->assertSame(4242, $fresh->installed_record_id);
        $this->assertNull($fresh->installed_by_user_id, 'A system install records no actor.');
    }
}
