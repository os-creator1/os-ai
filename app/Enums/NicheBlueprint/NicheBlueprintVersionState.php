<?php

namespace App\Enums\NicheBlueprint;

/**
 * Contract 20 §5.2 — a Blueprint version's state.
 *
 * At most one `draft` and at most one `published` version may exist per
 * Blueprint, and MySQL enforces both through the STORED generated guard
 * columns `draft_guard` / `published_guard`
 * (2026_09_24_100002_create_niche_blueprint_versions_table.php). Any number of
 * `superseded` versions coexist, because each yields NULL in both guards.
 *
 * A version's CONTENT becomes immutable the moment it leaves `draft`: its
 * authoring fields (`notes`, `version_number`, `blueprint_id`,
 * `published_at`, `published_by_user_id`) and every one of its component rows
 * are never written again, so an installation made from it keeps citing
 * exactly what was published.
 *
 * That is deliberately NOT the same as "the row never receives an UPDATE".
 * Exactly one transition remains authorized — `Published -> Superseded` — and
 * it changes `state` alone. A version never returns to `Draft`, and a
 * `Superseded` version never changes again.
 *
 * `superseded` IS the archived/deprecated state. There is no fourth case and
 * no separate archive table — a version is retired by being superseded, and is
 * retained because installation records cite its `version_number` forever.
 *
 * DELIBERATELY NOT `App\Enums\Automation\Workflow\WorkflowVersionState`, which
 * has the same three cases and the same semantics. Importing an
 * Automations-domain enum here would couple two bounded contexts that have no
 * other relationship; §5.2 calls for this slice's own enum, and that is what
 * this is.
 */
enum NicheBlueprintVersionState: string
{
    /** Editable. The only state a component may be added to or removed from. */
    case Draft = 'draft';

    /** Live. Immutable. New installations are made from this version. */
    case Published = 'published';

    /** Replaced by a newer publish. Immutable, and retained as provenance. */
    case Superseded = 'superseded';

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Whether this version's AUTHORING CONTENT and component snapshot are
     * frozen — true for `Published` and `Superseded`.
     *
     * It does not mean the row can never receive an UPDATE: a `Published`
     * version may still make the single authorized lifecycle move to
     * `Superseded` (see the class docblock). Every authoring method in
     * NicheBlueprintPublisher refuses when this returns true; `supersede()`
     * is the one method that acts on a state where it does.
     */
    public function isImmutable(): bool
    {
        return $this !== self::Draft;
    }
}
