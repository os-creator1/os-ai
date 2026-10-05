<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Website\Design\WebsiteLookService;
use App\Library\Website\Design\WebsiteTemplatePreviewRenderer;
use App\Library\Website\GuidedGeneration\WebsiteGenerationCoordinator;
use App\Library\Website\Setup\Exceptions\GenerationInProgressException;
use App\Library\Website\Setup\QuestionnaireResolver;
use App\Library\Website\WebsiteStarterDraftService;
use App\Models\Business;
use App\Models\Website;
use App\Models\WebsiteTemplate;
use App\Rules\ValidWebsiteImageRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Website V1 final — the owner's template and look choices, kept out of the
 * (already large) setup wizard controller:
 *
 *  - templatePreview(): the REAL renderer showing the owner's own business in
 *    a template, used by the four preview cards on the Review screen and the
 *    rebuild flow (framed, scaled down — never a wireframe).
 *  - update(): the one POST behind the "Your website's look" panel — a
 *    template choice (only while no pages are generated yet; after that the
 *    existing rebuild flow, with its layout-change warning, is the way), the
 *    one brand colour, a logo and a hero image. Controller stays thin: every
 *    rule lives in WebsiteLookService / WebsiteStarterDraftService.
 */
class WebsiteLookController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    public function __construct(
        private readonly QuestionnaireResolver $questionnaireResolver,
        private readonly WebsiteStarterDraftService $starterDrafts,
        private readonly WebsiteLookService $look,
        private readonly WebsiteTemplatePreviewRenderer $previews,
        private readonly WebsiteGenerationCoordinator $generationCoordinator,
    ) {
    }

    public function templatePreview(string $workspaceUid, string $businessUid, string $templateKey): Response
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

        $template = $this->templatesFor($business)->firstWhere('key', $templateKey);
        abort_unless($template !== null, 404);

        return response($this->previews->render($business, Website::where('business_id', $business->id)->first(), $template))
            ->header('X-Frame-Options', 'SAMEORIGIN');
    }
    public function update(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = Website::where('business_id', $business->id)->first();
        abort_unless($website !== null, 404);

        $request->validate([
            'template_key' => 'nullable|string|max:40',
            'brand_color' => 'nullable|string|max:20',
            'logo' => ['nullable', 'file', new ValidWebsiteImageRule()],
            'hero' => ['nullable', 'file', new ValidWebsiteImageRule()],
            'logo_alt' => 'nullable|string|max:160',
            'hero_alt' => 'nullable|string|max:160',
            'remove_logo' => 'nullable|boolean',
            'remove_hero' => 'nullable|boolean',
        ]);

        $back = redirect()->back();
        $notes = [];

        try {
            $this->generationCoordinator->runExclusive($website, function (Website $locked) use ($request, $business, &$notes) {
                $templateKey = $request->input('template_key');

                if (is_string($templateKey) && $templateKey !== '' && $templateKey !== $locked->template_key) {
                    $template = $this->templatesFor($business)->firstWhere('key', $templateKey);

                    if ($template === null) {
                        throw ValidationException::withMessages(['template_key' => ['Choose one of the available templates.']]);
                    }

                    if ($locked->pages()->exists()) {
                        throw ValidationException::withMessages(['template_key' => ['This website is already built. Use "Rebuild with a different template" to change its layout.']]);
                    }

                    $this->starterDrafts->updateShellTemplate($locked, $template);
                    $notes[] = 'Template updated.';
                }

                if ($request->has('brand_color')) {
                    $this->look->setBrandColor($locked, $request->input('brand_color'));
                    $notes[] = 'Brand colour saved.';
                }

                if ($request->boolean('remove_logo')) {
                    $this->look->removeLogo($locked);
                    $notes[] = 'Logo removed.';
                } elseif ($request->hasFile('logo')) {
                    $this->look->setLogo($locked, $request->file('logo'), $request->input('logo_alt'));
                    $notes[] = 'Logo saved.';
                }

                if ($request->boolean('remove_hero')) {
                    $this->look->removeHero($locked);
                    $notes[] = 'Hero image removed.';
                } elseif ($request->hasFile('hero')) {
                    $this->look->setHero($locked, $request->file('hero'), $request->input('hero_alt'));
                    $notes[] = 'Hero image saved.';
                }
            });
        } catch (GenerationInProgressException $e) {
            return $back->with(['status' => 'error', 'message' => $e->getMessage()]);
        }

        return $back->with(['status' => 'success', 'message' => $notes === [] ? 'Nothing to change.' : implode(' ', array_unique($notes))]);
    }

    /**
     * @return Collection<int, WebsiteTemplate>
     */
    private function templatesFor(Business $business): Collection
    {
        $nicheKey = $this->questionnaireResolver->nicheKeyFor($business);

        return WebsiteTemplate::where('is_active', true)
            ->where(function ($query) use ($nicheKey) {
                $query->whereNull('niche_key');

                if ($nicheKey !== null) {
                    $query->orWhere('niche_key', $nicheKey);
                }
            })
            ->orderBy('key')
            ->get();
    }

    private function resolveEntitledBusiness(string $workspaceUid, string $businessUid): array
    {
        return $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::WebsiteGeneration->value);
    }
}
