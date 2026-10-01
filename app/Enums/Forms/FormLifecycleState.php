<?php

namespace App\Enums\Forms;

/**
 * Forms V1 — a form definition's lifecycle. Mirrors the precedent of
 * `CatalogItemLifecycleState` / `BusinessLocationLifecycleState` (a string
 * state, never a delete), with one extra state because a form has something a
 * catalog item does not: a moment before it is ever shown to the public.
 *
 *   Draft    — being built; accepts no submission.
 *   Active   — accepts submissions through its enabled deployments.
 *   Inactive — switched off; accepts nothing, history stays readable, and it
 *              can be activated again.
 *
 * Only `FormManager::activate()` / `deactivate()` move between them.
 */
enum FormLifecycleState: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Inactive = 'inactive';

    public function acceptsSubmissions(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Inactive => 'Switched off',
        };
    }
}
