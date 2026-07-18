<?php

    namespace App\Http\Controllers\Customer;

    use App\Http\Controllers\Controller;
    use App\Models\CustomerBasedSendingServer;
    use Illuminate\Auth\Access\AuthorizationException;
    use Illuminate\Contracts\Foundation\Application;
    use Illuminate\Contracts\View\Factory;
    use Illuminate\Contracts\View\View;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\RedirectResponse;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Auth;

    class DeveloperController extends Controller
    {
        /**
         * update developer settings
         *
         * @throws AuthorizationException
         */
        public function settings(): View|Factory|RedirectResponse|Application
        {
            $this->authorize('developers');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['name' => __('locale.menu.Developers')],
            ];

            if ( ! Auth::user()->customer->activeSubscription()) {
                return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }

            $sendingServers = CustomerBasedSendingServer::where('user_id', auth()->user()->id)->where('status', 1)->get();

            return view('customer.Developers.settings', compact('breadcrumbs', 'sendingServers'));
        }

        /**
         * generate new token
         */
        public function generate(): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $user = Auth::user();

            $user->tokens()->delete();

            $permissions = json_decode($user->customer->permissions, true);
            $token       = $user->createToken($user->email, $permissions)->plainTextToken;

            $user->update([
                'api_token' => $token,
            ]);

            return response()->json([
                'status'  => 'success',
                'token'   => $token,
                'message' => __('locale.customer.token_successfully_regenerate'),
            ]);
        }

        public function docs(): Factory|View|Application
        {
            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('developers'), 'name' => __('locale.menu.Developers')],
                ['name' => __('locale.developers.api_documents')],
            ];

            return view('customer.Developers.documentation', compact('breadcrumbs'));
        }

        public function sendingServer(Request $request): RedirectResponse
        {
            if (config('app.stage') === 'demo') {
                return redirect()->route('customer.developer.settings')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $user = Auth::user();

            // Map form field names to user model attributes
            $fields = [
                'sending_server'          => 'api_sending_server',
                'voice_sending_server'    => 'api_voice_sending_server',
                'mms_sending_server'      => 'api_mms_sending_server',
                'whatsapp_sending_server' => 'api_whatsapp_sending_server',
                'viber_sending_server'    => 'api_viber_sending_server',
                'otp_sending_server'      => 'api_otp_sending_server',
            ];

            // Build update payload only for submitted fields
            $updateData = [];
            foreach ($fields as $input => $column) {
                if ($request->filled($input)) {
                    $updateData[$column] = $request->input($input);
                }
            }

            if (empty($updateData)) {
                return redirect()->route('customer.developer.settings')->with([
                    'status'  => 'error',
                    'message' => __('locale.campaigns.sending_server_not_available'),
                ]);
            }

            $status = $user->update($updateData);

            return redirect()->route('customer.developer.settings')->with([
                'status'  => $status ? 'success' : 'error',
                'message' => $status
                    ? __('locale.settings.settings_successfully_updated')
                    : __('locale.exceptions.something_went_wrong'),
            ]);
        }


        /*Version 3.8*/

        public function httpDocs(): Factory|View|Application
        {
            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('developers'), 'name' => __('locale.menu.Developers')],
                ['name' => __('locale.developers.api_documents')],
            ];

            return view('customer.Developers.http-documentation', compact('breadcrumbs'));
        }


        public function webhookUrl(Request $request)
        {

            if (config('app.stage') == 'demo') {
                return redirect()->route('user.account')->withInput(['tab' => 'webhook'])->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);

            }

            if ( ! isset($request->webhook_url)) {
                return redirect()->route('user.account')->withInput(['tab' => 'webhook'])->with([
                    'status'  => 'error',
                    'message' => __('locale.developers.webhook_url_required'),
                ]);
            }


            $user = Auth::user();

            $user->update([
                'webhook_url' => $request->webhook_url,
            ]);

            return redirect()->route('user.account')->withInput(['tab' => 'webhook'])->with([
                'status'  => 'success',
                'message' => __('locale.settings.settings_successfully_updated'),
            ]);

        }

    }
