<?php

namespace App\Jobs\Seo;

use App\Jobs\Base;
use App\Library\Seo\Content\Autopilot\AutopilotRunner;
use App\Models\Business;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Content Autopilot - one run for one Business (see AutopilotRunner). Deterministic and free; the paid step is the
 * WriteAutopilotArticleJob it may queue. Unique per Business so two sweeps can never run it twice at once.
 */
class RunContentAutopilotJob extends Base implements ShouldBeUnique
{
    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $businessId)
    {
    }

    public function uniqueId(): string
    {
        return 'content-autopilot-run:' . $this->businessId;
    }

    public function handle(AutopilotRunner $runner): void
    {
        $business = Business::query()->find($this->businessId);

        if ($business !== null) {
            $runner->run($business);
        }
    }
}
