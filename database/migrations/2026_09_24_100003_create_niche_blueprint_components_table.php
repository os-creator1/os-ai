<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contract 20 §5.3, Sub-slice A, migration 3 of 4 — the component descriptors.
 *
 * Attached to a VERSION, not to the Blueprint, so publishing a new version can
 * never mutate a component an existing installation was made from.
 *
 * `required_feature_key` IS NOT NULL, AND THAT IS THE POINT OF THIS TABLE.
 * Addendum §16's "Each component declares its required entitlement" is a
 * universal statement, not a default. A nullable column would put a
 * Blueprint-shaped hole straight through the invariant this domain exists to
 * enforce: a component published with no key would install into every Business
 * on every plan without `EntitlementManager::decide()` ever being consulted.
 * There is no ungated Blueprint component in V1, and a future component whose
 * product area has no `PlatformFeature` identity is unpublishable until the
 * relevant product authority defines one (§6.2, §15).
 *
 * `component_key` is the durable identity: it is what an installation record
 * points at, so "this Business already has this component" survives every later
 * version of the Blueprint. A version may change a component's `payload`,
 * `required_feature_key` or `position`; changing its `component_key` makes it a
 * DIFFERENT component.
 *
 * THE FOREIGN KEY IS COMPOSITE, ON PURPOSE. A plain key on
 * `blueprint_version_id` would prove only that the version row exists — it
 * would happily accept a version belonging to another Blueprint, while this
 * row's own denormalised `blueprint_id` claimed otherwise. Referencing
 * `(id, blueprint_id)` means a component can only ever belong to a version OF
 * THE BLUEPRINT IT NAMES, enforced by MySQL rather than by the publisher
 * behaving. This reproduces `automation_workflows.published_version_id`'s own
 * reasoning verbatim.
 *
 * `cascadeOnDelete` on `blueprint_version_id` is safe and deliberate: a DRAFT
 * version may be deleted outright, taking its components with it. A published
 * or superseded version is never deleted (§5.2's retention rule, and
 * `niche_blueprint_versions`' own restrictOnDelete to `niche_blueprints`).
 *
 * `payload` is validated at PUBLISH time by the component's own adapter (§6.2),
 * never at install time and never by the installer — `PipelineBlueprint`'s
 * "fails where it is defined rather than half-way through copying" rule, moved
 * to the publish boundary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('niche_blueprint_components', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('blueprint_version_id');

            // Denormalised so the composite foreign key below can prove this
            // component's version really belongs to the Blueprint it claims.
            $table->unsignedBigInteger('blueprint_id');

            $table->string('component_key', 64);
            $table->string('component_type', 40);

            // NEVER nullable. See the class docblock above.
            $table->string('required_feature_key', 64);

            $table->json('payload');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['blueprint_version_id', 'component_key'], 'nbc_version_component_unique');

            // The selector: one version's components, in order.
            $table->index(['blueprint_version_id', 'position'], 'nbc_version_position_index');

            $table->foreign(['blueprint_version_id', 'blueprint_id'], 'nbc_version_blueprint_foreign')
                ->references(['id', 'blueprint_id'])->on('niche_blueprint_versions')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('niche_blueprint_components');
    }
};
