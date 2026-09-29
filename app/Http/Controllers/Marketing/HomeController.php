<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Library\PlatformBilling\PlatformPlanPresenter;
use App\Repositories\Contracts\MarketingContentSettingsRepository;
use App\Repositories\Contracts\MarketingFaqRepository;
use App\Repositories\Contracts\MarketingTestimonialRepository;
use Illuminate\Contracts\View\View;

/**
 * Public Marketing Homepage contract. Composes the platform's own public
 * homepage entirely from existing, already-public read models —
 * PlatformPlanPresenter for plans, plus the small owner-editable Marketing
 * Content surface for copy/FAQ/testimonials. No pricing or business logic
 * lives here.
 *
 * Review correction: the singleton hero-copy settings row is now resolved
 * through MarketingContentSettingsRepository::current() — the same
 * race-safe path MarketingContentController uses — so the admin editor and
 * this public page can never resolve two different rows.
 *
 * Review correction round 2: FAQ and testimonial reads moved off the
 * MarketingFaq/MarketingTestimonial Eloquent scopes and behind
 * MarketingFaqRepository/MarketingTestimonialRepository — the same
 * repositories MarketingContentController already uses — so the public
 * read path follows the same controller -> repository -> library -> model
 * structure as the corrected admin path.
 */
class HomeController extends Controller
{
    public function __construct(
        private readonly PlatformPlanPresenter $plans,
        private readonly MarketingContentSettingsRepository $settings,
        private readonly MarketingFaqRepository $faqs,
        private readonly MarketingTestimonialRepository $testimonials,
    ) {
    }

    public function index(): View
    {
        return view('marketing.home', [
            'plans' => $this->plans->sellablePlans(),
            'settings' => $this->settings->current(),
            'faqs' => $this->faqs->visibleOrdered(),
            // A visible testimonial only ever renders once it has something
            // to actually show: an uploaded poster, or a YouTube link (whose
            // own thumbnail stands in for a poster) — see
            // MarketingTestimonial::hasDisplayableMedia(). Filtering an
            // already-fetched Collection by a plain model predicate is not
            // an Eloquent query, so it stays here rather than moving into
            // the repository.
            'testimonials' => $this->testimonials->visibleOrdered()->filter->hasDisplayableMedia()->values(),
        ]);
    }
}
