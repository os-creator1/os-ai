<?php

namespace App\Http\Controllers\Customer\Workspace;

use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Controllers\Customer\Workspace\Concerns\ResolvesAgencyProspectingWorkspace;
use App\Library\AgencyOutreach\Analytics\AnalyticsFilters;
use App\Library\AgencyOutreach\Analytics\AnalyticsPresenter;
use App\Library\AgencyOutreach\Analytics\OutreachAnalytics;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AgencyProspect;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Outreach → Analytics and the per-message drill-down. Workspace-scoped; read-only. */
class AgencyOutreachAnalyticsController extends CustomerBaseController
{
    use ResolvesAgencyProspectingWorkspace;

    private const TABS = ['performance' => 'Performance', 'messages' => 'Messages', 'types' => 'Business types', 'timing' => 'Timing', 'explorer' => 'Explorer'];

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceMembershipRepository $membershipRepository,
        private readonly EntitlementManager $entitlementManager,
    ) {
    }

    public function dashboard(Request $request, string $workspaceUid)
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $filters = AnalyticsFilters::fromRequest($request);
        $analytics = new OutreachAnalytics($workspace, $filters);
        $tab = array_key_exists((string) $request->query('tab'), self::TABS) ? (string) $request->query('tab') : 'performance';

        $options = $this->options($workspace, $analytics);

        $data = [
            'workspaceUid' => $workspaceUid, 'filters' => $filters, 'tab' => $tab, 'tabs' => self::TABS,
            'options' => $options,
        ];

        if (in_array($tab, ['performance', 'messages'], true)) {
            $data['kpis'] = $analytics->kpis();
            $data['steps'] = AnalyticsPresenter::steps($workspace, $analytics->steps());
            $data['unattributed'] = $analytics->unattributedSends();
            $data['funnel'] = $analytics->funnel($data['kpis']);
        }

        if ($tab === 'messages') {
            $data['numbers'] = $analytics->numbers();
        }

        if ($tab === 'performance') {
            $data['segments'] = $analytics->segments('type');
            $data['bestWebsite'] = $analytics->segments('website');
        }

        if ($tab === 'types') {
            $data['dimensions'] = [
                'type' => ['Business category', $analytics->segments('type')],
                'rating' => ['Google rating', $analytics->segments('rating')],
                'reviews' => ['Review count', $analytics->segments('reviews')],
                'website' => ['Website', $analytics->segments('website')],
                'geo' => ['City / state', $analytics->segments('geo')],
                'source' => ['Lead source / import', $analytics->segments('source')],
                'campaign' => ['Campaign', $analytics->segments('campaign')],
                'group' => ['Contact group', $analytics->segments('group')],
            ];

            foreach (array_slice(array_filter($analytics->customFieldOptions(), fn ($o) => in_array($o['type'], ['text', 'select', 'boolean'], true)), 0, 6) as $field) {
                $rows = $analytics->segments('cf:' . $field['key']);

                if ($rows !== []) {
                    $data['dimensions']['cf:' . $field['key']] = [$field['label'] . ' (custom field)', $rows];
                }
            }
        }

        if ($tab === 'timing') {
            $data['timing'] = $analytics->timing();
        }

        if ($tab === 'explorer') {
            $page = max(1, (int) $request->query('page', 1));
            $reached = in_array($request->query('reached'), ['contacted', 'replied', 'interested', 'rejected', 'opted_out', 'booked', 'won', 'eligible'], true) ? $request->query('reached') : null;
            $result = $analytics->explorer($page, 25, $reached);
            $data += ['explorer' => $result, 'page' => $page, 'pages' => max(1, (int) ceil($result['total'] / 25)), 'reached' => $reached];
        }

        return view('customer.workspaces.prospecting.analytics', $data);
    }

    public function step(Request $request, string $workspaceUid, string $step)
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $filters = AnalyticsFilters::fromRequest($request);
        $analytics = new OutreachAnalytics($workspace, $filters);

        $labelled = collect(AnalyticsPresenter::steps($workspace, $analytics->steps()));
        $info = $labelled->firstWhere('step_key', $step);

        // A step the Workspace never sent (or a foreign id) is simply not found.
        abort_if($info === null, 404);

        $tab = in_array($request->query('tab'), ['replied', 'rejected', 'opted_out', 'no_response'], true) ? (string) $request->query('tab') : 'all';
        $page = max(1, (int) $request->query('page', 1));
        $list = $analytics->stepProspects($step, $tab, $page, 25);

        $next = $labelled->firstWhere('number', $info['number'] + 1);

        return view('customer.workspaces.prospecting.analytics-step', [
            'workspaceUid' => $workspaceUid, 'filters' => $filters, 'info' => $info, 'tab' => $tab, 'list' => $list,
            'page' => $page, 'pages' => max(1, (int) ceil($list['total'] / 25)), 'texts' => $analytics->stepTexts($step),
            'nextLabel' => $next['label'] ?? null, 'options' => $this->options($workspace, $analytics),
            'canEdit' => $info['state'] !== 'removed',
        ]);
    }

    public function export(Request $request, string $workspaceUid): StreamedResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $filters = AnalyticsFilters::fromRequest($request);
        $analytics = new OutreachAnalytics($workspace, $filters);
        $reached = in_array($request->query('reached'), ['contacted', 'replied', 'interested', 'rejected', 'opted_out', 'booked', 'won', 'eligible'], true) ? $request->query('reached') : null;

        return response()->streamDownload(function () use ($analytics, $reached) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Business', 'Phone', 'Campaign', 'Business type', 'Rating', 'Reviews', 'Website', 'State', 'City', 'Source', 'Group', 'Steps sent', 'Last message', 'Last reply', 'Reply seconds', 'Outcome', 'Booked', 'Client won']);

            for ($page = 1; $page <= 200; $page++) {
                $res = $analytics->explorer($page, 100, is_string($reached) ? $reached : null);

                foreach ($res['rows'] as $r) {
                    // Spreadsheet-safe: a value starting with = + - @ must not execute as a formula.
                    $cells = [$r->company_name, $r->phone, $r->campaign_name, $r->business_type, $r->google_rating, $r->review_count,
                        $r->has_website === null ? '' : ($r->has_website ? 'Yes' : 'No'), $r->state, $r->city, $r->source_name, $r->group_names, $r->steps_sent,
                        $r->last_out, $r->last_in, $r->reply_sec, $r->outcome, $r->is_booked ? 'Yes' : 'No', $r->is_won ? 'Yes' : 'No'];
                    fputcsv($out, array_map(fn ($c) => is_string($c) && preg_match('/^[=+\-@\t\r]/', $c) ? "'" . $c : $c, $cells));
                }

                if (count($res['rows']) < 100) {
                    break;
                }
            }

            fclose($out);
        }, 'outreach-analytics-' . now()->format('Ymd') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Filter dropdown values from real data; message steps are named the way the journey names them. */
    private function options($workspace, OutreachAnalytics $analytics): array
    {
        $options = $analytics->options();
        $options['steps'] = array_map(fn ($r) => ['key' => $r['step_key'], 'label' => $r['label']],
            AnalyticsPresenter::steps($workspace, array_map(fn ($r) => ['step_key' => $r->step_key, 'first_out_id' => (int) $r->first_out_id], $options['steps'])));

        return $options;
    }
}
