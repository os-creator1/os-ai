<?php

namespace App\Http\Controllers\Customer\Business\Ads;

use App\Http\Controllers\Customer\Business\Concerns\ResolvesAdsBusinessTenancy;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesMetaAdsBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Acquisition\AcquisitionPurposeException;
use App\Library\Acquisition\AcquisitionPurposeManager;
use App\Library\Acquisition\Economics\EconomicsCalculators;
use App\Models\AcquisitionPurpose;
use App\Models\Business;
use App\Models\CrmPipeline;
use App\Models\Form;
use App\Models\GoogleAdsCampaign;
use App\Models\MetaAdsCampaign;
use App\Models\Website;
use App\Models\WebsitePage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Acquisition Purpose V1 — "Ads > Goals & economics": the ONE place the owner
 * says what their ads are for, what a good result costs them, and which
 * campaigns serve which goal.
 *
 * Tenancy mirrors the Ads Overview (Workspace -> Business -> active Business ->
 * ads_basic_visibility or the full module). Reading needs either provider's read
 * capability; changing anything needs either provider's manage capability, and
 * every POST is View-As prohibited by the `ads.` route prefix. Nothing here
 * calls a provider or edits a campaign: assignment is a MotionGrove-side label.
 *
 * Money answers are in the BUSINESS currency. "I don't know yet" is stored as
 * unknown and is never turned into zero or a suggestion.
 */
class AdsGoalsController extends CustomerBaseController
{
    use ResolvesAdsBusinessTenancy;
    use ResolvesBusinessTenancy;
    use ResolvesMetaAdsBusinessTenancy;

    public function __construct(
        private readonly AcquisitionPurposeManager $purposes,
        private readonly EconomicsCalculators $calculators,
    ) {
    }

    public function show(string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business] = $this->resolveGoalsTenancy($workspaceUid, $businessUid, manage: false);

        $rows = [];

        foreach ($this->purposes->forBusiness($business, activeOnly: false) as $purpose) {
            $rows[] = [
                'purpose' => $purpose,
                'profile' => $purpose->economicsProfile(),
                'questions' => $this->questions($purpose),
                'googleCampaigns' => GoogleAdsCampaign::query()->where('business_id', $business->id)->where('acquisition_purpose_id', $purpose->id)->orderBy('name')->get(['uid', 'name']),
                'metaCampaigns' => MetaAdsCampaign::query()->where('business_id', $business->id)->where('acquisition_purpose_id', $purpose->id)->orderBy('name')->get(['uid', 'name']),
            ];
        }

        $website = Website::query()->where('business_id', $business->id)->first(['id']);

