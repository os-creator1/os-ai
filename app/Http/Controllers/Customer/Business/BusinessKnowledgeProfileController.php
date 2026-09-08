<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Business\BusinessKnowledgeProfileFieldKey;
use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Business\BusinessKnowledgeProfileManager;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\BusinessKnowledgeProfile;
use App\Models\BusinessLocation;
use App\Models\BusinessVertical;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Website Guided Generation contract §3.3 (Option A, locked), §16 Slice
 * 2 -- the Business Knowledge Profile / completeness experience. A
 * dedicated, narrow, Workspace/Business-uid-scoped surface reusing the
 * identical resolveEntitledBusiness() chain Business\WebsiteController
 * already uses, gated by the same PlatformFeature::WebsiteGeneration
 * entitlement and the same `website` customer permission (§3.2: writing
 * the Profile always happens through the entitled Website guided-setup
 * surface) -- the legacy flat Customer\BusinessController is completely
 * untouched.
 *
 * All Profile/hours writes are delegated to
 * BusinessKnowledgeProfileManager -- this controller never writes
 * business_knowledge_profiles, business_knowledge_profile_field_states,
 * business_knowledge_profile_changes, or business_locations.hours
 * directly.
 */
class BusinessKnowledgeProfileController extends CustomerBaseController
{
    /**
     * Plain-language label/help/example copy for every
     * BusinessKnowledgeProfileFieldKey, used whenever the resolved
     * question pack (§6.3) does not itself cover a field -- including
     * when no pack has been seeded at all yet. Never vertical-specific
     * editorial content (that is an operator's question-pack authoring
     * job, §6.4); this is the generic fallback so the completeness page
     * is always meaningful.
     */
    private const FIELD_COPY = [
        'vertical_key' => ['label' => 'Business specialty', 'help' => 'If your trade has a specific specialty we support, pick it here. This is optional.'],
        'pricing_method' => ['label' => 'How do you price your work?', 'help' => 'Choose whichever best matches how you usually charge customers.'],
        'financing_available' => ['label' => 'Do you offer financing or payment plans?', 'help' => 'Let customers know up front if you offer a way to pay over time.'],
        'offers' => ['label' => 'Specific offers or packages', 'help' => "List a few offers customers can act on, e.g. \"Free estimate\" or \"Spring tune-up package\"."],
        'differentiators' => ['label' => 'What makes your business different?', 'help' => 'Short phrases, one per line, e.g. "Family owned since 1998" or "24/7 emergency service".'],
        'ideal_customers' => ['label' => 'Who are your ideal customers?', 'help' => 'A sentence or two describing the customers you do your best work for.'],
        'customer_problems' => ['label' => 'What problems do you solve for customers?', 'help' => 'One per line, e.g. "Leaky roofs" or "Overgrown lawns".'],
        'credentials' => ['label' => 'Licenses, certifications, or insurance', 'help' => 'List anything customers should know you carry.'],
        'years_operating' => ['label' => 'How many years have you been in business?', 'help' => 'A whole number, e.g. 12.'],
        'warranties_guarantees' => ['label' => 'Warranties or guarantees you offer', 'help' => 'Describe any warranty or guarantee in your own words.'],
        'primary_conversion_goal' => ['label' => "What's the main action you want website visitors to take?", 'help' => 'Choose the single most important next step for a visitor.'],
        'conversion_target' => ['label' => 'Where should that action go?', 'help' => 'A phone number (tel:...), an email address (mailto:...), or a link (https://...).'],
        'brand_voice' => ['label' => "How would you describe your brand's tone?", 'help' => 'e.g. "Friendly and down-to-earth" or "Professional and precise".'],
        'prohibited_claims' => ['label' => 'Anything we should never say about your business?', 'help' => 'One per line, e.g. "Never say we are the cheapest".'],
        'growth_priority_service_ids' => ['label' => 'Which services should we highlight most?', 'help' => 'Pick the services you most want new customers to notice.'],
        'growth_priority_location_ids' => ['label' => 'Which locations should we highlight most?', 'help' => 'Pick the locations you most want new customers to notice.'],
        'testimonials' => ['label' => 'Customer testimonials', 'help' => 'Real quotes from real customers, word for word -- never invented or reworded.'],
        'hours' => ['label' => 'Business hours', 'help' => 'Set your open and close times for each day.'],
    ];

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly EntitlementManager $entitlementManager,
        private readonly BusinessKnowledgeProfileManager $profileManager,
    ) {
    }

    public function show(string $workspaceUid, string $businessUid): View|Factory|Application
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

        $completeness = $this->profileManager->completenessCheck($business);

        return view('customer.business.website.knowledge-profile.show', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'business' => $business,
            'completeness' => $completeness,
            'fieldCopy' => self::FIELD_COPY,
            'locations' => $business->locations()->orderByDesc('is_primary')->get(),
        ]);
    }

    public function edit(string $workspaceUid, string $businessUid): View|Factory|Application
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

        $profile = BusinessKnowledgeProfile::where('business_id', $business->id)->first();
        $completeness = $this->profileManager->completenessCheck($business);

        return view('customer.business.website.knowledge-profile.edit', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'business' => $business,
            'profile' => $profile,
            'completeness' => $completeness,
            'fieldCopy' => self::FIELD_COPY,
            'verticals' => BusinessVertical::where('is_active', true)->orderBy('display_name')->get(),
            'services' => $business->services()->orderBy('sort_order')->get(),
            'locations' => $business->locations()->orderByDesc('is_primary')->get(),
        ]);
    }

    public function update(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

        $fields = $this->fieldsFromRequest($request);
        $reconfirmFieldKeys = $this->reconfirmFieldKeysFromRequest($request);

        $this->profileManager->updateFields(
            $business,
            $fields,
            'website_setup',
            (int) Auth::id(),
            markVerified: true,
            reconfirmFieldKeys: $reconfirmFieldKeys,
        );

        return redirect()->route('customer.workspaces.businesses.knowledge-profile.show', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => 'Business details saved.',
        ]);
    }

    public function updateHours(Request $request, string $workspaceUid, string $businessUid, string $locationUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

        $location = BusinessLocation::where('business_id', $business->id)->where('uid', $locationUid)->first();
        abort_unless($location !== null, 404);

        $hours = $this->hoursFromRequest($request);

        $this->profileManager->updateLocationHours(
            $business,
            $location,
            $hours,
            'website_setup',
            (int) Auth::id(),
            markVerified: true,
        );

        return redirect()->route('customer.workspaces.businesses.knowledge-profile.show', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => 'Business hours saved.',
        ]);
    }

    /**
     * Translates raw POST input into the typed shape
     * BusinessKnowledgeProfileManager::updateFields() expects.
     *
     * Correction (§10, non-destructive partial updates): a key is
     * included in the returned array ONLY when the request actually
     * presented it (Request::has(), or the dedicated "_presented"
     * marker for the two multi-selects below). An omitted key is left
     * out entirely -- the manager then leaves that field untouched,
     * rather than this method defaulting a missing key to null and
     * silently clearing it. The full edit form always presents every
     * key (so ordinary browser saves behave exactly as before); this
     * matters for any other caller of this action that submits a
     * narrower payload, since the browser's own form shape is never the
     * security/integrity boundary here -- the boundary is this explicit
     * has()/marker check.
     */
    private function fieldsFromRequest(Request $request): array
    {
        $fields = [];

        foreach (['vertical_key', 'pricing_method', 'ideal_customers', 'warranties_guarantees', 'primary_conversion_goal', 'conversion_target', 'brand_voice'] as $key) {
            if ($request->has($key)) {
                $fields[$key] = $this->blankToNull($request->input($key));
            }
        }

        if ($request->has('financing_available')) {
            $fields['financing_available'] = match ($request->input('financing_available')) {
                '1' => true,
                '0' => false,
                default => null,
            };
        }

        if ($request->has('offers')) {
            $fields['offers'] = $this->rowsFromRequest($request, 'offers', 'name', ['name', 'description', 'price_label', 'pricing_method_override']);
        }

        if ($request->has('differentiators')) {
            $fields['differentiators'] = $this->linesFromRequest($request, 'differentiators');
        }

        if ($request->has('customer_problems')) {
            $fields['customer_problems'] = $this->linesFromRequest($request, 'customer_problems');
        }

        if ($request->has('credentials')) {
            $fields['credentials'] = $this->rowsFromRequest($request, 'credentials', 'label', ['label', 'verified']);
        }

        if ($request->has('years_operating')) {
            $fields['years_operating'] = $request->filled('years_operating') ? (int) $request->input('years_operating') : null;
        }

        if ($request->has('prohibited_claims')) {
            $fields['prohibited_claims'] = $this->linesFromRequest($request, 'prohibited_claims');
        }

        if ($request->has('testimonials')) {
            $fields['testimonials'] = $this->rowsFromRequest($request, 'testimonials', 'quote', ['quote', 'author_name', 'author_title']);
        }

        // Correction (§6, clearable priority selections): a native
        // <select multiple> submits no key at all for its name when
        // nothing is selected -- indistinguishable, by Request::has()
        // alone, from the control never having been rendered at all.
        // The edit form always renders a dedicated hidden
        // "..._presented" marker alongside each multi-select, so
        // "control shown, customer cleared every selection" (marker
        // present, array key absent -> write []) is distinguished from
        // "control never rendered / field omitted from this request"
        // (marker absent -> leave untouched entirely).
        if ($request->has('growth_priority_service_ids_presented')) {
            $fields['growth_priority_service_ids'] = array_map('intval', $this->arrayInput($request, 'growth_priority_service_ids'));
        }

        if ($request->has('growth_priority_location_ids_presented')) {
            $fields['growth_priority_location_ids'] = array_map('intval', $this->arrayInput($request, 'growth_priority_location_ids'));
        }

        return $fields;
    }

    private function blankToNull(mixed $value): ?string
    {
        return ($value === null || $value === '') ? null : $value;
    }

    /**
     * Correction (§7, stale-value reconfirmation): a stale field's
     * "Confirm this is still correct" checkbox in the edit form submits
     * `reconfirm[<field_key>] = 1`. This is a plain list of field_key
     * strings the customer explicitly reconfirmed -- BusinessKnowledge
     * ProfileManager::updateFields() itself decides whether a given
     * reconfirmed key actually resulted in an unchanged value (a
     * field-state-only refresh) versus an actual value change (a
     * normal write); this method does no such classification.
     *
     * @return array<int, string>
     */
    private function reconfirmFieldKeysFromRequest(Request $request): array
    {
        $raw = $request->input('reconfirm', []);

        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_map('strval', array_keys(array_filter($raw, fn ($value) => $value === '1' || $value === true))));
    }

    private function arrayInput(Request $request, string $key): array
    {
        $value = $request->input($key, []);

        return is_array($value) ? $value : [];
    }

    private function linesFromRequest(Request $request, string $key): ?array
    {
        $raw = (string) $request->input($key, '');
        $lines = array_values(array_filter(array_map('trim', explode("\n", $raw)), fn ($line) => $line !== ''));

        return $lines === [] ? null : $lines;
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function rowsFromRequest(Request $request, string $key, string $identifyingColumn, array $columns): ?array
    {
        $rows = $request->input($key, []);

        if (! is_array($rows)) {
            return null;
        }

        $normalized = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row[$identifyingColumn]) || trim((string) $row[$identifyingColumn]) === '') {
                continue;
            }

            $entry = [];

            foreach ($columns as $column) {
                $value = $row[$column] ?? null;

                if ($column === 'verified') {
                    $entry[$column] = $value === '1' || $value === true;

                    continue;
                }

                $value = is_string($value) ? trim($value) : $value;
                $entry[$column] = ($value === '' || $value === null) ? null : $value;
            }

            $normalized[] = $entry;
        }

        return $normalized === [] ? null : $normalized;
    }

    private function hoursFromRequest(Request $request): array
    {
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $hours = [];

        foreach ($days as $day) {
            $periods = $request->input($day, []);
            $normalized = [];

            if (is_array($periods)) {
                foreach ($periods as $period) {
                    $open = is_array($period) ? ($period['open'] ?? null) : null;
                    $close = is_array($period) ? ($period['close'] ?? null) : null;

                    if (is_string($open) && $open !== '' && is_string($close) && $close !== '') {
                        $normalized[] = ['open' => $open, 'close' => $close];
                    }
                }
            }

            $hours[$day] = $normalized;
        }

        $notes = $request->input('notes');
        $hours['notes'] = ($notes === null || trim((string) $notes) === '') ? null : trim((string) $notes);

        return $hours;
    }

    /**
     * Contract §2.2/§26.2 pattern -- mirrors
     * Business\WebsiteController::resolveEntitledBusiness() exactly:
     * Workspace by UID -> Business inside that Workspace ->
     * userCanAccessBusiness() -> fresh, always-recomputed entitlement
     * decision for PlatformFeature::WebsiteGeneration (the same feature
     * Website guided-setup itself is gated by, §3.2).
     *
     * @return array{0: \App\Models\Workspace, 1: Business}
     */
    private function resolveEntitledBusiness(string $workspaceUid, string $businessUid): array
    {
        $workspace = $this->workspaceRepository->findByUid($workspaceUid);

        if ($workspace === null || ! $workspace->is_active) {
            abort(404);
        }

        $business = $this->workspaceRepository->businessesForWorkspace($workspace)->firstWhere('uid', $businessUid);

        if ($business === null || ! $this->workspaceManager->userCanAccessBusiness((int) Auth::id(), $business)) {
            abort(404);
        }

        if ($business->status !== BusinessStatus::Active) {
            abort(404);
        }

        try {
            $decision = $this->entitlementManager->decide($workspace, $business, PlatformFeature::WebsiteGeneration->value, (int) Auth::id());
        } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
            abort(404);
        }

        if (! $decision->allowed) {
            abort(404);
        }

        return [$workspace, $business];
    }
}
