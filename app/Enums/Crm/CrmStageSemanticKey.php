<?php

namespace App\Enums\Crm;

/**
 * Semantic stage keys the PRODUCT itself relies on, independent of what a
 * Business calls the stage.
 *
 * `NewInquiry` is the canonical first stage of every standard pipeline: it
 * cannot be archived and always stays first. Automations, templates and (later)
 * forms address it by this key, never by its mutable name.
 *
 * A template may give its other stages keys of its own (`qualified`, ...); those
 * are template vocabulary, stored as plain strings, and deliberately not listed
 * here — a niche template must not need an enum change. A stage a customer adds
 * carries no semantic key at all.
 */
enum CrmStageSemanticKey: string
{
    case NewInquiry = 'new_inquiry';

    /** The shape every semantic key must have (templates included). */
    public const PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';
}
