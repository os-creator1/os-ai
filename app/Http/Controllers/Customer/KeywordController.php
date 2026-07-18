<?php

    namespace App\Http\Controllers\Customer;

    use App\Exceptions\GeneralException;
    use App\Http\Requests\Keywords\CustomerUpdate;
    use App\Http\Requests\Keywords\StoreKeywordsRequest;
    use App\Http\Requests\SenderID\PayPaymentRequest;
    use App\Library\Tool;
    use App\Models\AppConfig;
    use App\Models\Country;
    use App\Models\Invoices;
    use App\Models\Keywords;
    use App\Models\PaymentMethods;
    use App\Models\PhoneNumbers;
    use App\Models\Senderid;
    use App\Repositories\Contracts\KeywordRepository;
    use Carbon\Carbon;
    use Illuminate\Auth\Access\AuthorizationException;
    use Illuminate\Contracts\Foundation\Application;
    use Illuminate\Contracts\View\Factory;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\RedirectResponse;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Auth;
    use Illuminate\View\View;
    use JetBrains\PhpStorm\NoReturn;

    class KeywordController extends CustomerBaseController
    {

        protected $keywords;


        /**
         * KeywordController constructor.
         *
         * @param KeywordRepository $keywords
         */

        public function __construct(KeywordRepository $keywords)
        {
            $this->keywords = $keywords;
        }

        /**
         * @return Application|Factory|View
         * @throws AuthorizationException
         */

        public function index()
        {

            $this->authorize('view_keywords');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.Sending')],
                ['name' => __('locale.menu.Keywords')],
            ];


            return view('customer.keywords.index', compact('breadcrumbs'));
        }


        /**
         * @param Request $request
         *
         * @return void
         * @throws AuthorizationException
         */
        #[NoReturn]
        public function search(Request $request)
        {

            $this->authorize('view_keywords');

            $columns = [
                0 => 'responsive_id',
                1 => 'uid',
                2 => 'uid',
                3 => 'title',
                4 => 'keyword_name',
                5 => 'price',
                6 => 'status',
                7 => 'actions',
            ];

            $totalData = Keywords::where('user_id', Auth::user()->id)->count();

            $totalFiltered = $totalData;

            $limit = $request->input('length');
            $start = $request->input('start');
            $order = $columns[$request->input('order.0.column')];
            $dir   = $request->input('order.0.dir');

            if (empty($request->input('search.value'))) {
                $keywords = Keywords::where('user_id', Auth::user()->id)->offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();
            } else {
                $search = $request->input('search.value');

                $keywords = Keywords::where('user_id', Auth::user()->id)->whereLike(['uid', 'title', 'keyword_name', 'price'], $search)
                    ->offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();

                $totalFiltered = Keywords::where('user_id', Auth::user()->id)->whereLike(['uid', 'title', 'keyword_name', 'price'], $search)->count();
            }

            $data = [];
            if ( ! empty($keywords)) {
                foreach ($keywords as $keyword) {

                    $is_assigned = false;
                    if ($keyword->status == 'assigned') {
                        $is_assigned = true;
                        $status      = '<span class="badge bg-success text-uppercase">' . __('locale.labels.assigned') . '</span>';
                    } else if ($keyword->status == 'available') {
                        $is_assigned = true;
                        $status      = '<span class="badge bg-warning text-uppercase">' . __('locale.labels.pending') . '</span>';
                    } else {
                        $status = '<span class="badge bg-danger text-uppercase">' . __('locale.labels.expired') . '</span>';
                    }

                    $reply_mms = false;
                    if ($keyword->reply_mms) {
                        $reply_mms = true;
                    }

                    $nestedData['responsive_id'] = '';
                    $nestedData['uid']           = $keyword->uid;
                    $nestedData['title']         = $keyword->title;
                    $nestedData['keyword_name']  = $keyword->keyword_name;
                    $nestedData['created_at']    = __('locale.labels.created_at') . ': ' . Tool::formatDateTime($keyword->created_at);
                    $nestedData['price']         = "<div class='d-flex flex-column'>
                                                        <span class='emp_name text-truncate fw-bold'>" . Tool::format_price($keyword->price, $keyword->currency->format) . " </span>
                                                        <small class='emp_post text-truncate text-muted'>" . $keyword->displayFrequencyTime() . "</small>
                                                   </div>";
                    $nestedData['status']        = $status;
                    $nestedData['is_assigned']   = $is_assigned;

                    $nestedData['reply_mms']   = $reply_mms;
                    $nestedData['remove_mms']  = __('locale.buttons.remove_mms');
                    $nestedData['show_label']  = __('locale.buttons.edit');
                    $nestedData['show']        = route('customer.keywords.show', $keyword->uid);
                    $nestedData['renew_label'] = __('locale.labels.renew');
                    $nestedData['renew']       = route('customer.keywords.pay', $keyword->uid);
                    $nestedData['release']     = __('locale.labels.release');
                    $data[]                    = $nestedData;

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
         * @return Application|Factory|View
         * @throws AuthorizationException
         */

        public function create()
        {
            $this->authorize('create_keywords');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('keywords'), 'name' => __('locale.menu.Keywords')],
                ['name' => __('locale.keywords.create_new_keyword')],
            ];


            if (Auth::user()->customer->getOption('sender_id_verification') == 'yes') {
                $sender_ids    = Senderid::where('user_id', auth()->user()->id)->where('status', 'active')->cursor();
                $phone_numbers = PhoneNumbers::where('user_id', auth()->user()->id)->where('status', 'assigned')->cursor();
            } else {
                $sender_ids    = null;
                $phone_numbers = null;
            }

            return view('customer.keywords.create', compact('breadcrumbs', 'sender_ids', 'phone_numbers'));
        }


        /**
         * @param StoreKeywordsRequest $request
         *
         * @param Keywords             $keyword
         *
         * @return RedirectResponse
         * @throws AuthorizationException
         */

        public function store(StoreKeywordsRequest $request, Keywords $keyword): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.keywords.create')->withInput($request->except('_token'))->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->authorize('create_keywords');

            $this->keywords->store($request->except('_token'), $keyword::billingCycleValues());

            return redirect()->route('customer.keywords.index')->with([
                'status'  => 'success',
                'message' => __('locale.keywords.keyword_successfully_added'),
            ]);
        }


        /**
         * show available keywords
         *
         * @return Application|Factory|\Illuminate\Contracts\View\View
         * @throws AuthorizationException
         */
        public function buy()
        {
            $this->authorize('buy_keywords');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('keywords'), 'name' => __('locale.menu.Keywords')],
                ['name' => __('locale.keywords.buy_keyword')],
            ];

            return view('customer.keywords.buy', compact('breadcrumbs'));
        }


        /**
         * @param Request $request
         *
         * @return void
         * @throws AuthorizationException
         */
        #[NoReturn]
        public function available(Request $request)
        {

            $this->authorize('buy_keywords');

            $columns = [
                0 => 'responsive_id',
                1 => 'uid',
                2 => 'uid',
                3 => 'title',
                4 => 'keyword_name',
                5 => 'price',
                6 => 'actions',
            ];

            $totalData = Keywords::where('status', 'available')->count();

            $totalFiltered = $totalData;

            $limit = $request->input('length');
            $start = $request->input('start');
            $order = $columns[$request->input('order.0.column')];
            $dir   = $request->input('order.0.dir');

            if (empty($request->input('search.value'))) {
                $keywords = Keywords::where('status', 'available')->offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();
            } else {
                $search = $request->input('search.value');

                $keywords = Keywords::where('status', 'available')->whereLike(['uid', 'title', 'keyword_name', 'price'], $search)
                    ->offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();

                $totalFiltered = Keywords::where('status', 'available')->whereLike(['uid', 'title', 'keyword_name', 'price'], $search)->count();
            }

            $data = [];
            if ( ! empty($keywords)) {
                foreach ($keywords as $keyword) {

                    $nestedData['responsive_id'] = '';
                    $nestedData['uid']           = $keyword->uid;
                    $nestedData['title']         = $keyword->title;
                    $nestedData['buy']           = __('locale.labels.buy');
                    $nestedData['keyword_name']  = $keyword->keyword_name;
                    $nestedData['price']         = "<div>
                                                        <p class='text-bold-600'>" . Tool::format_price($keyword->price, $keyword->currency->format) . " </p>
                                                        <p class='text-muted'>" . $keyword->displayFrequencyTime() . "</p>
                                                   </div>";
                    $nestedData['checkout']      = route('customer.keywords.pay', $keyword->uid);
                    $data[]                      = $nestedData;

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
         * View currency for edit
         *
         * @param Keywords $keyword
         *
         * @return Application|Factory|View
         *
         * @throws AuthorizationException
         */

        public function show(Keywords $keyword)
        {
            $this->authorize('update_keywords');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('keywords'), 'name' => __('locale.menu.Keywords')],
                ['name' => __('locale.keywords.update_keyword')],
            ];

            if (Auth::user()->customer->getOption('sender_id_verification') == 'yes') {
                $sender_ids    = Senderid::where('user_id', auth()->user()->id)->where('status', 'active')->cursor();
                $phone_numbers = PhoneNumbers::where('user_id', auth()->user()->id)->where('status', 'assigned')->cursor();
            } else {
                $sender_ids    = null;
                $phone_numbers = null;
            }

            return view('customer.keywords.show', compact('breadcrumbs', 'keyword', 'sender_ids', 'phone_numbers'));
        }


        /**
         * @param Keywords       $keyword
         * @param CustomerUpdate $request
         *
         * @return RedirectResponse
         */

        public function update(Keywords $keyword, CustomerUpdate $request): RedirectResponse
        {

            if (config('app.stage') == 'demo') {
                return redirect()->route('customer.keywords.show', $keyword->uid)->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }


            try {

                if (Auth::user()->can('create_keywords')) {
                    $input = $request->except('_method', '_token');
                } else {
                    $input = $request->except('_method', '_token', 'title', 'keyword_name');
                }

                $this->keywords->updateByCustomer($keyword, $input);

                return redirect()->route('customer.keywords.show', $keyword->uid)->with([
                    'status'  => 'success',
                    'message' => __('locale.keywords.keyword_successfully_updated'),
                ]);
            } catch (GeneralException $exception) {

                return redirect()->route('customer.keywords.show', $keyword->uid)->with([
                    'status'  => 'error',
                    'message' => $exception->getMessage(),
                ]);
            }

        }

        /**
         * remove mms file
         *
         * @param Keywords $keyword
         *
         * @return JsonResponse
         */

        public function removeMMS(Keywords $keyword): JsonResponse
        {
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }


            if ( ! $keyword->where('user_id', Auth::user()->id)->update(['reply_mms' => null])) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.exceptions.something_went_wrong'),
                ]);
            }

            return response()->json([
                'status'  => 'success',
                'message' => __('locale.keywords.keyword_mms_file_removed'),
            ]);
        }


        /**
         * @param Keywords $keyword
         * @param          $id
         *
         * @return JsonResponse Controller|JsonResponse
         *
         * @throws AuthorizationException
         */
        public function release(Keywords $keyword, $id): JsonResponse
        {
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }


            $this->authorize('release_keywords');

            $this->keywords->release($keyword, $id);

            return response()->json([
                'status'  => 'success',
                'message' => __('locale.keywords.keyword_successfully_released'),
            ]);

        }

        /**
         * batch release
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


            $ids      = $request->get('ids');
            $keywords = Keywords::where('user_id', Auth::user()->id)->whereIn('uid', $ids)->cursor();

            foreach ($keywords as $keyword) {
                $keyword->user_id       = 1;
                $keyword->status        = 'available';
                $keyword->validity_date = null;

                $keyword->save();
            }

            return response()->json([
                'status'  => 'success',
                'message' => __('locale.keywords.keyword_successfully_released'),
            ]);

        }


        /**
         * checkout
         *
         * @param Keywords $keyword
         *
         * @return Application|Factory|\Illuminate\Contracts\View\View|RedirectResponse
         * @throws AuthorizationException
         */
        public function pay(Keywords $keyword)
        {

            $this->authorize('buy_keywords');

            $pageConfigs = [
                'bodyClass' => 'ecommerce-application',
            ];

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.Sending')],
                ['link' => url('keywords'), 'name' => __('locale.menu.Keywords')],
                ['name' => __('locale.labels.checkout')],
            ];

            if ($keyword->price == '0') {

                $paymentMethod = PaymentMethods::where('type', 'offline_payment')->first();

                $invoice = Invoices::create([
                    'user_id'        => auth()->user()->id,
                    'currency_id'    => $keyword->currency_id,
                    'payment_method' => $paymentMethod->id,
                    'amount'         => $keyword->price,
                    'tax'            => 0,
                    'total_amount'   => $keyword->price,
                    'type'           => Invoices::TYPE_KEYWORD,
                    'description'    => __('locale.keywords.payment_for_keyword') . ' ' . $keyword->keyword_name,
                    'transaction_id' => $keyword->uid,
                    'status'         => Invoices::STATUS_PAID,
                ]);

                if ($invoice) {
                    $current                = Carbon::now();
                    $keyword->user_id       = auth()->user()->id;
                    $keyword->validity_date = $current->add($keyword->frequency_unit, (int) $keyword->frequency_amount);
                    $keyword->status        = 'assigned';
                    $keyword->save();

                    return redirect()->route('customer.keywords.index')->with([
                        'status'  => 'success',
                        'message' => __('locale.payment_gateways.payment_successfully_made'),
                    ]);
                }

                return redirect()->route('customer.keywords.pay', $keyword->uid)->with([
                    'status'  => 'error',
                    'message' => __('locale.exceptions.something_went_wrong'),
                ]);
            }


            $payment_methods = PaymentMethods::where('status', true)->cursor();

            $country   = Country::where('name', Auth::user()->customer->country)->first();
            $price     = $keyword->price; // $price
            $taxAmount = 0;
            $taxRate   = 0;

            if ($country) {
                $taxRate = AppConfig::getTaxByCountry($country);
                if ($taxRate > 0) {
                    $taxAmount = ($price * $taxRate) / 100;
                    $price     = $price + $taxAmount;
                }
            }

            return view('customer.keywords.checkout', compact('breadcrumbs', 'pageConfigs', 'keyword', 'payment_methods', 'price', 'taxAmount', 'taxRate'));
        }


        /**
         * pay sender id payment
         *
         * @param Keywords          $keyword
         * @param PayPaymentRequest $request
         *
         * @return Application|Factory|\Illuminate\Contracts\View\View|RedirectResponse
         */
        public function payment(Keywords $keyword, PayPaymentRequest $request)
        {

            $data = $this->keywords->payPayment($keyword, $request->except('_token'));

            if ($data->getData()->status == 'success') {

                if ($request->input('payment_methods') == PaymentMethods::TYPE_BRAINTREE) {
                    return view('customer.Payments.braintree', [
                        'token'    => $data->getData()->token,
                        'keyword'  => $keyword,
                        'post_url' => route('customer.keywords.braintree', $keyword->uid),
                    ]);
                }

                if ($request->input('payment_methods') == PaymentMethods::TYPE_STRIPE) {
                    return view('customer.Payments.stripe', [
                        'session_id'      => $data->getData()->session_id,
                        'publishable_key' => $data->getData()->publishable_key,
                        'keyword'         => $keyword,
                    ]);
                }

                if ($request->input('payment_methods') == PaymentMethods::TYPE_AUTHORIZE_NET) {

                    $months = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec'];

                    return view('customer.Payments.authorize_net', [
                        'months'   => $months,
                        'keyword'  => $keyword,
                        'post_url' => route('customer.keywords.authorize_net', $keyword->uid),
                    ]);
                }

                if ($request->input('payment_methods') == PaymentMethods::TYPE_CASH) {
                    return view('customer.Payments.offline', [
                        'data'      => $data->getData()->data,
                        'type'      => 'keyword',
                        'post_data' => $keyword->uid,
                    ]);
                }


                if ($request->payment_methods == PaymentMethods::TYPE_NOWPAYMENTS) {
                    return view('customer.Payments.nowpayments', [
                        'pay_address' => $data->getData()->pay_address,
                        'valid_unit'  => $data->getData()->valid_unit,
                        'type'        => 'keyword',
                        'post_data'   => $keyword->uid,
                        'payment_id'  => $data->getData()->payment_id,
                        'data'        => $data->getData()->data,
                    ]);
                }

                if ($request->input('payment_methods') == PaymentMethods::TYPE_VODACOMMPESA) {

                    return view('customer.Payments.vodacom_mpesa', [
                        'keyword'  => $keyword,
                        'post_url' => route('customer.keywords.vodacommpesa', $keyword->uid),
                    ]);
                }

                if ($request->input('payment_methods') == PaymentMethods::TYPE_EASYPAY) {
                    return view('customer.Payments.easypay', [
                        'data'         => $data->getData()->data,
                        'request_type' => 'keyword',
                        'post_data'    => $keyword->uid,

                    ]);
                }


                if ($request->input('payment_methods') == PaymentMethods::TYPE_FEDAPAY) {
                    return view('customer.Payments.fedapay', [
                        'public_key' => $data->getData()->public_key,
                        'amount'     => $keyword->price,
                        'first_name' => $request->input('first_name'),
                        'last_name'  => $request->input('last_name'),
                        'email'      => $request->input('email'),
                        'item_name'  => __('locale.keywords.payment_for_keyword') . ' ' . $keyword->keyword_name,
                        'postData'   => [
                            'user_id'      => $keyword->user_id,
                            'request_type' => 'keyword',
                            'post_data'    => $keyword->uid,
                        ],
                    ]);
                }


                if (isset($data->getData()->redirect_url)) {
                    return redirect()->to($data->getData()->redirect_url);
                } else {
                    return redirect()->route('customer.keywords.pay', $keyword->uid)->with([
                        'status'  => 'error',
                        'message' => 'Redirect URL not found',
                    ]);
                }
            }

            return redirect()->route('customer.keywords.pay', $keyword->uid)->with([
                'status'  => 'error',
                'message' => $data->getData()->message,
            ]);

        }

    }
