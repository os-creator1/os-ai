<?php

namespace App\Library\NicheBlueprint\Adapters;

use App\Models\Business;

/**
 * Contract 20 §10 — the seam a Blueprint component joins through.
 *
 * ONE ADAPTER PER TARGET MODULE, and each is additive: a new adapter is a new
 * class implementing this interface plus one registration line, touching no
 * existing adapter and no installer code. That is what makes later
 * Calendar/Packages/Proposal/Forms adapters independently mergeable (§11).
 *
 * An adapter NEVER contains copying logic of its own where the target module
 * already has a canonical "create a Business-owned row" seam — it translates
 * the descriptor and calls that seam. The first real adapter (Sub-slice D)
 * delegates to the existing `BusinessTemplateApplier` for exactly this reason.
 *
 * An adapter may only be written once its target module HAS such a seam (§11
 * rule 1), and a version naming a `component_type` with no registered adapter
 * cannot be published at all (§6.2 check 1) — so an installation can never
 * reach a missing installer.
 */
interface BlueprintComponentAdapter
{
    /**
     * The `niche_blueprint_components.component_type` discriminator this
     * adapter claims. Must be unique across registered adapters and at most 40
     * characters, matching the column.
     */
    public function componentType(): string;

    /**
     * Publish-time descriptor validation (§6.2 check 5). Throws when the
     * payload is malformed.
     *
     * Called by the publisher, never by the installer: a malformed component
     * fails where it is defined rather than half-way through copying into a
     * Business.
     *
     * @param  array<string, mixed>  $payload
     */
    public function validateDescriptor(array $payload): void;

    /**
     * Copy this component into rows the Business owns.
     *
     * Called inside the installer's per-component transaction, with the
     * Business row already locked (§7.2), and only ever for a component whose
     * entitlement decision was ALLOWED (§6.3) — an adapter never re-checks
     * entitlement and never consults `PlatformFeatureRegistry` itself.
     *
     * COPY, NEVER LINK: what it creates belongs to the Business outright, and
     * a later change to the Blueprint never reaches back into it.
     *
     * @param  array<string, mixed>  $payload
     */
    public function install(Business $business, array $payload, ?int $actorUserId): InstalledComponentReference;
}
