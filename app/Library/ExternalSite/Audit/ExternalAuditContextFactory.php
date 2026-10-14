<?php

namespace App\Library\ExternalSite\Audit;

use App\Library\ExternalSite\ExternalSiteConfig;
use App\Library\Seo\SeoConfig;
use App\Models\Business;

/**
 * Builds the evaluator's ExternalAuditContext from a Business: its name and phone,
 * the cities of its active locations, and the length thresholds from the SEO
 * config (the same conventional guidance the hosted audit uses; never Google-stated
 * requirements). The only database reads in the evaluation path, kept out of the
 * pure evaluator.
 */
final class ExternalAuditContextFactory
{
    public function __construct(private readonly SeoConfig $seo, private readonly ExternalSiteConfig $config)
    {
    }

    public function forBusiness(Business $business): ExternalAuditContext
    {
        $locations = $business->activeLocations()->get(['city']);
        $cities = $locations->pluck('city')->map(fn ($c): string => trim((string) $c))->filter()->unique()->values()->all();
        $digits = preg_replace('/\D+/', '', (string) $business->phone) ?? '';

        return new ExternalAuditContext(
            businessName: trim((string) $business->name),
            phoneDigits: strlen($digits) >= 10 ? $digits : null,
            cities: $cities,
            hasLocation: $locations->isNotEmpty(),
            titleMax: $this->seo->auditSeoTitleMaxRecommended(),
            descriptionMin: $this->seo->auditMetaDescriptionMinRecommended(),
            largeImageBytes: $this->config->largeImageBytes(),
            deepPageDepth: $this->config->deepPageDepth(),
        );
    }
}
