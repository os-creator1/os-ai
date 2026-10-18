<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
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

    private function page(string $page): View
    {
        return view('marketing.pages.'.$page, [
            'mg' => $this->site,
            'page' => $page,
            'meta' => $this->site->meta($page),
        ]);
    }
}
