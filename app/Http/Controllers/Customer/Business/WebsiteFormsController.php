<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Website\WebsiteFormPresets;
use App\Library\Website\WebsiteStarterDraftService;
use App\Models\Business;
use App\Models\Website;
use App\Models\WebsiteForm;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * The business side of the Forms foundation: create the one Photo Booth
 * quote-request form and see who has submitted it. Runs the exact same
 * tenancy/entitlement chain Business\WebsiteController does
 * (PlatformFeature::WebsiteGeneration) — Forms are a Website capability,
 * not a separately entitled product.
 */
class WebsiteFormsController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    public function home(string $workspaceUid, string $businessUid): View
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);

        return view('customer.business.website.forms.index', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'website' => $website,
            'isPhotoBooth' => WebsiteStarterDraftService::isPhotoBooth($business),
            'form' => $website->forms()->where('type', WebsiteForm::TYPE_QUOTE_REQUEST)->first(),
        ]);
    }

    public function store(string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);

        // The Photo Booth quote-request preset is the only one this slice
        // ships; a niche it was never written for must never be offered
        // it, whether through the view or a direct request to this action.
        if (! WebsiteStarterDraftService::isPhotoBooth($business)) {
            throw ValidationException::withMessages([
                'form' => ['This form preset is only available for Photo Booth businesses.'],
            ]);
        }

        if ($website->forms()->where('type', WebsiteForm::TYPE_QUOTE_REQUEST)->exists()) {
            return redirect()->route('customer.workspaces.businesses.website.forms.index', [$workspaceUid, $businessUid]);
        }

        $website->forms()->create([
            'business_id' => $business->id,
            'type' => WebsiteForm::TYPE_QUOTE_REQUEST,
            'name' => 'Photo Booth Quote Request',
            'fields' => WebsiteFormPresets::photoBoothQuoteRequest(),
            'submit_label' => 'Request a quote',
        ]);

        return redirect()->route('customer.workspaces.businesses.website.forms.index', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => 'Quote request form created. Add a Form section to a page to publish it.',
        ]);
    }

    public function submissions(string $workspaceUid, string $businessUid, string $formUid): View
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);
        $form = $this->resolveForm($website, $formUid);

        return view('customer.business.website.forms.submissions', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'website' => $website,
            'form' => $form,
            'submissions' => $form->submissions()->latest()->paginate(25),
        ]);
    }

    /**
     * @return array{0: Workspace, 1: Business}
     */
    private function resolveEntitledBusiness(string $workspaceUid, string $businessUid): array
    {
        return $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::WebsiteGeneration->value);
    }

    private function resolveWebsite(Business $business): Website
    {
        $website = Website::where('business_id', $business->id)->first();

        abort_unless($website !== null, 404);

        return $website;
    }

    private function resolveForm(Website $website, string $formUid): WebsiteForm
    {
        $form = $website->forms()->where('uid', $formUid)->first();

        abort_unless($form !== null, 404);

        return $form;
    }
}
