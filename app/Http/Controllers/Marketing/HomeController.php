<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Library\PlatformBilling\PlatformPlanPresenter;
use App\Models\MarketingFaq;
use App\Models\MarketingTestimonial;
use App\Repositories\Contracts\MarketingContentSettingsRepository;
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
 */
class HomeController extends Controller
{
    public function __construct(
        private readonly PlatformPlanPresenter $plans,
        private readonly MarketingContentSettingsRepository $settings,
    ) {
    }

    public function index(): View
    {
        return view('marketing.home', [
            'plans' => $this->plans->sellablePlans(),
            'settings' => $this->settings->current(),
            'faqs' => MarketingFaq::visibleOrdered()->get(),
            // A visible testimonial only ever renders once it has something
            // to actually show: an uploaded poster, or a YouTube link (whose
            // own thumbnail stands in for a poster) — see
            // MarketingTestimonial::hasDisplayableMedia().
            'testimonials' => MarketingTestimonial::visibleOrdered()->get()->filter->hasDisplayableMedia()->values(),
        ]);
    }
}
