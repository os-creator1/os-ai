<?php

namespace App\Jobs\Seo;

use App\Jobs\Base;
use App\Library\Seo\Content\Autopilot\AutopilotMaintainer;
use App\Models\Business;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Content Autopilot - the weekly review of one Business's existing articles (see AutopilotMaintainer). Deterministic and free;
 * the only paid step is the WriteAutopilotArticleJob it may queue for a rewrite. Unique per Business.
 */
class MaintainAutopilotArticlesJob extends Base implements ShouldBeUnique
{
    public int $timeout = 180;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $businessId)
    {
    }

    public function uniqueId(): string
    {
        return 'content-autopilot-maintain:' . $this->businessId;
    }

    public function handle(AutopilotMaintainer $maintainer): void
    {
        $business = Business::query()->find($this->businessId);

        if ($business !== null) {
            $maintainer->run($business);
        }
    }
}
