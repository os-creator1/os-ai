<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Library\AgencySite\OfficialDomainPaths;
use App\Library\Marketing\CallOnlyMarketingSite;
use App\Library\Marketing\MarketingSite;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The public MotionGrove marketing website. Read-only pages: no writes, no
 * authentication, no billing. Content comes from MarketingSite (config/marketing.php).
 */
class MarketingController extends Controller
{
    public function __construct(private readonly MarketingSite $site) {}

    public function home(Request $request): View|RedirectResponse
    {
        if (config('app.stage') == 'new') {
            // Same as the application's root before this site existed: a fresh instance is
            // provisioned from the command line.
            abort(503, 'This instance is not installed yet. Run `php artisan platform:install` on the server.');
        }

        // A signed-in customer opening the root still lands in the application, exactly as before.
        if (Auth::check()) {
            return redirect('login');
        }

        return $this->page('home');
    }

    public function product(): View
    {
        return $this->page('product');
    }

    public function solutions(): View
    {
        return $this->page('solutions');
    }

    public function howItWorks(): View
    {
        return $this->page('how-it-works');
    }

    public function customers(): View
    {
        return $this->page('customers');
    }

    public function pricing(): View
    {
        return $this->page('pricing');
    }

    /** Public Zoom Marketplace support page. */
    public function support(): View
    {
        return $this->page('support');
    }

    /** Public guide to connecting, using and removing the native Zoom calendar integration. */
    public function zoomDocs(): View
    {
        return $this->page('zoom-docs');
    }

    /**
     * /photobooth — the official homepage as a "Book a call" landing page: same views, CSS, JS and content, minus pricing
     * and self-service signup, with every conversion button going to the existing demo-booking flow. Request-scoped;
     * the normal homepage, /pricing and signup are untouched.
     */
    public function photobooth(?string $page = null): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($this->site->servesRequest(request()), 404);

        // The Agency account that owns /photobooth (resolved from its immutable workspace uid, never from the request).
        // Once it has a published Landing Page site, THAT site is authoritative; until then the static adaptation below
        // keeps serving.
        $site = OfficialDomainPaths::siteFor('photobooth');
        if ($site !== null && $site->isLive()) {
            return app(AgencySiteController::class)->landing($site->public_id, $page ?? 'home', 'photobooth');
        }

        abort_unless($page === null, 404);

        $landing = new CallOnlyMarketingSite('photobooth');
        abort_unless($landing->canBook(), 404);

        // An outreach landing page that near-duplicates the homepage: kept out of search results, while the normal
        // marketing pages keep their own canonicals and indexing.
        return response()->view('marketing.pages.home', [
            'mg' => $landing,
            'page' => 'home',
            'meta' => $landing->meta('home'),
        ])->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * /dogs and /dogs/{page} — the existing published White Label dog-business site, served as a call-only landing page.
     * Which Agency site is decided by the immutable workspace uid in marketing.landing.official_paths, never by the request.
     */
    public function dogs(?string $page = null): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($this->site->servesRequest(request()), 404);

        $site = OfficialDomainPaths::siteFor('dogs');
        abort_if($site === null, 404);

        return app(AgencySiteController::class)->landing($site->public_id, $page ?? 'home', 'dogs');
    }

    private function page(string $page): View
    {
        abort_unless($this->site->servesRequest(request()), 404);

        return view('marketing.pages.'.$page, [
            'mg' => $this->site,
            'page' => $page,
            'meta' => $this->site->meta($page),
        ]);
    }
}
