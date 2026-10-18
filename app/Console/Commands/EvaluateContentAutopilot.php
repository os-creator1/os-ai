<?php

namespace App\Console\Commands;

use App\Library\Seo\Content\Autopilot\AutopilotPlanner;
use App\Models\Business;
use Illuminate\Console\Command;

/**
 * Content Autopilot - run the decision step for ONE Business and show why. Dry run by default: nothing is written and no
 * AI or provider call is made (the planner is deterministic); pass --persist to record the decision.
 */
class EvaluateContentAutopilot extends Command
{
    protected $signature = 'content:autopilot-evaluate {business : Business id or uid} {--persist : Record the decision (default is a dry run)}';

    protected $description = 'Show what Content Autopilot would decide for a Business, and why (dry run unless --persist)';

    public function handle(AutopilotPlanner $planner): int
    {
        $ref = (string) $this->argument('business');
        $business = Business::query()->where(ctype_digit($ref) ? 'id' : 'uid', $ref)->first();

        if ($business === null) {
            $this->error('No such Business.');

            return self::FAILURE;
        }

        $result = $planner->evaluate($business, (bool) $this->option('persist'));

        $this->line('Decision: ' . $result['decision'] . ' (' . $result['reason'] . ')' . ($result['record'] === null ? '  [dry run - nothing written]' : '  [recorded]'));

        if ($result['selected'] !== null) {
            $this->line('Selected: ' . $result['selected']['title'] . '  score ' . $result['selected']['score']);
        }

        $this->table(['score', 'band', 'topic', 'why'], array_map(fn (array $s) => [
            $s['score'],
            $s['band'] . ($s['blocked'] ? ' (in progress)' : ''),
            $s['title'],
            $s['disqualified'] ?? implode(', ', $s['flags']),
        ], $result['ranked']));

        return self::SUCCESS;
    }
}
