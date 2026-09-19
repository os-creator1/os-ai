<?php

namespace App\Enums\Seo;

/**
 * Contract 18 §8.4 — an SEO keyword is archived, never deleted (the codebase's
 * lifecycle-column convention, mirroring BusinessLocation). Only
 * SeoKeywordManager::archive()/reactivate() write it.
 */
enum SeoKeywordLifecycleState: string
{
    case Active = 'active';
    case Archived = 'archived';
}
