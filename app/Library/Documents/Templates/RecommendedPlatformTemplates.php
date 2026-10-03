<?php

namespace App\Library\Documents\Templates;

use App\Models\Business;
use Illuminate\Support\Collection;

/**
 * Implementation Contract 17B §6 — the ONLY door to platform-owned templates.
 *
 * A Business never lists, reads or uses a platform template directly: a
 * platform template is reachable only if it is in this collection (the active
 * platform templates referenced by the published blueprint of the Business's
 * niche). DocumentTemplateAccess consults it for every read/use decision, so a
 * forged or merely-guessed platform uid fails closed.
 *
 * Stage 5 ships the seam with nothing behind it: it returns an EMPTY
 * collection. The platform-template stage rebinds / replaces this class with
 * the niche-blueprint implementation; nothing else changes.
 */
class RecommendedPlatformTemplates
{
    /**
     * @return Collection<int, \App\Models\DocumentTemplate>
     */
    public function forBusiness(Business $business): Collection
    {
        return collect();
    }
}
