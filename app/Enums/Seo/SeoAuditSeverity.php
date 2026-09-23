<?php

namespace App\Enums\Seo;

/**
 * Contract 18 §8.7 — how much a finding matters. Closed, and owned by
 * SeoAuditRuleRegistry: a rule's severity is declared once in the registry,
 * never chosen per run and never supplied by a caller.
 *
 * `Critical` has no rule in registry v1. It is declared because the schema
 * and the counters carry it and a later rule set may need it; an empty
 * severity today is honest, whereas inventing a critical rule to fill it
 * would be exactly the "extra SEO advice" §8.7 forbids.
 */
enum SeoAuditSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Suggestion',
            self::Warning => 'Needs attention',
            self::Critical => 'Critical',
        };
    }
}