        return view('customer.business.ads.goals', [
            'workspaceUid' => (string) $workspace->uid,
            'businessUid' => (string) $business->uid,
            'business' => $business,
            'account' => null,
            'freshness' => null,
            'adsNav' => [],
            'adsProviders' => $this->adsProviderSwitcher((string) $workspace->uid, (string) $business->uid),
            'provider' => 'overview',
            'rows' => $rows,
            'canManage' => $this->canManage(),
            'currency' => strtoupper((string) $business->currency_code),
            'pipelines' => CrmPipeline::query()->where('business_id', $business->id)->whereNull('archived_at')->orderBy('position')->get(['id', 'name']),
            'forms' => Form::query()->where('business_id', $business->id)->orderBy('name')->get(['id', 'name']),
            'pages' => $website === null ? collect() : WebsitePage::query()->where('website_id', $website->id)->orderBy('title')->get(['id', 'title']),
            'unassigned' => [
                'google' => GoogleAdsCampaign::query()->where('business_id', $business->id)->whereNull('acquisition_purpose_id')->where('status', '<>', 'REMOVED')->orderBy('name')->get(['uid', 'name']),
                'meta' => MetaAdsCampaign::query()->where('business_id', $business->id)->whereNull('acquisition_purpose_id')->whereIn('status', ['ACTIVE', 'PAUSED'])->orderBy('name')->get(['uid', 'name']),
            ],
            'activePurposes' => $this->purposes->forBusiness($business),
        ]);
    }

    public function saveEconomics(Request $request, string $workspaceUid, string $businessUid, string $purposeUid): RedirectResponse
    {
        return $this->change($workspaceUid, $businessUid, function (Business $business) use ($request, $purposeUid): string {
            $purpose = $this->purposes->find($business, $purposeUid) ?? throw new AcquisitionPurposeException('That goal could not be found.');
            $this->purposes->saveEconomics($business, $purpose, [
                'answers' => (array) $request->input('answers', []),
                'unknown' => (array) $request->input('unknown', []),
            ]);

            return 'Saved. MotionGrove will judge ' . $purpose->name . ' against these numbers.';
        });
    }

    public function saveLinks(Request $request, string $workspaceUid, string $businessUid, string $purposeUid): RedirectResponse
    {
        return $this->change($workspaceUid, $businessUid, function (Business $business) use ($request, $purposeUid): string {
            $purpose = $this->purposes->find($business, $purposeUid) ?? throw new AcquisitionPurposeException('That goal could not be found.');
            $blank = static fn (mixed $v): ?int => ($v === null || $v === '') ? null : (int) $v;

            $this->purposes->saveLinks($business, $purpose, [
                'pipeline_id' => $blank($request->input('pipeline_id')),
                'form_id' => $blank($request->input('form_id')),
                'destination_type' => (string) $request->input('destination_type', AcquisitionPurpose::DESTINATION_NONE),
                'destination_page_id' => $blank($request->input('destination_page_id')),
                'destination_url' => (string) $request->input('destination_url', ''),
            ]);

            return 'Saved how ' . $purpose->name . ' connects to your pipeline, form and page.';
        });
    }

    public function assignCampaign(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        return $this->change($workspaceUid, $businessUid, function (Business $business) use ($request): string {
            $provider = (string) $request->input('provider');
            $purposeUid = $request->input('purpose_uid');

            $this->purposes->assignCampaign($business, $provider, (string) $request->input('campaign_uid'), is_string($purposeUid) ? $purposeUid : null);

            return $purposeUid ? 'Campaign assigned to its goal.' : 'Campaign unassigned.';
        });
    }

    public function store(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        return $this->change($workspaceUid, $businessUid, function (Business $business) use ($request): string {
            $pipeline = $request->input('pipeline_id');
            $purpose = $this->purposes->createManual($business, (string) $request->input('name'), ($pipeline === null || $pipeline === '') ? null : (int) $pipeline);

            return 'Goal "' . $purpose->name . '" added. Tell MotionGrove what a good result costs you below.';
        });
    }

    public function toggle(Request $request, string $workspaceUid, string $businessUid, string $purposeUid): RedirectResponse
    {
        return $this->change($workspaceUid, $businessUid, function (Business $business) use ($request, $purposeUid): string {
            $purpose = $this->purposes->find($business, $purposeUid) ?? throw new AcquisitionPurposeException('That goal could not be found.');
            $active = $request->boolean('active');
            $this->purposes->setActive($business, $purpose, $active);

            return $active ? $purpose->name . ' is active again.' : $purpose->name . ' is paused. Its campaigns are unassigned.';
        });
    }

    /**
     * @param  callable(Business): string  $apply  returns the success message; throws AcquisitionPurposeException to refuse
     */
    private function change(string $workspaceUid, string $businessUid, callable $apply): RedirectResponse
    {
        [, $business] = $this->resolveGoalsTenancy($workspaceUid, $businessUid, manage: true);

        $back = redirect()->route('customer.workspaces.businesses.ads.goals', [$workspaceUid, $businessUid]);

        try {
            return $back->with(['status' => 'success', 'message' => $apply($business)]);
        } catch (AcquisitionPurposeException $e) {
            return $back->with(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    /** @return array{0: \App\Models\Workspace, 1: Business} */
    private function resolveGoalsTenancy(string $workspaceUid, string $businessUid, bool $manage): array
    {
        $resolved = $this->resolveAdsTenancy($workspaceUid, $businessUid);

        $allowed = $manage ? $this->canManage() : (Gate::allows('view_google_ads') || Gate::allows('view_meta_ads'));

        if (! $allowed) {
            $this->authorize($manage ? 'manage_google_ads' : 'view_google_ads');
        }

        return $resolved;
    }

    private function canManage(): bool
    {
        return Gate::allows('manage_google_ads') || Gate::allows('manage_meta_ads');
    }

    /**
     * The purpose's questions with the owner's current answers.
     *
     * @return list<array<string, mixed>>
     */
    private function questions(AcquisitionPurpose $purpose): array
    {
        $schema = is_array($purpose->question_schema) && $purpose->question_schema !== []
            ? $purpose->question_schema
            : array_map(
                fn (string $key, array $definition): array => ['key' => $key] + $definition,
                array_keys($this->calculators->get($purpose->calculator_key)->inputs()),
                array_values($this->calculators->get($purpose->calculator_key)->inputs()),
            );

        $answers = $purpose->answers();
        $unknown = $purpose->unknownKeys();

        return array_map(fn (array $q): array => $q + [
            'value' => $answers[$q['key']] ?? null,
            'unknown' => in_array($q['key'], $unknown, true),
        ], $schema);
    }
}
