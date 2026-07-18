<?php

    namespace App\Http\Controllers\Customer;

    use App\Http\Requests\SenderID\PayPaymentRequest;
    use App\Library\Tool;
    use App\Models\AppConfig;
    use App\Models\Country;
    use App\Models\Invoices;
    use App\Models\PaymentMethods;
    use App\Models\PhoneNumbers;
    use App\Repositories\Contracts\PhoneNumberRepository;
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

    class NumberController extends CustomerBaseController
    {

        protected PhoneNumberRepository $numbers;


        /**
         * PhoneNumberController constructor.
         *
         * @param PhoneNumberRepository $numbers
         */

        public function __construct(PhoneNumberRepository $numbers)
        {
            $this->numbers = $numbers;
        }

        /**
         * @return Application|Factory|View
         * @throws AuthorizationException
         */
        public function index()
        {

            $this->authorize('view_numbers');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.Sending')],
                ['name' => __('locale.menu.Numbers')],
            ];

            return view('customer.Numbers.index', compact('breadcrumbs'));
        }


        /**
         * @param Request $request
         *
         * @return void
         * @throws AuthorizationException
         */
        #[NoReturn] public function search(Request $request)
        {

            $this->authorize('view_numbers');

            $columns = [
                0 => 'responsive_id',
                1 => 'uid',
                2 => 'uid',
                3 => 'number',
                4 => 'price',
                5 => 'status',
                6 => 'capabilities',
                7 => 'actions',
            ];

            $totalData = PhoneNumbers::where('user_id', Auth::user()->id)->count();

            $totalFiltered = $totalData;

            $limit = $request->input('length');
            $start = $request->input('start');
            $order = $columns[$request->input('order.0.column')];
            $dir   = $request->input('order.0.dir');

            if (empty($request->input('search.value'))) {
                $numbers = PhoneNumbers::where('user_id', Auth::user()->id)->offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();
            } else {
                $search = $request->input('search.value');

                $numbers = PhoneNumbers::where('user_id', Auth::user()->id)->whereLike(['uid', 'number', 'price', 'status'], $search)
                    ->offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();

                $totalFiltered = PhoneNumbers::where('user_id', Auth::user()->id)->whereLike(['uid', 'number', 'price', 'status'], $search)->count();

            }

            $data = [];
            if ( ! empty($numbers)) {
                foreach ($numbers as $number) {

                    $is_assigned = false;
                    if ($number->status == 'assigned') {
                        $is_assigned = true;
                        $status      = '<span class="badge bg-success text-uppercase">' . __('locale.labels.assigned') . '</span>';
                    } else if ($number->status == 'available') {
                        $is_assigned = true;
                        $status      = '<span class="badge bg-warning text-uppercase">' . __('locale.labels.pending') . '</span>';
                    } else {
                        $status = '<span class="badge bg-danger text-uppercase">' . __('locale.labels.expired') . '</span>';
                    }


                    $nestedData['responsive_id'] = '';
                    $nestedData['uid']           = $number->uid;
                    $nestedData['number']        = $number->number;
                    $nestedData['created_at']    = __('locale.labels.created_at') . ': ' . Tool::formatDateTime($number->created_at);
                    $nestedData['price']         = "<div class='d-flex flex-column'>
                                                        <span class='emp_name text-truncate fw-bold'>" . Tool::format_price($number->price, $number->currency->format) . " </span>
                                                        <small class='emp_post text-truncate text-muted'>" . $number->displayFrequencyTime() . "</small>
                                                   </div>";
                    $nestedData['status']        = $status;
                    $nestedData['is_assigned']   = $is_assigned;

                    $nestedData['capabilities'] = $number->getCapabilities();

                    $nestedData['renew_label'] = __('locale.labels.renew');
                    $nestedData['renew']       = route('customer.numbers.pay', $number->uid);
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
         * show available numbers
         *
         * @return Application|Factory|\Illuminate\Contracts\View\View
         * @throws AuthorizationException
         */
        public function buy()
        {
            $this->authorize('buy_numbers');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('numbers'), 'name' => __('locale.menu.Numbers')],
                ['name' => __('locale.phone_numbers.buy_number')],
            ];

            return view('customer.Numbers.buy', compact('breadcrumbs'));
        }

        /**
         * @param Request $request
         *
         * @return void
         * @throws AuthorizationException
         */
        #[NoReturn] public function availableNumbers(Request $request)
        {

            $this->authorize('buy_numbers');

            $columns = [
                0 => 'responsive_id',
                1 => 'uid',
                2 => 'uid',
                3 => 'number',
                4 => 'price',
                5 => 'capabilities',
                6 => 'actions',
            ];

            $totalData = PhoneNumbers::where('status', 'available')->count();

            $totalFiltered = $totalData;

            $limit = $request->input('length');
            $start = $request->input('start');
            $order = $columns[$request->input('order.0.column')];
            $dir   = $request->input('order.0.dir');

            if (empty($request->input('search.value'))) {
                $numbers = PhoneNumbers::where('status', 'available')->offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();
            } else {
                $search = $request->input('search.value');

                $numbers = PhoneNumbers::where('status', 'available')->whereLike(['uid', 'number', 'price'], $search)
                    ->offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();

                $totalFiltered = PhoneNumbers::where('status', 'available')->whereLike(['uid', 'number', 'price'], $search)->count();

            }

            $data = [];
            if ( ! empty($numbers)) {
                foreach ($numbers as $number) {

                    $nestedData['responsive_id'] = '';
                    $nestedData['uid']           = $number->uid;
                    $nestedData['buy']           = __('locale.labels.buy');
                    $nestedData['number']        = $number->number;
                    $nestedData['price']         = "<div>
                                                        <p class='text-bold-600'>" . Tool::format_price($number->price, $number->currency->format) . " </p>
                                                        <p class='text-muted'>" . $number->displayFrequencyTime() . "</p>
                                                   </div>";
                    $nestedData['checkout']      = route('customer.numbers.pay', $number->uid);
                    $nestedData['capabilities']  = $number->getCapabilities();
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
         * @param PhoneNumbers $phone_number
         * @param              $id
         *
         * @return JsonResponse Controller|JsonResponse
         *
         * @throws AuthorizationException
         */
        public function release(PhoneNumbers $phone_number, $id): JsonResponse
        {
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->authorize('release_numbers');

            $this->numbers->release($phone_number, $id);

            return response()->json([
                'status'  => 'success',
                'message' => __('locale.phone_numbers.number_successfully_released'),
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

            $ids     = $request->get('ids');
            $numbers = PhoneNumbers::where('user_id', Auth::user()->id)->whereIn('uid', $ids)->cursor();

            foreach ($numbers as $number) {
                $number->user_id       = 1;
                $number->status        = 'available';
                $number->validity_date = null;

                $number->save();
            }

            return response()->json([
                'status'  => 'success',
                'message' => __('locale.phone_numbers.number_successfully_released'),
            ]);

        }


        /**
         * checkout
         *
         * @param PhoneNumbers $number
         *
         * @return Application|Factory|\Illuminate\Contracts\View\View|RedirectResponse
         * @throws AuthorizationException
         */
        public function pay(PhoneNumbers $number)
        {

            $this->authorize('buy_numbers');

            $pageConfigs = [
                'bodyClass' => 'ecommerce-application',
            ];

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.Sending')],
                ['link' => url('numbers'), 'name' => __('locale.menu.Numbers')],
                ['name' => __('locale.labels.checkout')],
            ];

            if ($number->price == '0') {

                $paymentMethod = PaymentMethods::where('type', PaymentMethods::TYPE_CASH)->first();

                $invoice = Invoices::create([
                    'user_id'        => auth()->user()->id,
                    'currency_id'    => $number->currency_id,
                    'payment_method' => $paymentMethod->id,
                    'amount'         => $number->price,
                    'total_amount'   => $number->price,
                    'tax'     => 0,
                    'type'           => Invoices::TYPE_NUMBERS,
                    'description'    => __('locale.phone_numbers.payment_for_number') . ' ' . $number->number,
                    'transaction_id' => $number->uid,
                    'status'         => Invoices::STATUS_PAID,
                ]);

                if ($invoice) {
                    $current               = Carbon::now();
                    $number->user_id       = auth()->user()->id;
                    $number->validity_date = $current->add($number->frequency_unit, (int) $number->frequency_amount);
                    $number->status        = 'assigned';
                    $number->save();


                    return redirect()->route('customer.numbers.index')->with([
                        'status'  => 'success',
                        'message' => __('locale.payment_gateways.payment_successfully_made'),
                    ]);
                }

                return redirect()->route('customer.numbers.pay', $number->uid)->with([
                    'status'  => 'error',
                    'message' => __('locale.exceptions.something_went_wrong'),
                ]);
            }

            $payment_methods = PaymentMethods::where('status', true)->cursor();


            $country   = Country::where('name', Auth::user()->customer->country)->first();
            $price     = $number->price; // $price
            $taxAmount = 0;
            $taxRate   = 0;

            if ($country) {
                $taxRate = AppConfig::getTaxByCountry($country);
                if ($taxRate > 0) {
                    $taxAmount = ($price * $taxRate) / 100;
                    $price     = $price + $taxAmount;
                }
            }


            return view('customer.Numbers.checkout', compact('breadcrumbs', 'pageConfigs', 'number', 'payment_methods', 'price', 'taxAmount', 'taxRate'));
        }


        /**
         * pay sender id payment
         *
         * @param PhoneNumbers      $number
         * @param PayPaymentRequest $request
         *
         * @return Application|Factory|\Illuminate\Contracts\View\View|RedirectResponse
         */
        public function payment(PhoneNumbers $number, PayPaymentRequest $request)
        {

            $data = $this->numbers->payPayment($number, $request->except('_token'));

            if (isset($data->getData()->status)) {

                if ($data->getData()->status == 'success') {

                    if ($request->input('payment_methods') == PaymentMethods::TYPE_BRAINTREE) {
                        return view('customer.Payments.braintree', [
                            'token'    => $data->getData()->token,
                            'number'   => $number,
                            'post_url' => route('customer.numbers.braintree', $number->uid),
                        ]);
                    }

                    if ($request->input('payment_methods') == PaymentMethods::TYPE_STRIPE) {
                        return view('customer.Payments.stripe', [
                            'session_id'      => $data->getData()->session_id,
                            'publishable_key' => $data->getData()->publishable_key,
                            'number'          => $number,
                        ]);
                    }

                    if ($request->input('payment_methods') == PaymentMethods::TYPE_AUTHORIZE_NET) {

                        $months = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec'];

                        return view('customer.Payments.authorize_net', [
                            'months'   => $months,
                            'number'   => $number,
                            'post_url' => route('customer.numbers.authorize_net', $number->uid),
                        ]);
                    }

                    if ($request->input('payment_methods') == PaymentMethods::TYPE_FEDAPAY) {
                        return view('customer.Payments.fedapay', [
                            'public_key' => $data->getData()->public_key,
                            'amount'     => $number->price,
                            'first_name' => $request->input('first_name'),
                            'last_name'  => $request->input('last_name'),
                            'email'      => $request->input('email'),
                            'item_name'  => __('locale.phone_numbers.payment_for_number') . ' ' . $number->number,
                            'postData'   => [
                                'user_id'      => $number->user_id,
                                'request_type' => 'number',
                                'post_data'    => $number->uid,
                            ],
                        ]);
                    }


                    if ($request->input('payment_methods') == PaymentMethods::TYPE_CASH) {
                        return view('customer.Payments.offline', [
                            'data'      => $data->getData()->data,
                            'type'      => 'number',
                            'post_data' => $number->uid,
                        ]);
                    }

                    if ($request->payment_methods == PaymentMethods::TYPE_NOWPAYMENTS) {
                        return view('customer.Payments.nowpayments', [
                            'pay_address' => $data->getData()->pay_address,
                            'valid_unit'  => $data->getData()->valid_unit,
                            'type'        => 'number',
                            'post_data'   => $number->uid,
                            'payment_id'  => $data->getData()->payment_id,
                            'data'        => $data->getData()->data,
                        ]);
                    }

                    if ($request->input('payment_methods') == PaymentMethods::TYPE_VODACOMMPESA) {

                        return view('customer.Payments.vodacom_mpesa', [
                            'number'   => $number,
                            'post_url' => route('customer.numbers.vodacommpesa', $number->uid),
                        ]);
                    }

                    if (isset($data->getData()->redirect_url)) {
                        return redirect()->to($data->getData()->redirect_url);
                    } else {
                        return redirect()->route('customer.numbers.pay', $number->uid)->with([
                            'status'  => 'error',
                            'message' => 'Redirect URL not found',
                        ]);
                    }

                }

                return redirect()->route('customer.numbers.pay', $number->uid)->with([
                    'status'  => 'error',
                    'message' => $data->getData()->message,
                ]);
            }

            return redirect()->route('customer.numbers.pay', $number->uid)->with([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);

        }

    }
