


<?php
    use App\Http\Controllers\Customer\DLRController;
    use Illuminate\Support\Facades\DB;
    use App\Http\Controllers\Customer\PusherController;
    use App\Http\Controllers\Debug\DebugController;
    use App\Http\Controllers\LanguageController;
    use App\Http\Controllers\MaintenanceNotifyController;
    use App\Http\Controllers\Admin\HotLeadController;
    use App\Http\Controllers\Admin\AiAnalyticsController;

Route::get('/admin/ai-analytics', [AiAnalyticsController::class, 'index'])
    ->middleware(['auth', 'can:access_backend', 'ValidProduct', 'twofactor']);


Route::get('/admin/hot-leads', [HotLeadController::class, 'index'])
    ->middleware(['auth', 'can:access_backend', 'ValidProduct', 'twofactor']);
Route::post('/admin/hot-leads/mark-called', [HotLeadController::class, 'markCalled'])
    ->middleware(['auth', 'can:access_backend', 'ValidProduct', 'twofactor']);




Route::post('/admin/ai-variants/update', [App\Http\Controllers\Admin\AiAnalyticsController::class, 'updateVariants'])
    ->name('admin.ai_variants.update');


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

    Route::get('add-gateways', [DebugController::class, 'addGateways'])->name('add.gateways');
    Route::get('remove-jobs', [DebugController::class, 'removeJobs'])->name('remove.jobs');
    Route::get('remove-contacts', [DebugController::class, 'removeContacts'])->name('remove.contacts');
    Route::get('cache-clear', [DebugController::class, 'cacheClear'])->name('cache.clear');
    Route::get('update-campaign-cache/{campaign}/{number}', [DebugController::class, 'updateCampaignCache'])->name('update.campaign.cache');
    
    
    Route::post('/admin/ai-analytics/book/{id}', [\App\Http\Controllers\Admin\AiAnalyticsController::class, 'markBooked'])
    ->name('admin.ai.booked')
    ->middleware(['auth', 'can:access_backend', 'ValidProduct', 'twofactor']);







    if (config('app.stage') == 'local') {
        Route::get('debug', [DebugController::class, 'index'])->name('debug');
    }
    //new
use App\Http\Controllers\Admin\AiSettingsController;

Route::get('/admin/ai-brain',[AiSettingsController::class,'index'])
    ->middleware(['auth', 'can:access backend', 'ValidProduct', 'twofactor']);
Route::post('/admin/ai-brain',[AiSettingsController::class,'save'])
    ->middleware(['auth', 'can:access backend', 'ValidProduct', 'twofactor']);

Route::post('/telnyx/webhook', [DLRController::class, 'inboundTelnyx']);


 

