<?php

namespace App\Library\ExternalSite;

use App\Library\ExternalSite\Audit\ExternalAuditCatalog;
use App\Library\ExternalSite\Audit\ExternalPageImportance;
use App\Models\Business;
use App\Models\ExternalSiteCrawl;
use App\Models\ExternalSitePlanItem;
use Illuminate\Support\Collection;

/**
 * The Website Improvement Plan: the audit's findings turned into a prioritised list
 * of changes the owner (or their developer) can act on, with every item carrying
 *
 *   - a proposed change,
 *   - the exact affected URLs and what was observed on each,
 *   - why it matters to a local business (no traffic, ranking or revenue projection),
 *   - how to implement it (written for the owner's own CMS: MotionGrove cannot edit an
 *     external website),
 *   - how the next check will verify it,
 *   - the owner's own tracking state and, when the change is made, whether the next
 *     check actually confirmed it.
 *
 * SECTIONS keep what is KNOWN apart from what is SUGGESTED: confirmed technical
 * problems, SEO improvements and growth opportunities are separate lists.
 *
 * ORDER is deterministic and puts what stops a visitor from reaching or contacting
 * you first: priority, then whether the issue touches a contact/booking page, then
 * the home page, then the kind (problems before improvements before opportunities),
 * then the number of pages. A long list of low-priority items never outranks one
 * booking-critical problem.
 *
 * Pure over (issue groups, tracked items): no database, no network, no AI.
 */
final class ExternalImprovementPlan
{
    /** Items shown under "Start here". */
    public const START_HERE = 5;

