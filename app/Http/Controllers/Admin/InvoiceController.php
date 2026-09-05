<?php

    namespace App\Http\Controllers\Admin;

    use App\Helpers\Helper;
    use App\Http\Controllers\Controller;
    use App\Http\Controllers\Customer\PaymentController;
    use App\Library\Tool;
    use App\Models\Invoices;
    use App\Models\Keywords;
    use App\Models\PhoneNumbers;
    use App\Models\Senderid;
    use App\Models\Subscription;
    use App\Models\SubscriptionLog;
    use App\Models\SubscriptionTransaction;
    use App\Models\User;
    use App\Notifications\SubscriptionPurchase;
    use Carbon\Carbon;
    use Exception;
    use Illuminate\Auth\Access\AuthorizationException;
    use Illuminate\Contracts\Foundation\Application;
    use Illuminate\Contracts\View\Factory;
    use Illuminate\Contracts\View\View;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\Request;
    use JetBrains\PhpStorm\NoReturn;

    class InvoiceController extends Controller
    {

        /**
         * view invoices
         *
         * @return Application|Factory|View
         * @throws AuthorizationException
         */
        public function index(): Factory|View|Application
        {
            $this->authorize('view invoices');

            $breadcrumbs = [
                ['link' => url(config('app.admin_path') . "/dashboard"), 'name' => __('locale.menu.Dashboard')],
                ['link' => url(config('app.admin_path') . "/dashboard"), 'name' => __('locale.menu.Reports')],
                ['name' => __('locale.menu.Invoices')],
            ];

            return view('admin.Invoices.index', compact('breadcrumbs'));
        }

        /**
         * @param Request $request
         *
         * @return void
         */
        #[NoReturn] public function search(Request $request): void
        {

            $columns = [
                0  => 'responsive_id',
                1  => 'uid',
                2  => 'uid',
                3  => 'created_at',
                4  => 'id',
                5  => 'type',
                6  => 'description',
                7  => 'amount',
                8  => 'status',
                9  => 'user_id',
                10 => 'actions',
            ];

            $totalData = Invoices::count();

            $totalFiltered = $totalData;

            $limit = $request->input('length');
            $start = $request->input('start');
            $order = $columns[$request->input('order.0.column')];
            $dir   = $request->input('order.0.dir');

            if (empty($request->input('search.value'))) {
                $invoices = Invoices::offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();
            } else {
                $search = $request->input('search.value');

                $invoices = Invoices::whereLike(['uid', 'type', 'created_at', 'description', 'amount', 'status', 'user.first_name', 'user.last_name'], $search)
                    ->offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();

                $totalFiltered = Invoices::whereLike(['uid', 'type', 'created_at', 'description', 'amount', 'status', 'user.first_name', 'user.last_name'], $search)->count();

            }

            $data = [];
            if ( ! empty($invoices)) {
                foreach ($invoices as $invoice) {

                    $show = route('admin.invoices.view', $invoice->uid);

                    $customer_profile = route('admin.customers.show', $invoice->user->uid);
                    $customer_name    = $invoice->user->displayName();
                    $user_id          = "<a href='$customer_profile' class='text-primary mr-1'>$customer_name</a>";
                    $invoice_number   = "<a href='$show' class='text-primary fw-bold'>#$invoice->id</a>";


                    $nestedData['responsive_id'] = '';
                    $nestedData['uid']           = $invoice->uid;
                    $nestedData['id']            = $invoice_number;
                    $nestedData['user_id']       = $user_id;
                    $nestedData['avatar']        = route('admin.customers.avatar', $invoice->user->uid);
                    $nestedData['email']         = $invoice->user->email;
                    $nestedData['created_at']    = Tool::customerDateTime($invoice->created_at);
                    $nestedData['type']          = strtoupper($invoice->type);
                    $nestedData['description']   = str_limit($invoice->description, 35);
                    $nestedData['amount']        = Tool::format_price($invoice->total_amount, $invoice->currency->format);
                    $nestedData['status']        = $invoice->getStatus();
                    $nestedData['get_status']    = $invoice->status;
                    $nestedData['status_locale'] = __('locale.labels.approve');
                    $nestedData['edit']          = $show;
                    $nestedData['delete']        = $invoice->uid;

                    $data[] = $nestedData;

                }
            }

            $json_data = [
                "draw"            => intval($request->input('draw')),
                "recordsTotal"    => $totalData,
                "recordsFiltered" => $totalFiltered,
                "data"            => $data,
            ];

            echo json_encode($json_data);
            exit();

        }


        /**
         * view invoice
         *
         * @param Invoices $invoice
         *
         * @return Factory|View|Application
         */

        public function view(Invoices $invoice): Factory|View|Application
        {

            $breadcrumbs = [
                ['link' => url(config('app.admin_path') . "/dashboard"), 'name' => __('locale.menu.Dashboard')],
                ['link' => url(config('app.admin_path') . "/invoices"), 'name' => __('locale.menu.All Invoices')],
                ['name' => __('locale.labels.invoice')],
            ];

            return view('admin.Invoices.view', compact('breadcrumbs', 'invoice'));
        }

        /**
         * print invoice
         *
         * @param Invoices $invoice
         *
         * @return Application|Factory|View
         */
        public function print(Invoices $invoice): View|Factory|Application
        {

            $pageConfigs = ['pageHeader' => false];

            return view('admin.Invoices.print', compact('invoice', 'pageConfigs'));
        }

        /**
         * @param Invoices $invoice
         *
         * @return JsonResponse
         * @throws Exception
         */
        public function destroy(Invoices $invoice): JsonResponse
        {
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            if ( ! $invoice->delete()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.exceptions.something_went_wrong'),
                ]);
            }

            return response()->json([
                'status'  => 'success',
                'message' => 'Invoice was deleted successfully.',
            ]);

        }

        /**
         * batch actions
         *
         * @param Request $request
         *
         * @return JsonResponse
         */
        public function batchAction(Request $request): JsonResponse
        {
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }
            $ids = $request->get('ids');

            if (Invoices::whereIn('uid', $ids)->delete()) {
                return response()->json([
                    'status'  => 'success',
                    'message' => __('locale.subscription.invoices_deleted'),
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);
        }


        /**
         * Approve Pending Invoice
         *
         * @param Invoices $invoice
         *
         * @return JsonResponse
         */
        public function approve(Invoices $invoice): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $data              = explode("|", $invoice->transaction_id);
            $paymentController = new PaymentController();

            if (is_array($data) && count($data) == 2) {
                switch ($data[0]) {
                    case 'top_up':

                        $user = User::find($invoice->user_id);

                        if ($user->sms_unit != '-1') {
                            $user->increment('sms_unit', $data['1']);
                        }


                        $subscription = $user->customer->activeSubscription();

                        $transaction = $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                            'status' => SubscriptionTransaction::STATUS_SUCCESS,
                            'title'  => 'Add ' . $data['1'] . ' sms units',
                            'amount' => $data['1'] . ' sms units',
                        ]);


                        $invoice->update([
                            'status'         => 'paid',
                            'transaction_id' => $transaction->uid,
                        ]);

                        return response()->json([
                            'status'  => 'success',
                            'message' => 'Invoice marked as paid',
                        ]);

                    case 'sender_id':

                        $senderid = Senderid::findByUid($data[1]);


                        $current                 = Carbon::now();
                        $senderid->validity_date = $current->add($senderid->frequency_unit, (int) $senderid->frequency_amount);
                        $senderid->status        = 'active';
                        $senderid->save();

                        $paymentController->createNotification('senderid', $senderid->sender_id, User::find($senderid->user_id)->displayName());


                        $invoice->update([
                            'status'         => 'paid',
                            'transaction_id' => $senderid->uid,
                        ]);

                        return response()->json([
                            'status'  => 'success',
                            'message' => 'Invoice marked as paid',
                        ]);

                    case 'number':
                        $number = PhoneNumbers::findByUid($data[1]);

                        $current               = Carbon::now();
                        $number->validity_date = $current->add($number->frequency_unit, (int) $number->frequency_amount);
                        $number->status        = 'assigned';
                        $number->save();

                        $paymentController->createNotification('number', $number->number, User::find($number->user_id)->displayName());

                        $invoice->update([
                            'status'         => 'paid',
                            'transaction_id' => $number->uid,
                        ]);

                        return response()->json([
                            'status'  => 'success',
                            'message' => 'Invoice marked as paid',
                        ]);

                    case 'keyword':
                        $keyword                = Keywords::findByUid($data[1]);
                        $current                = Carbon::now();
                        $keyword->validity_date = $current->add($keyword->frequency_unit, (int) $keyword->frequency_amount);
                        $keyword->status        = 'assigned';
                        $keyword->save();

                        $paymentController->createNotification('keyword', $keyword->keyword_name, User::find($keyword->user_id)->displayName());


                        $invoice->update([
                            'status'         => 'paid',
                            'transaction_id' => $keyword->uid,
                        ]);

                        return response()->json([
                            'status'  => 'success',
                            'message' => 'Invoice marked as paid',
                        ]);

                    case 'subscription':

                        $subscription = Subscription::findByUid($data[1]);
                        $plan         = $subscription->plan;


                        $user = User::find($invoice->user_id);


                        if ($user->customer->activeSubscription()) {
                            $user->customer->activeSubscription()->cancelNow();
                        }

                        if ($user->customer->subscription) {
                            $subscription          = $user->customer->subscription;
                            $get_options           = json_decode($subscription->options, true);
                            $output                = array_replace($get_options, [
                                'send_warning' => false,
                            ]);
                            $subscription->options = json_encode($output);
                            $subscription->plan_id = $plan->getBillableId();
                            $currentPeriodEndsAt   = $subscription->getPeriodEndsAt($subscription->current_period_ends_at);

                        } else {
                            $subscription           = new Subscription();
                            $subscription->user_id  = $user->id;
                            $subscription->start_at = Carbon::now();
                            $subscription->plan_id  = $plan->getBillableId();
                            $currentPeriodEndsAt    = $subscription->getPeriodEndsAt(Carbon::now());
                        }

                        $subscription->status                 = Subscription::STATUS_ACTIVE;
                        $subscription->end_period_last_days   = '10';
                        $subscription->current_period_ends_at = $currentPeriodEndsAt;
                        $subscription->end_at                 = null;
                        $subscription->end_by                 = null;
                        $subscription->payment_method_id      = $invoice->payment_method;

                        $subscription->save();

                        // add transaction
                        $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                            'end_at'                 => $subscription->end_at,
                            'current_period_ends_at' => $currentPeriodEndsAt,
                            'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                            'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                            'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                        ]);

                        // add log
                        $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                            'plan'  => $subscription->plan->getBillableName(),
                            'price' => $subscription->plan->getBillableFormattedPrice(),
                        ]);


                        if ($user->sms_unit == null || $user->sms_unit == '-1' || $plan->getOption('sms_max') == '-1') {
                            $user->sms_unit = $plan->getOption('sms_max');
                        } else {
                            $user->sms_unit += ($plan->getOption('add_previous_balance') == 'yes') ? $plan->getOption('sms_max') : 0;
                        }

                        $user->save();

                        if (Helper::app_config('subscription_notification_email')) {
                            $admin = User::find(1);
                            $admin->notify(new SubscriptionPurchase(route('admin.invoices.view', $invoice->uid)));
                        }

                        if ($user->customer->getNotifications()['subscription'] == 'yes') {
                            $user->notify(new SubscriptionPurchase(route('customer.invoices.view', $invoice->uid)));
                        }

                        $invoice->update([
                            'status'         => 'paid',
                            'transaction_id' => $subscription->uid,
                        ]);

                        if (Helper::app_config('subscription_notification_email')) {
                            $admin = User::find(1);
                            $admin->notify(new SubscriptionPurchase(route('admin.invoices.view', $invoice->uid)));
                        }

                        if ($user->customer->getNotifications()['subscription'] == 'yes') {
                            $user->notify(new SubscriptionPurchase(route('customer.invoices.view', $invoice->uid)));
                        }

                        if (isset($plan->getOptions()['sender_id']) && $plan->getOption('sender_id') !== null) {
                            $sender_id = Senderid::where('sender_id', $plan->getOption('sender_id'))->where('user_id', $user->id)->first();
                            if ( ! $sender_id) {
                                $current = Carbon::now();
                                Senderid::create([
                                    'sender_id'        => $plan->getOption('sender_id'),
                                    'status'           => 'active',
                                    'price'            => $plan->getOption('sender_id_price'),
                                    'billing_cycle'    => $plan->getOption('sender_id_billing_cycle'),
                                    'frequency_amount' => $plan->getOption('sender_id_frequency_amount'),
                                    'frequency_unit'   => $plan->getOption('sender_id_frequency_unit'),
                                    'currency_id'      => $plan->currency->id,
                                    'validity_date'    => $current->add($plan->getOption('sender_id_frequency_unit'), (int) $plan->getOption('sender_id_frequency_amount')),
                                    'payment_claimed'  => true,
                                    'user_id'          => $user->id,
                                    'business_id'      => app(\App\Library\Business\LegacyBusinessResolver::class)->resolveForCustomer((int) $user->id)?->id,
                                ]);
                            }
                        }

                        return response()->json([
                            'status'  => 'success',
                            'message' => 'Invoice marked as paid',
                        ]);

                    default:
                        return response()->json([
                            'status'  => 'error',
                            'message' => __('locale.exceptions.something_went_wrong'),
                        ]);
                }
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);
        }

    }
