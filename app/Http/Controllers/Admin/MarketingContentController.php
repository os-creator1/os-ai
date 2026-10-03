<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\SaveMarketingFaqRequest;
use App\Http\Requests\Admin\SaveMarketingTestimonialRequest;
use App\Http\Requests\Admin\UpdateMarketingHeroCopyRequest;
use App\Library\Marketing\MarketingTestimonialAssetService;
use App\Library\Marketing\YoutubeUrlParser;
use App\Models\MarketingFaq;
use App\Models\MarketingTestimonial;
use App\Repositories\Contracts\MarketingContentSettingsRepository;
use App\Repositories\Contracts\MarketingFaqRepository;
use App\Repositories\Contracts\MarketingTestimonialRepository;
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
 *
 * Review correction: this controller used to call Eloquent directly
 * (MarketingFaq::query(), MarketingTestimonial::query(), etc.), bypassing
 * the controller -> repository -> library -> model structure AGENTS.md
 * requires. Every persistence/query operation now goes through the three
 * Marketing repositories injected below; this class only handles
 * authorization, request shaping, and orchestrating the repositories with
 * MarketingTestimonialAssetService (file storage, a library concern).
 */
class MarketingContentController extends AdminBaseController
{
    public function __construct(
        private readonly MarketingTestimonialAssetService $testimonialAssets,
        private readonly MarketingContentSettingsRepository $settings,
        private readonly MarketingFaqRepository $faqs,
        private readonly MarketingTestimonialRepository $testimonials,
    ) {
    }

    public function index(): View
    {
        $this->authorize('general settings');

        return view('admin.marketing-content.index', [
            'settings' => $this->settings->current(),
            'faqs' => $this->faqs->allOrdered(),
            'testimonials' => $this->testimonials->allOrdered(),
            'breadcrumbs' => $this->breadcrumbs(),
        ]);
    }

    public function updateHero(UpdateMarketingHeroCopyRequest $request): RedirectResponse
    {
        $this->authorize('general settings');

        $this->settings->update($this->settings->current(), $request->validated(), Auth::id());

        return redirect()
            ->route('admin.marketing-content.index')
            ->with('success', 'Homepage headline saved.');
    }

    public function storeFaq(SaveMarketingFaqRequest $request): RedirectResponse
    {
        $this->authorize('general settings');

        $this->faqs->create([
            ...$request->validated(),
            'is_visible' => $request->boolean('is_visible'),
            'position' => $request->input('position', $this->faqs->nextPosition()),
        ]);

        return redirect()
            ->route('admin.marketing-content.index')
            ->with('success', 'FAQ added.');
    }

    public function updateFaq(SaveMarketingFaqRequest $request, MarketingFaq $faq): RedirectResponse
    {
        $this->authorize('general settings');

        $this->faqs->update($faq, [
            ...$request->validated(),
            'is_visible' => $request->boolean('is_visible'),
            // Review correction: an operator clearing the "Order" field
            // submits an empty string, which ConvertEmptyStringsToNull
            // turns into null before the (nullable) validation rule ever
            // sees it — a null would otherwise reach this non-nullable
            // column and fail as a database error rather than a friendly
            // one. Keep the current position instead of persisting null.
            'position' => $request->validated('position') ?? $faq->position,
        ]);

        return redirect()
            ->route('admin.marketing-content.index')
            ->with('success', 'FAQ saved.');
    }

    public function destroyFaq(MarketingFaq $faq): RedirectResponse
    {
        $this->authorize('general settings');

        $this->faqs->delete($faq);

        return redirect()
            ->route('admin.marketing-content.index')
            ->with('success', 'FAQ removed.');
    }

    public function storeTestimonial(SaveMarketingTestimonialRequest $request): RedirectResponse
    {
        $this->authorize('general settings');

        $youtubeId = $request->filled('video_url') ? YoutubeUrlParser::extractVideoId($request->input('video_url')) : null;

        if (! $request->hasFile('poster_image') && $youtubeId === null) {
            return back()
                ->withErrors(['poster_image' => 'A poster image is required, unless you provide a YouTube video link.'])
                ->withInput();
        }

        $posterPath = $request->hasFile('poster_image')
            ? $this->testimonialAssets->store($request->file('poster_image'))
            : null;

        $this->testimonials->create([
            ...$request->safe()->except('poster_image'),
            'poster_image_path' => $posterPath,
            'is_visible' => $request->boolean('is_visible'),
            'position' => $request->input('position', $this->testimonials->nextPosition()),
        ]);

        return redirect()
            ->route('admin.marketing-content.index')
            ->with('success', 'Testimonial added.');
    }

    public function updateTestimonial(SaveMarketingTestimonialRequest $request, MarketingTestimonial $testimonial): RedirectResponse
    {
        $this->authorize('general settings');

        $previousPath = $testimonial->poster_image_path;

        $newPosterPath = $request->hasFile('poster_image')
            ? $this->testimonialAssets->store($request->file('poster_image'))
            : $previousPath;

        if (! MarketingTestimonial::wouldHaveDisplayableMedia($newPosterPath, $request->validated('video_url'))) {
            return back()
                ->withErrors(['poster_image' => 'A poster image is required, unless you provide a YouTube video link.'])
                ->withInput();
        }

        $this->testimonials->update($testimonial, [
            ...$request->safe()->except('poster_image'),
            'poster_image_path' => $newPosterPath,
            'is_visible' => $request->boolean('is_visible'),
            // Review correction: see the identical note in updateFaq() —
            // a cleared "Order" field must not persist null into this
            // non-nullable column.
            'position' => $request->validated('position') ?? $testimonial->position,
        ]);

        // Content-hashed filenames mean re-uploading the same photo, or two
        // testimonials sharing one photo, can leave `$previousPath` equal to
        // the file this (or another) row still needs. Only remove it once no
        // testimonial — including this one, post-save — references it.
        $this->deletePosterIfOrphaned($previousPath);

        return redirect()
            ->route('admin.marketing-content.index')
            ->with('success', 'Testimonial saved.');
    }

    public function destroyTestimonial(MarketingTestimonial $testimonial): RedirectResponse
    {
        $this->authorize('general settings');

        $posterPath = $testimonial->poster_image_path;
        $this->testimonials->delete($testimonial);
        $this->deletePosterIfOrphaned($posterPath);

        return redirect()
            ->route('admin.marketing-content.index')
            ->with('success', 'Testimonial removed.');
    }

    /**
     * Deletes a testimonial poster file only when no `marketing_testimonials`
     * row — checked against current database state, after the triggering
     * save/delete has already been committed — still points at it. Guards
     * against exactly two ways content-hashed filenames can be shared: an
     * unchanged re-upload (the "previous" path is still this row's current
     * path) and two different testimonials pointing at the same uploaded
     * photo (deleting or replacing one must not orphan the other's image).
     */
    private function deletePosterIfOrphaned(?string $path): void
    {
        if (blank($path)) {
            return;
        }

        if (! $this->testimonials->isPosterPathInUse($path)) {
            $this->testimonialAssets->deleteIfOwned($path);
        }
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
