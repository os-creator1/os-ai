<?php

namespace App\Jobs\Seo;

use App\Jobs\Base;
use App\Library\Seo\Content\Autopilot\AutopilotArticleWriter;
use App\Models\ContentAutopilotDecision;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Content Autopilot - writing one article is long-running and paid, so it runs on the queue, once per decision
 * (ShouldBeUnique on the decision uid; the writer also claims the decision under a row lock and every model call carries
 * an idempotency key derived from the decision). The job carries only the decision id.
 */
class WriteAutopilotArticleJob extends Base implements ShouldBeUnique
{
    public int $timeout = 240;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $decisionId)
    {
    }

    public function uniqueId(): string
    {
        return 'content-autopilot-write:' . $this->decisionId;
    }

    public function handle(AutopilotArticleWriter $writer): void
    {
        $decision = ContentAutopilotDecision::query()->find($this->decisionId);

        if ($decision !== null) {
            $writer->write($decision);
        }
    }
}
