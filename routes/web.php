


<?php
    use App\Http\Controllers\Customer\DLRController;
    use Illuminate\Support\Facades\DB;
    use App\Http\Controllers\Customer\PusherController;
    use App\Http\Controllers\LanguageController;
    use App\Http\Controllers\MaintenanceNotifyController;

    // B5 Business Analytics §14 — the ghost "AI Analytics" / "Hot Leads"
    // surfaces (AiAnalyticsController, HotLeadController, their views,
    // request and routes) are removed: they ran on chat_boxes columns and
    // an ai_box_campaign_map table that no migration in this repository
    // creates, and one of their routes (POST /admin/ai-variants/update)
    // carried no middleware at all and pointed at a method that did not
    // exist. The live producers of that untracked schema in
    // EloquentCampaignRepository and DLRController are stop-listed for B5
    // and are deliberately untouched (a separate contract).


    /*
    |--------------------------------------------------------------------------
    | Web Routes
    |--------------------------------------------------------------------------
    |
    | Here is where you can register web routes for your application. These
    | routes are loaded by the RouteServiceProvider within a group which
    | contains the "web" middleware group. Now create something great!
    |
    */

    Route::get('/', function () {

        if (config('app.stage') == 'new') {
            return redirect('install');
        }

        return redirect('login');
    });

// locale Route
///new
Route::post('/inbound/telnyx', [DLRController::class, 'inboundTelnyx']);

    Route::get('lang/{locale}', [LanguageController::class, 'swap']);
    Route::any('languages', [LanguageController::class, 'languages'])->name('languages');
    Route::post('maintenance/notify', [MaintenanceNotifyController::class, 'store'])->name('maintenance.notify');

    Route::post('/pusher/auth', [PusherController::class, 'pusherAuth'])
        ->middleware('auth')->name('pusher.auth');

    // Security Remediation Slice 0 §16.A.1 (D-24) — the five unauthenticated
    // GET routes formerly registered here (add-gateways, remove-jobs,
    // remove-contacts, cache-clear, update-campaign-cache/{campaign}/{number})
    // and the locally guarded /debug route are removed, along with
    // app/Http/Controllers/Debug/DebugController.php in full. None had a
    // legitimate caller anywhere in this repository: queue maintenance is
    // `php artisan queue:flush`, cache eviction is `php artisan cache:clear`,
    // gateway seeding is database/seeders/PaymentMethodsSeeder.php, and the
    // contact-cleanup/campaign-cache-repair behaviours have no console
    // replacement because they were never a real operational need. See
    // docs/automation/AI-BUSINESS-OS-CUSTOMER-EXPERIENCE-AND-NAVIGATION-REDESIGN.md
    // §5.4/§16.A.1 for the full evidence and per-route disposition.

    // B3 Simplified Platform Settings §7 — the orphan "AI Brain" surface
    // (app/Http/Controllers/Admin/AiSettingsController.php, an ai_settings
    // table with no migration anywhere in this repository, and its
    // system_prompt field with zero production consumers) has been
    // removed. The one canonical platform AI provider configuration path
    // is admin/ai-settings (SettingsController::aiSettings/postAiSettings/
    // toggleAiSettings), which both the existing campaign AI feature and
    // Lane A's Agency Prospecting runtime already read via
    // config('services.openai.*').

Route::post('/telnyx/webhook', [DLRController::class, 'inboundTelnyx']);


 

