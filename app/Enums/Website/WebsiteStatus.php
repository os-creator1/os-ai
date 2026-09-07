<?php

namespace App\Enums\Website;

/**
 * Website Generation + Hosting Slice A contract §5.1/§6. Deliberately a
 * tiny, three-case vocabulary — draft (never published, or explicitly
 * taken down again after publishing), published (currently live), and
 * archived (owner-initiated, reversible take-down that retains all
 * pages/revisions/assets, preferred over hard delete per contract §34).
 * A plain string column, cast through this code-backed enum, matching
 * the repository's own established convention (BusinessStatus,
 * BusinessServiceStatus).
 */
enum WebsiteStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
