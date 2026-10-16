<?php

namespace App\Library\Assistant\Tools;

use App\Library\Assistant\ActionOutcome;
use App\Library\Assistant\ActionPreview;
use App\Library\Assistant\AssistantContext;

/**
 * A write the assistant can PREPARE. It never runs from model text: the model may only name the tool and offer
 * arguments; the server validates them, resolves every reference through the module's own readers (so the Location
 * ACL applies), shows a preview, and runs `execute()` only after the customer's explicit approval — having first
 * re-checked authorization and that the previewed situation still holds.
 *
 * `execute()` must go through the existing domain service (never raw SQL, never a controller bypass), and runs inside
 * the proposal's transaction so a failure leaves nothing half-done.
 */
interface ActionTool
{
    public const MODE_EXECUTE = 'execute';

    /** The module's own endpoint performs the consequential step after the customer reviews it there. */
    public const MODE_HANDOFF = 'handoff';

    public function key(): string;

    public function description(): string;

    /** @return array<string, mixed> */
    public function rules(): array;

    public function mode(): string;

    public function available(AssistantContext $context): bool;

    /**
     * @param  array<string, mixed>  $args  validated against rules()
     *
     * @throws \App\Library\Assistant\AssistantMissingInformation
     * @throws \App\Library\Assistant\AssistantActionRefused
     */
    public function prepare(AssistantContext $context, array $args): ActionPreview;

    /**
     * A hash of the live state the preview depends on, recomputed at approval; a different value means the situation
     * changed and the proposal is stale. @param array<string, mixed> $resolved
     *
     * @throws \App\Library\Assistant\AssistantActionRefused when something the action needs no longer exists/is reachable
     */
    public function fingerprint(AssistantContext $context, array $resolved): string;

    /**
     * @param  array<string, mixed>  $resolved
     *
     * @throws \App\Library\Assistant\AssistantActionRefused
     */
    public function execute(AssistantContext $context, array $resolved): ActionOutcome;
}
