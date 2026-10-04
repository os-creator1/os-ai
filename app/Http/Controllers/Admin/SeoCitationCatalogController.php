<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Business\BusinessIndustry;
use App\Enums\Seo\SeoDirectoryImportance;
use App\Enums\Seo\SeoDirectoryTrackingMode;
use App\Exceptions\Seo\SeoCitationCatalogException;
use App\Library\Seo\SeoCitationCatalogManager;
use App\Models\SeoCitationDirectory;
use App\Models\SeoNicheCitationRecommendation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Citations V1 — the Platform Owner's "Citation Directories" (the catalog) and
 * "Citation Niches" (per-niche recommendations) screens.
 *
 * Thin: every rule lives in SeoCitationCatalogManager, which re-checks
 * platform-administrator authority itself on top of the route's
 * 'can:access backend' + EnsureUserIsAdministrator group middleware. Nothing
 * here touches a Business's data, and nothing here handles credentials: a
 * provider integration's capability is shown read-only (the tracking-mode
 * column), never configured.
 */
class SeoCitationCatalogController extends AdminBaseController
{
    public function __construct(private readonly SeoCitationCatalogManager $catalog)
    {
    }

    public function directories(): View
    {
        return view('admin.citation-directories.index', [
            'directories' => SeoCitationDirectory::query()->whereNull('business_id')->orderBy('sort_order')->orderBy('id')->get(),
            'breadcrumbs' => $this->crumbs(),
        ]);
    }

    public function createDirectory(): View
    {
        return view('admin.citation-directories.form', $this->formData(null, [['name' => 'New directory']]));
    }

    public function storeDirectory(Request $request): RedirectResponse
    {
        return $this->run(fn () => $this->catalog->createDirectory((int) Auth::id(), $this->directoryInput($request)), route('admin.citation-directories.index'), 'Directory added.', $request);
    }

    public function editDirectory(string $uid): View
    {
        $directory = SeoCitationDirectory::query()->where('uid', $uid)->whereNull('business_id')->first();
        abort_if($directory === null, 404);

        return view('admin.citation-directories.form', $this->formData($directory, [['name' => $directory->name]]));
    }

    public function updateDirectory(Request $request, string $uid): RedirectResponse
    {
        return $this->run(fn () => $this->catalog->updateDirectory((int) Auth::id(), $uid, $this->directoryInput($request)), route('admin.citation-directories.index'), 'Directory saved.', $request);
    }

    public function setDirectoryActive(Request $request, string $uid): RedirectResponse
    {
        $active = $request->boolean('active');

        return $this->run(fn () => $this->catalog->setActive((int) Auth::id(), $uid, $active), route('admin.citation-directories.index'), $active ? 'Directory enabled.' : 'Directory disabled. Existing Business records stay readable.', $request);
    }

    public function niches(): View
    {
        $counts = SeoNicheCitationRecommendation::query()
            ->selectRaw('niche_key, count(*) as total')
            ->groupBy('niche_key')
            ->pluck('total', 'niche_key');

        return view('admin.citation-niches.index', [
            'industries' => BusinessIndustry::cases(),
            'counts' => $counts,
            'breadcrumbs' => $this->crumbs([['name' => 'Niche recommendations']]),
        ]);
    }

    public function showNiche(string $niche): View
    {
        $industry = BusinessIndustry::tryFrom($niche);
        abort_if($industry === null, 404);

        $recommendations = SeoNicheCitationRecommendation::query()
            ->where('niche_key', $industry->value)
            ->with('directory')
            ->orderBy('sort_order')
            ->get();

        return view('admin.citation-niches.show', [
            'industry' => $industry,
            'recommendations' => $recommendations,
            'available' => SeoCitationDirectory::query()
                ->whereNull('business_id')
                ->whereNotIn('id', $recommendations->pluck('seo_citation_directory_id'))
                ->orderBy('name')
                ->get(),
            'importances' => SeoDirectoryImportance::cases(),
            'breadcrumbs' => $this->crumbs([['name' => 'Niche recommendations', 'link' => route('admin.citation-niches.index')], ['name' => $industry->label()]]),
        ]);
    }

    public function recommend(Request $request, string $niche): RedirectResponse
    {
        $input = $request->validate([
            'directory' => ['required', 'string', 'max:64'],
            'importance' => ['nullable', 'string', 'max:16'],
            'guidance' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->run(
            fn () => $this->catalog->recommend((int) Auth::id(), $niche, $input['directory'], $input['importance'] ?? null, $input['guidance'] ?? null),
            route('admin.citation-niches.show', $niche),
            'Recommendation added.',
            $request,
        );
    }

    public function updateRecommendation(Request $request, string $niche, string $uid): RedirectResponse
    {
        $input = $request->validate([
            'importance' => ['nullable', 'string', 'max:16'],
            'guidance' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:60000'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);
        $input['is_enabled'] = $request->boolean('is_enabled');

        return $this->run(fn () => $this->catalog->updateRecommendation((int) Auth::id(), $uid, $input), route('admin.citation-niches.show', $niche), 'Recommendation saved.', $request);
    }

    public function removeRecommendation(Request $request, string $niche, string $uid): RedirectResponse
    {
        return $this->run(fn () => $this->catalog->removeRecommendation((int) Auth::id(), $uid), route('admin.citation-niches.show', $niche), 'Recommendation removed.', $request);
    }

    /**
     * @return array<string, mixed>
     */
    private function directoryInput(Request $request): array
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'website_url' => ['nullable', 'string', 'max:2048'],
            'claim_url' => ['nullable', 'string', 'max:2048'],
            'category' => ['required', 'string', 'max:32'],
            'icon' => ['nullable', 'string', 'max:40'],
            'importance' => ['required', 'string', 'max:16'],
            'tracking_mode' => ['required', 'string', 'max:24'],
            'setup_guidance' => ['nullable', 'string', 'max:500'],
            'country_scope' => ['nullable', 'string', 'max:2'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:60000'],
        ]);
        $input['is_platform_core'] = $request->boolean('is_platform_core');

        return $input;
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(?SeoCitationDirectory $directory, array $crumbs): array
    {
        return [
            'directory' => $directory,
            'categories' => SeoCitationCatalogManager::CATEGORIES,
            'importances' => SeoDirectoryImportance::cases(),
            'modes' => [SeoDirectoryTrackingMode::Assisted, SeoDirectoryTrackingMode::Manual],
            'breadcrumbs' => $this->crumbs([['name' => 'Citation directories', 'link' => route('admin.citation-directories.index')], $crumbs[0]]),
        ];
    }

    /**
     * @param  array<int, array<string, string>>  $extra
     * @return array<int, array<string, string>>
     */
    private function crumbs(array $extra = [['name' => 'Citation directories']]): array
    {
        return array_merge([['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')]], $extra);
    }

    private function run(callable $write, string $to, string $success, Request $request): RedirectResponse
    {
        try {
            $write();
        } catch (SeoCitationCatalogException $e) {
            return back()->withInput($request->except('_token'))->with('flash_error', $e->getMessage());
        }

        return redirect($to)->with('flash_success', $success);
    }
}
