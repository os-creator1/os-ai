<?php

use App\Http\Controllers\Public\LegalController;
use App\Http\Controllers\Public\MarketingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| MotionGrove marketing website
|--------------------------------------------------------------------------
|
| The marketing site's public pages. The application's own routes (login,
| register, /book-demo, /sites, ...) are untouched. Two marketing URLs already
| belonged to the application, so they are not registered here (a second route
| on the same URI would silently replace the first):
|   `/`        routes/web.php: the marketing homepage for guests, otherwise the
|              unchanged redirect into the application.
|   `/pricing` routes/auth.php: the signed-in customer's own pricing page, with
|              ServeMarketingPricingToGuests answering guests with the public page.
| The Legal Center (`/legal` and the document slugs in config/legal.php) is registered here too, except
| `/privacy-policy`: that URI already belongs to routes/public.php (the legacy, admin-editable page), so
| PublicController::privacyPolicy() hands the marketing host to the Legal Center instead of a second route
| shadowing it. `/terms-of-use` likewise redirects to /terms-of-service on the marketing host.
| When marketing.enabled is false, or the request host is not in marketing.hosts,
| none of this matches.
*/

$legalSlugs = array_values(array_diff(array_keys((array) config('legal.documents')), ['privacy-policy']));

$register = function (bool $named = true) use ($legalSlugs) {
    $route = fn ($r, $name) => $named ? $r->name($name) : $r;

    $route(Route::get('product', [MarketingController::class, 'product']), 'marketing.product');
    $route(Route::get('solutions', [MarketingController::class, 'solutions']), 'marketing.solutions');
    $route(Route::get('how-it-works', [MarketingController::class, 'howItWorks']), 'marketing.how-it-works');
    $route(Route::get('customers', [MarketingController::class, 'customers']), 'marketing.customers');
    $route(Route::get('legal', [LegalController::class, 'index']), 'marketing.legal');
    foreach ($legalSlugs as $slug) {
        $route(Route::get($slug, [LegalController::class, 'show'])->defaults('slug', $slug), 'marketing.legal.'.$slug);
    }
};

if (config('marketing.enabled', true)) {
    $hosts = (array) config('marketing.hosts', []);

    if ($hosts === []) {
        $register();
    } else {
        // Route names must be unique, so only the first host carries them; the rest share the actions.
        foreach ($hosts as $i => $host) {
            Route::domain($host)->group(fn () => $register($i === 0));
        }
    }
}
