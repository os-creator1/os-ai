<?php

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
| When marketing.enabled is false, or the request host is not in marketing.hosts,
| none of this matches.
*/

$register = function () {
    Route::get('product', [MarketingController::class, 'product'])->name('marketing.product');
    Route::get('solutions', [MarketingController::class, 'solutions'])->name('marketing.solutions');
    Route::get('how-it-works', [MarketingController::class, 'howItWorks'])->name('marketing.how-it-works');
    Route::get('customers', [MarketingController::class, 'customers'])->name('marketing.customers');
};

if (config('marketing.enabled', true)) {
    $hosts = (array) config('marketing.hosts', []);

    if ($hosts === []) {
        $register();
    } else {
        // Route names must be unique, so only the first host carries them; the rest share the actions.
        foreach ($hosts as $i => $host) {
            Route::domain($host)->group($i === 0 ? $register : function () {
                Route::get('product', [MarketingController::class, 'product']);
                Route::get('solutions', [MarketingController::class, 'solutions']);
                Route::get('how-it-works', [MarketingController::class, 'howItWorks']);
                Route::get('customers', [MarketingController::class, 'customers']);
            });
        }
    }
}
