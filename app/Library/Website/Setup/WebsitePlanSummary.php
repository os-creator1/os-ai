<?php

namespace App\Library\Website\Setup;

use App\Models\Business;
use App\Models\BusinessService;
use App\Models\QuestionnaireResponse;
use App\Models\Website;
use App\Models\WebsiteTemplate;
use App\Library\Website\WebsitePageStrategy;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Website V1 final — the Review screen's "Website plan": exactly which
 * pages Generate will build from the owner's answers, and WHY each other
 * candidate is not built — computed by the SAME deterministic planner that
 * Generate runs (WebsitePageStrategy::planWithExplanation), never a second
 * algorithm that could drift.
 *
 * Before Generate, the owner's services and backdrops are still answers, not
 * saved rows; they are fed to the planner as unsaved models in the order the
 * applier will give them, so the preview matches the real result.
 */
final class WebsitePlanSummary
{
    public function __construct(private readonly WebsitePageStrategy $strategy) {}

    /**
     * @return array{pages: array<int, array{title: string, type: string, included: bool, reason: string}>, included: array<int, array{title: string, reason: string}>, excluded: array<int, array{title: string, reason: string}>, areas: array{saved: int, planned: int, planned_list: array<int, string>, excluded: array<int, array{area: string, reason: string}>}, budget: array{max: int, planned: int}}
     */
    public function forResponse(Business $business, Website $website, WebsiteTemplate $template, QuestionnaireResponse $response): array
    {
        $services = $this->virtualServices($business, $response);

        $explained = $this->strategy->planWithExplanation(
            $business,
            $template,
            $website,
            WizardPresentationAnswers::customSection($response),
            WizardPresentationAnswers::catalogSelection($response),
            WizardPresentationAnswers::serviceAreas($response),
            $services,
            WizardPresentationAnswers::hasBackdrops($response),
        );

        $decisions = collect($explained['decisions']);

        return [
            'pages' => $decisions->map(fn (array $d) => ['title' => $d['title'], 'type' => $d['type'], 'included' => $d['included'], 'reason' => $d['reason']])->all(),
            'included' => $decisions->where('included', true)->map(fn (array $d) => ['title' => $d['title'], 'reason' => $d['reason']])->values()->all(),
            // Area pages are explained in the service-area block (saved / planned / why not), not twice.
            'excluded' => $decisions->where('included', false)->reject(fn (array $d) => str_starts_with($d['key'], 'area:'))->map(fn (array $d) => ['title' => $d['title'], 'reason' => $d['reason']])->values()->all(),
            'areas' => $explained['areas'],
            'budget' => $explained['budget'],
        ];
    }

    /**
     * @return Collection<int, BusinessService>
     */
    private function virtualServices(Business $business, QuestionnaireResponse $response): Collection
    {
        $rows = WizardPresentationAnswers::serviceRows($response);

        // A questionnaire with no service step (or none answered yet) falls
        // back to whatever services the Business already has saved.
        if ($rows === []) {
            return $this->strategy->eligibleServices($business);
        }

        return collect($rows)->map(function (array $row, int $position) {
            $service = new BusinessService([
                'name' => $row['name'],
                'slug' => Str::slug($row['name']),
                'description' => $row['description'],
                'sort_order' => $position,
            ]);
            // Unsaved: the planner only ever reads these (uid is for page keys).
            $service->uid = 'preview-' . $position;

            return $service;
        });
    }
}
