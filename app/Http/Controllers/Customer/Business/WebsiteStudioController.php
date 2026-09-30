<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Catalog\CatalogItemLifecycleState;
use App\Enums\Entitlement\PlatformFeature;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Models\Business;
use App\Models\CatalogItem;
use App\Models\QuestionnaireResponse;
use App\Models\Website;
use App\Models\WebsiteForm;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireSeeder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Website Builder redesign — the unified post-generation hub replacing
 * the old disconnected "Pages"/"Publish" dashboard cards
 * (show.blade.php). For an existing Website, this is always where
 * `businesses.website.show` lands — the wizard never re-runs here except
 * through the separate, explicit "Edit setup answers" action
 * (WebsiteWizardController::editSetupAnswers()).
 *
 * Four tabs, each a thin, connected view of a REAL canonical module —
 * never duplicated data:
 *  - website: today's Generate/Publish/Preview/History actions, reused
 *    verbatim (WebsiteController, unchanged).
 *  - packages: the Business's own CatalogItem rows (the same table the
 *    wizard's package step and the standalone, separately-flagged
 *    Catalog screen both read/write — never a website-only copy).
 *  - forms: the Business's own WebsiteForm rows.
 *  - questionnaires: the Business's own completed setup answers, plus
 *    the "Edit setup answers" entry point.
 */
class WebsiteStudioController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    private const VALID_TABS = ['website', 'packages', 'forms', 'questionnaires'];

    public function show(string $workspaceUid, string $businessUid, string $tab = 'website'): View|RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::WebsiteGeneration->value);

        $website = Website::where('business_id', $business->id)->first();

        if ($website === null) {
            return view('customer.business.website.empty', [
                'workspaceUid' => $workspaceUid,
                'businessUid' => $businessUid,
            ]);
        }

        if (! in_array($tab, self::VALID_TABS, true)) {
            $tab = 'website';
        }

        return view('customer.business.website.studio.shell', array_merge([
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'website' => $website,
            'tab' => $tab,
        ], $this->tabData($tab, $business, $website)));
    }

    /**
     * @return array<string, mixed>
     */
    private function tabData(string $tab, Business $business, Website $website): array
    {
        return match ($tab) {
            'website' => [
                'pageCount' => $website->pages()->count(),
                'mediaWarnings' => $website->guidedGenerationAttempts()->latest('id')->first()?->warnings ?? [],
            ],
            'packages' => [
                'catalogItems' => CatalogItem::where('business_id', $business->id)
                    ->where('lifecycle_state', CatalogItemLifecycleState::Active->value)
                    ->with('images')
                    ->orderBy('position')
                    ->get(),
            ],
            'forms' => [
                'forms' => WebsiteForm::where('business_id', $business->id)->get(),
            ],
            'questionnaires' => [
                'responses' => QuestionnaireResponse::where('business_id', $business->id)
                    ->where('questionnaire_definition_id', function ($query) {
                        $query->select('id')->from('questionnaire_definitions')->where('key', PhotoboothWebsiteSetupQuestionnaireSeeder::KEY);
                    })
                    ->latest('id')
                    ->get(),
            ],
            default => [],
        };
    }
}
