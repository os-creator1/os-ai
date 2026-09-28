<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\SaveMarketingFaqRequest;
use App\Http\Requests\Admin\SaveMarketingTestimonialRequest;
use App\Http\Requests\Admin\UpdateMarketingHeroCopyRequest;
use App\Library\Marketing\MarketingTestimonialAssetService;
use App\Models\MarketingContentSettings;
use App\Models\MarketingFaq;
use App\Models\MarketingTestimonial;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Public Marketing Homepage contract — the one small, safe admin surface
 * for owner-editable marketing copy: hero headline/subheadline, FAQ
 * entries, and video testimonial slots. Deliberately not a page builder:
 * every field here is a plain typed column, never arbitrary HTML.
 *
 * Gated by the existing 'general settings' ability rather than a new
 * permission string, matching the Platform Branding contract's own
 * precedent of reusing that ability for owner-identity content.
 */
class MarketingContentController extends AdminBaseController
{
    public function __construct(
        private readonly MarketingTestimonialAssetService $testimonialAssets,
    ) {
    }

    public function index(): View
    {
        $this->authorize('general settings');

        return view('admin.marketing-content.index', [
            'settings' => MarketingContentSettings::current(),
            'faqs' => MarketingFaq::query()->orderBy('position')->orderBy('id')->get(),
            'testimonials' => MarketingTestimonial::query()->orderBy('position')->orderBy('id')->get(),
            'breadcrumbs' => $this->breadcrumbs(),
        ]);
    }

    public function updateHero(UpdateMarketingHeroCopyRequest $request): RedirectResponse
    {
        $this->authorize('general settings');

        $settings = MarketingContentSettings::current();
        $settings->fill($request->validated());
        $settings->updated_by_user_id = Auth::id();
        $settings->save();

        return redirect()
            ->route('admin.marketing-content.index')
            ->with('success', 'Homepage headline saved.');
    }

    public function storeFaq(SaveMarketingFaqRequest $request): RedirectResponse
    {
        $this->authorize('general settings');

        MarketingFaq::query()->create([
            ...$request->validated(),
            'is_visible' => $request->boolean('is_visible'),
            'position' => $request->input('position', MarketingFaq::query()->max('position') + 1),
        ]);

        return redirect()
            ->route('admin.marketing-content.index')
            ->with('success', 'FAQ added.');
    }

    public function updateFaq(SaveMarketingFaqRequest $request, MarketingFaq $faq): RedirectResponse
    {
        $this->authorize('general settings');

        $faq->fill($request->validated());
        $faq->is_visible = $request->boolean('is_visible');
        $faq->save();

        return redirect()
            ->route('admin.marketing-content.index')
            ->with('success', 'FAQ saved.');
    }

    public function destroyFaq(MarketingFaq $faq): RedirectResponse
    {
        $this->authorize('general settings');

        $faq->delete();

        return redirect()
            ->route('admin.marketing-content.index')
            ->with('success', 'FAQ removed.');
    }

    public function storeTestimonial(SaveMarketingTestimonialRequest $request): RedirectResponse
    {
        $this->authorize('general settings');

        if (! $request->hasFile('poster_image')) {
            return back()
                ->withErrors(['poster_image' => 'A poster image is required for a new testimonial.'])
                ->withInput();
        }

        $posterPath = $this->testimonialAssets->store($request->file('poster_image'));

        MarketingTestimonial::query()->create([
            ...$request->safe()->except('poster_image'),
            'poster_image_path' => $posterPath,
            'is_visible' => $request->boolean('is_visible'),
            'position' => $request->input('position', MarketingTestimonial::query()->max('position') + 1),
        ]);

        return redirect()
            ->route('admin.marketing-content.index')
            ->with('success', 'Testimonial added.');
    }

    public function updateTestimonial(SaveMarketingTestimonialRequest $request, MarketingTestimonial $testimonial): RedirectResponse
    {
        $this->authorize('general settings');

        $testimonial->fill($request->safe()->except('poster_image'));
        $testimonial->is_visible = $request->boolean('is_visible');

        if ($request->hasFile('poster_image')) {
            $previousPath = $testimonial->poster_image_path;
            $testimonial->poster_image_path = $this->testimonialAssets->store($request->file('poster_image'));
            $testimonial->save();
            $this->testimonialAssets->deleteIfOwned($previousPath);
        } else {
            $testimonial->save();
        }

        return redirect()
            ->route('admin.marketing-content.index')
            ->with('success', 'Testimonial saved.');
    }

    public function destroyTestimonial(MarketingTestimonial $testimonial): RedirectResponse
    {
        $this->authorize('general settings');

        $this->testimonialAssets->deleteIfOwned($testimonial->poster_image_path);
        $testimonial->delete();

        return redirect()
            ->route('admin.marketing-content.index')
            ->with('success', 'Testimonial removed.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function breadcrumbs(): array
    {
        return [
            ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
            ['name' => 'Marketing Content'],
        ];
    }
}
