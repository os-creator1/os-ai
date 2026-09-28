<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Library\PlatformBilling\PlatformPlanPresenter;
use App\Models\MarketingContentSettings;
use App\Models\MarketingFaq;
use App\Models\MarketingTestimonial;
use Illuminate\Contracts\View\View;

/**
 * Public Marketing Homepage contract. Composes the platform's own public
 * homepage entirely from existing, already-public read models —
 * PlatformPlanPresenter for plans, plus the small owner-editable Marketing
 * Content surface for copy/FAQ/testimonials. No pricing or business logic
 * lives here.
 */
class HomeController extends Controller
{
    public function __construct(
        private readonly PlatformPlanPresenter $plans,
    ) {
    }

    public function index(): View
    {
        return view('marketing.home', [
            'plans' => $this->plans->sellablePlans(),
            'settings' => MarketingContentSettings::current(),
            'faqs' => MarketingFaq::visibleOrdered()->get(),
            'testimonials' => MarketingTestimonial::visibleOrdered()->whereNotNull('poster_image_path')->get(),
        ]);
    }
}