    /**
     * @param  list<array<string, mixed>>  $groups  ExternalWebsiteReader::issueGroups()
     * @param  Collection<string, ExternalSitePlanItem>  $tracked  by rule key
     * @return array<string, mixed>
     */
    public function build(array $groups, Business $business, ExternalSiteCrawl $crawl, Collection $tracked, ?string $helperEmail = null): array
    {
        $items = [];
        $observations = [];

        foreach ($groups as $group) {
            if ($group['kind'] === 'info' || $group['priority'] === 'info') {
                $observations[] = $group;

                continue;
            }

            $items[] = $this->item($group, $business, $crawl, $tracked->get($group['rule_key']), $helperEmail);
        }

        usort($items, fn (array $a, array $b): int => $b['sort'] <=> $a['sort']);

        $active = array_values(array_filter($items, fn (array $i): bool => ! in_array($i['tracking']['status'], [ExternalSitePlanItem::WONT_DO], true) && ! $i['tracking']['verified']));
        $sections = ['problem' => [], 'improvement' => [], 'opportunity' => []];

        foreach ($items as $item) {
            $sections[$item['kind']][] = $item;
        }

        // Tracked items whose rule is no longer present: show the verified fixes.
        $verified = [];

        foreach ($tracked as $ruleKey => $tracking) {
            if (ExternalAuditCatalog::has((string) $ruleKey) && ! collect($groups)->contains('rule_key', $ruleKey) && $tracking->status === ExternalSitePlanItem::DONE) {
                $verified[] = ['rule_key' => (string) $ruleKey, 'title' => ExternalAuditCatalog::rule((string) $ruleKey)['title'], 'verified' => $tracking->resolved_in_crawl_id !== null, 'status_changed_at' => $tracking->status_changed_at];
            }
        }

        return [
            'start_here' => array_slice($active, 0, self::START_HERE),
            'sections' => $sections,
            'observations' => $observations,
            'verified' => $verified,
            'counts' => [
                'items' => count($items),
                'problems' => count($sections['problem']),
                'improvements' => count($sections['improvement']),
                'opportunities' => count($sections['opportunity']),
                'done' => count(array_filter($items, fn (array $i): bool => $i['tracking']['status'] === ExternalSitePlanItem::DONE)),
            ],
            'full_text' => $this->fullText($items, $business, $crawl),
        ];
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    private function item(array $group, Business $business, ExternalSiteCrawl $crawl, ?ExternalSitePlanItem $tracking, ?string $helperEmail): array
    {
        $hasKey = false;
        $hasHome = false;

        foreach ($group['rows'] as $row) {
            $hasKey = $hasKey || $row['importance'] === ExternalPageImportance::KEY;
            $hasHome = $hasHome || $row['importance'] === ExternalPageImportance::HOME;
        }

        $status = $tracking?->status ?? ExternalSitePlanItem::OPEN;
        $verified = $tracking !== null && $tracking->status === ExternalSitePlanItem::DONE && $tracking->resolved_in_crawl_id !== null;
        $stillPresent = $tracking !== null && $tracking->status === ExternalSitePlanItem::DONE && $tracking->resolved_in_crawl_id === null;

        return $group + [
            'item_key' => ExternalSitePlanItem::keyFor($group['rule_key']),
            'sort' => ExternalWebsiteReader::rank($group),
            'relevance' => $this->relevance($group, $hasKey, $hasHome),
            'urls' => $this->urls($group),
            'tracking' => [
                'status' => $status,
                'status_label' => ExternalSitePlanItem::STATUSES[$status] ?? 'Not started',
                'note' => $tracking?->note,
                'verified' => $verified,
                'still_present_after_done' => $stillPresent,
                'changed_at' => $tracking?->status_changed_at,
            ],
            'brief' => $this->brief($group, $business, $crawl),
            'mailto' => $helperEmail === null ? null : 'mailto:'.rawurlencode($helperEmail).'?subject='.rawurlencode('Website change request: '.$group['title']).'&body='.rawurlencode($this->brief($group, $business, $crawl)),
        ];
    }

    /**
     * Why this matters to THIS business: the catalogue's reason plus which of its important pages are touched. No numbers
     * about traffic, rankings or revenue.
     *
     * @param  array<string, mixed>  $group
     */
    private function relevance(array $group, bool $hasKey, bool $hasHome): string
    {
        $touches = [];

        if ($hasHome) {
            $touches[] = 'your home page';
        }

        if ($hasKey) {
            $paths = [];

            foreach ($group['rows'] as $row) {
                if ($row['importance'] === ExternalPageImportance::KEY && $row['path'] !== null) {
                    $paths[$row['path']] = true;
                }
            }

            $touches[] = 'pages where visitors contact, book or choose a service ('.implode(', ', array_slice(array_keys($paths), 0, 3)).')';
        }

        $text = $group['why'];

        if ($touches !== []) {
            $text .= ' This affects '.implode(' and ', $touches).'.';
        }

        return $text;
    }

    /**
     * The exact affected URLs, one per page (a page appears once, with what was observed on it).
     *
     * @param  array<string, mixed>  $group
     * @return list<array{url: ?string, path: ?string, observed: string, page_id: ?int, importance: string}>
     */
    private function urls(array $group): array
    {
        $seen = [];
        $out = [];

        foreach ($group['rows'] as $row) {
            $k = ($row['page_url'] ?? '').'|'.$row['observed'];

            if (isset($seen[$k])) {
                continue;
            }

            $seen[$k] = true;
            $out[] = ['url' => $row['page_url'], 'path' => $row['path'], 'observed' => $row['observed'], 'page_id' => $row['page_id'], 'importance' => $row['importance']];
        }

        return $out;
    }

    /**
     * A plain-text work order the owner can paste into an email or hand to a developer.
     *
     * @param  array<string, mixed>  $group
     */
    private function brief(array $group, Business $business, ExternalSiteCrawl $crawl): string
    {
        $lines = [];
        $lines[] = 'Website change request: '.$group['title'];
        $lines[] = 'Website: '.$crawl->start_url.'   |   Found by the MotionGrove audit on '.($crawl->finished_at?->format('M j, Y') ?? 'a recent check');
        $lines[] = '';
        $lines[] = 'PROPOSED CHANGE';
        $lines[] = $group['proposal'];
        $lines[] = '';
        $lines[] = 'WHAT WAS FOUND ('.$group['row_count'].' place(s), '.max($group['page_count'], 0).' page(s))';

        foreach (array_slice($this->urls($group), 0, 15) as $u) {
            $lines[] = '- '.($u['url'] ?? 'Whole website').': '.$u['observed'];
        }

        if ($group['row_count'] > 15) {
            $lines[] = '- ...and more (see the MotionGrove audit).';
        }

        $lines[] = '';
        $lines[] = 'WHY IT MATTERS';
        $lines[] = $group['why'];
        $lines[] = '';
        $lines[] = 'HOW TO DO IT';

        foreach ($group['steps'] as $i => $step) {
            $lines[] = ($i + 1).'. '.$step;
        }

        $lines[] = '';
        $lines[] = 'HOW IT WILL BE CHECKED';
        $lines[] = $group['verify'];

        return implode("\n", $lines);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function fullText(array $items, Business $business, ExternalSiteCrawl $crawl): string
    {
        $text = 'Website Improvement Plan for '.trim((string) $business->name).' ('.$crawl->start_url.")\nFrom the MotionGrove audit of ".($crawl->finished_at?->format('M j, Y') ?? 'a recent check').". Priorities come from what was observed on the site; no traffic, ranking or revenue is projected.\n";

        foreach ($items as $i => $item) {
            $text .= "\n".str_repeat('=', 60)."\n".($i + 1).'. ['.strtoupper($item['priority']).'] '.$item['brief']."\n";
        }

        return $text;
    }
}
