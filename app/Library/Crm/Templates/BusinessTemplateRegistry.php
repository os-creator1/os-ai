<?php

namespace App\Library\Crm\Templates;

use App\Enums\Crm\CrmStageSemanticKey;

/**
 * The internal Business Templates this installation knows.
 *
 * v1 ships exactly one: `generic`, a single general sales pipeline. Niche
 * templates (trades, salons, studios, ...) are deliberately not hard-coded yet;
 * each would be one more register() call with its own key, and nothing else in
 * the CRM domain changes.
 *
 * Bound as a singleton so tests (and later an admin-managed source) can register
 * templates without editing this file.
 */
class BusinessTemplateRegistry
{
    public const GENERIC = 'generic';

    /** @var array<string, BusinessTemplate> */
    private array $templates = [];

    public function __construct()
    {
        $this->register(self::genericTemplate());
    }

    public function register(BusinessTemplate $template): void
    {
        $this->templates[$template->key] = $template;
    }

    public function find(string $key): ?BusinessTemplate
    {
        return $this->templates[$key] ?? null;
    }

    public function generic(): BusinessTemplate
    {
        return $this->templates[self::GENERIC];
    }

    /** The one standard pipeline every Business can start from. */
    public static function genericTemplate(): BusinessTemplate
    {
        return new BusinessTemplate(self::GENERIC, 1, 'Standard', [
            new PipelineBlueprint('sales', 'Sales pipeline', [
                new StageBlueprint('New inquiry', CrmStageSemanticKey::NewInquiry->value),
                new StageBlueprint('Qualified', 'qualified'),
                new StageBlueprint('Proposal sent', 'proposal_sent'),
                new StageBlueprint('Negotiating', 'negotiating'),
            ]),
        ]);
    }
}
