@php use App\Library\Tool;use App\Models\Campaigns;use App\Models\Plan;use App\Models\Reports;use App\Models\User;use App\Models\Invoices;use App\Models\SendingServer; @endphp
@extends('layouts/contentLayoutMaster')

@section('title', __('locale.menu.Dashboard'))

{{--Vendor Css files--}}
@section('vendor-style')
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/charts/apexcharts.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/extensions/tether-theme-arrows.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/extensions/tether.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/extensions/shepherd.min.css')) }}">
@endsection


@section('page-style')
    <link rel="stylesheet" href="{{ asset(mix('css/base/plugins/charts/chart-apex.css')) }}">
@endsection


@section('content')

    <section>

        @if(!file_exists(storage_path('cronJobAvailable')))
            <div class="alert alert-warning" role="alert">
                <h4 class="alert-heading text-uppercase">Cron Job or Schedule task not available</h4>
                <div class="alert-body">
                    <p>Kindly ensure that the cron job or schedule task is enabled to facilitate the importing of
                        contacts, sending campaigns, and all other background-related tasks. Please navigate to <a
                                class="text-decoration-underline " href="{{ route('admin.settings.general') }}"><i>Settings
                                > All Settings > Cron jobs</i></a> for more details. </p>
                </div>
            </div>
        @endif


        @if( $serverCounts->active == 0 || $planCounts->active  == 0 || $customerCounts->active == 0 || $subscriptionCounts->active == 0)

            <div class="row match-height">

                <div class="col-12">
                    <div class="card card-user-timeline">
                        <div class="card-header">
                            <div class="d-flex align-items-center">
                                <i data-feather="list" class="user-timeline-title-icon"></i>
                                <h4 class="card-title text-uppercase text-success">{{ __('locale.labels.getting_started') }}</h4>
                            </div>
                        </div>

                        <div class="card-content">
                            <div class="card-body">
                                <ul class="timeline">

                                    <li class="timeline-item">
                                        <span class="timeline-point @if($serverCounts->active != 0)  timeline-point-success @else  timeline-point-danger @endif">
                                           @if($serverCounts->active != 0)
                                                <i data-feather="check-circle"></i>
                                            @else
                                                <i data-feather="x-circle"></i>
                                            @endif
                                        </span>
                                        <div class="timeline-event">
                                            <div class="d-flex justify-content-between flex-sm-row flex-column mb-sm-0 mb-1">
                                                <h6 class="text-uppercase @if($serverCounts->active != 0) text-decoration-line-through text-success @else text-danger @endif">
                                                    Add Sending Server or SMS Gateway</h6>
                                            </div>
                                            <p><a class="text-uppercase"
                                                  href="{{ route('admin.sending-servers.index') }}">Sending
                                                    servers</a> are needed in order to send out all the sms from the
                                                application. {{ config('app.name') }} comes with support more than 200+
                                                sending servers or gateways. You can use either of them, or Create your
                                                <a
                                                        href="{{ route('admin.sending-servers.create', ['type' => 'custom']) }}">own
                                                    one</a></p>
                                        </div>
                                    </li>

                                    <li class="timeline-item">
                                        <span class="timeline-point @if($planCounts->active != 0)  timeline-point-success @else  timeline-point-danger @endif">
                                           @if($planCounts->active != 0)
                                                <i data-feather="check-circle"></i>
                                            @else
                                                <i data-feather="x-circle"></i>
                                            @endif
                                        </span>
                                        <div class="timeline-event">
                                            <div class="d-flex justify-content-between flex-sm-row flex-column mb-sm-0 mb-1">
                                                <h6 class="text-uppercase @if($planCounts->active != 0) text-decoration-line-through text-success @else text-danger @endif">
                                                    Create Plan for your Customers</h6>
                                            </div>
                                            <p><a class="text-uppercase"
                                                  href="{{ route('admin.plans.index') }}">Plans</a> are essential for
                                                configuring SMS pricing, coverage, sending limits, plan features,
                                                etc. {{ config('app.name') }} allows you to create various types of
                                                price plans for your customers. Additionally, you can assign different
                                                types of sending servers or SMS gateways and allocate SMS units based on
                                                countries.</p>
                                        </div>
                                    </li>

                                    <li class="timeline-item">
                                        <span class="timeline-point @if($customerCounts->active != 0)  timeline-point-success @else  timeline-point-danger @endif">
                                           @if($customerCounts->active != 0)
                                                <i data-feather="check-circle"></i>
                                            @else
                                                <i data-feather="x-circle"></i>
                                            @endif
                                        </span>

                                        <div class="timeline-event">
                                            <div class="d-flex justify-content-between flex-sm-row flex-column mb-sm-0 mb-1">
                                                <h6 class="text-uppercase @if($customerCounts->active != 0) text-decoration-line-through text-success @else text-danger @endif">
                                                    Your Customer Account</h6>
                                            </div>
                                            <p>When you first installed the application, you were asked to create a
                                                customer account. In case you haven't done so, please go ahead and <a
                                                        href="{{ route('admin.customers.create') }}">create one.</a>
                                                When using {{ config('app.name') }}, the customers are the ones that
                                                manage the contact lists, campaigns, templates and so on. You can also
                                                assign different types of pricing, sending servers or SMS gateways,
                                                permissions, etc to your customers.</p>
                                        </div>
                                    </li>

                                    <li class="timeline-item">
                                        <span class="timeline-point @if($subscriptionCounts->active != 0)  timeline-point-success @else  timeline-point-danger @endif">
                                           @if($subscriptionCounts->active != 0)
                                                <i data-feather="check-circle"></i>
                                            @else
                                                <i data-feather="x-circle"></i>
                                            @endif
                                        </span>

                                        <div class="timeline-event">
                                            <div class="d-flex justify-content-between flex-sm-row flex-column mb-sm-0 mb-1">
                                                <h6 class="text-uppercase @if($subscriptionCounts->active != 0) text-decoration-line-through text-success @else text-danger @endif">
                                                    Assign Plan to your Customer
                                                </h6>
                                            </div>
                                            <p>After creating a <a class="text-uppercase"
                                                                   href="{{ route('admin.plans.index') }}">Plan</a> and <a
                                                        href="{{ route('admin.customers.create') }}">Customer</a>, now it's time to assign your plan to
                                                your customer. To assign a plan to your customer, go ahead and click on
                                                the <a href="{{ route('admin.subscriptions.create') }}"> Assign
                                                    Plan</a> button. </p>
                                        </div>
                                    </li>

                                    <li class="timeline-item">
                                        <span class="timeline-point timeline-point-info">
                                               <i data-feather="check-circle"></i>
                                        </span>

                                        <div class="timeline-event">
                                            <div class="d-flex justify-content-between flex-sm-row flex-column mb-sm-0 mb-1">
                                                <h6 class="text-uppercase text-info">
                                                    Assign Sender ID or Phone Numbers to your customer
                                                </h6>
                                            </div>
                                            <p>You have the option to assign sender IDs or phone numbers to your customers. This step is optional if your sending servers or SMS gateway do not support sender IDs or phone numbers as originators. </p>
                                        </div>
                                    </li>


                                    <li class="timeline-item">
                                        <span class="timeline-point timeline-point-info">
                                               <i data-feather="check-circle"></i>
                                        </span>

                                        <div class="timeline-event">
                                            <div class="d-flex justify-content-between flex-sm-row flex-column mb-sm-0 mb-1">
                                                <h6 class="text-uppercase text-info">
                                                    Application Branding Setup
                                                </h6>
                                            </div>
                                            <p>You have the option to configure your Application/Brand Name, Application Title, Logo, Favicon, Default Language, and many more. Navigate to <a href="{{ route('admin.settings.general') }}">Settings -> All Settings -> General</a> to configure your application. </p>
                                        </div>
                                    </li>


                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

        @endif


        <div class="row match-height">

            <div class="col-lg-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2 class="fw-bolder mb-0"><sup>{{ $customerCounts->active }}</sup>
                                / {{ $customerCounts->total }}</h2>
                            <p class="card-text">{{ __('locale.menu.Customers') }}</p>
                        </div>

                        <a href="{{ route('admin.customers.index') }}">
                            <div class="avatar bg-light-primary p-50 m-0">
                                <div class="avatar-content">
                                    <i data-feather="users" class="font-medium-5"></i>
                                </div>
                            </div>
                        </a>

                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2 class="fw-bolder mb-0"><sup>{{ $planCounts->active }}</sup>
                                / {{ $planCounts->total }}</h2>
                            <p class="card-text">{{ __('locale.menu.Plan') }}</p>
                        </div>

                        <a href="{{ route('admin.plans.index') }}">
                            <div class="avatar bg-light-success p-50 m-0">
                                <div class="avatar-content">
                                    <i data-feather="credit-card" class="font-medium-5"></i>
                                </div>
                            </div>
                        </a>

                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2 class="fw-bolder mb-0">{{ Reports::count() }}</h2>
                            <p class="card-text">{{ __('locale.labels.sms_send') }}</p>
                        </div>

                        <a href="{{ route('admin.reports.all') }}">
                            <div class="avatar bg-light-danger p-50 m-0">
                                <div class="avatar-content">
                                    <i data-feather="message-square" class="font-medium-5"></i>
                                </div>
                            </div>
                        </a>

                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2 class="fw-bolder mb-0">{{ Campaigns::count() }}</h2>
                            <p class="card-text">{{ __('locale.labels.campaigns_send') }}</p>
                        </div>

                        <a href="{{ route('admin.reports.campaigns') }}">
                            <div class="avatar bg-light-info p-50 m-0">
                                <div class="avatar-content">
                                    <i data-feather="send" class="font-medium-5"></i>
                                </div>
                            </div>
                        </a>

                    </div>
                </div>
            </div>

        </div>

        <div class="row match-height">

            <div class="col-lg-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2 class="fw-bolder mb-0"><sup>{{ $subscriptionCounts->active }}</sup>
                                / {{ $subscriptionCounts->total }}</h2>
                            <p class="card-text">{{ __('locale.menu.Subscriptions') }}</p>
                        </div>

                        <a href="{{ route('admin.subscriptions.index') }}">
                            <div class="avatar bg-light-success p-50 m-0">
                                <div class="avatar-content">
                                    <i data-feather="credit-card" class="font-medium-5"></i>
                                </div>
                            </div>
                        </a>

                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2 class="fw-bolder mb-0">{{ \App\Models\Announcements::count() }}</h2>
                            <p class="card-text">{{ __('locale.menu.Announcements') }}</p>
                        </div>

                        <a href="{{ route('admin.announcements.index') }}">
                            <div class="avatar bg-light-warning p-50 m-0">
                                <div class="avatar-content">
                                    <i data-feather="tv" class="font-medium-5"></i>
                                </div>
                            </div>
                        </a>

                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2 class="fw-bolder mb-0">
                                <sup>{{ Invoices::where('status', '!=', Invoices::STATUS_PAID)->count() }}</sup>
                                / {{ Invoices::where('status', Invoices::STATUS_PAID)->count() }}</h2>
                            <p class="card-text">{{ __('locale.menu.Invoices') }}</p>
                        </div>
                        <a href="{{ route('admin.invoices.index') }}">
                            <div class="avatar bg-light-primary p-50 m-0">
                                <div class="avatar-content">
                                    <i data-feather="shopping-cart" class="font-medium-5"></i>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2 class="fw-bolder mb-0"><sup>{{ $serverCounts->active }}</sup>
                                / {{ $serverCounts->total }}</h2>
                            <p class="card-text">{{ __('locale.menu.Sending Servers') }}</p>
                        </div>
                        <a href="{{ route('admin.sending-servers.index') }}">
                            <div class="avatar bg-light-primary p-50 m-0">
                                <div class="avatar-content">
                                    <i data-feather="send" class="font-medium-5"></i>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="row match-height">

            <div class="col-lg-6 col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-end">
                        <h4 class="card-title text-uppercase">{{ __('locale.labels.customers_growth') }}</h4>
                    </div>
                    <div class="card-content">
                        <div class="card-body pb-0">
                            <div id="customer-growth"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6 col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-end">
                        <h4 class="card-title text-uppercase">{{ __('locale.labels.sms_reports') }}</h4>
                    </div>
                    <div class="card-content">
                        <div class="card-body pb-0">
                            <div id="sms-reports"></div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <div class="row match-height">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-end">
                        <h4 class="card-title text-uppercase">{{ __('locale.labels.revenue_this_month') }}</h4>
                    </div>
                    <div class="card-content">
                        <div class="card-body pb-0">
                            <div id="revenue-chart"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row match-height">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0">{{ __('locale.labels.recent_sender_id_requests') }}</h4>
                    </div>
                    <div class="card-content">
                        <div class="table-responsive mt-1">
                            <table class="table table-hover-animation mb-0">
                                <thead>
                                <tr>
                                    <th style="width: 15%">{{ __('locale.labels.sender_id') }}</th>
                                    <th>{{ __('locale.labels.name') }}</th>
                                    <th>{{ __('locale.menu.Customer') }}</th>
                                    <th>{{ __('locale.plans.price') }}</th>
                                    <th>{{ __('locale.plans.validity') }}</th>
                                </tr>
                                </thead>
                                <tbody>

                                @foreach($sender_ids as $senderid)
                                    <tr>
                                        <td>
                                            <a href="{{ route('admin.senderid.show', $senderid->uid) }}">{{ $senderid->uid }}</a>
                                        </td>
                                        <td>{{ $senderid->sender_id }}</td>
                                        <td>
                                            <a href={{route('admin.customers.show', $senderid->user->uid)}}>{{ $senderid->user->displayName() }}</a>
                                        </td>
                                        <td>{{ Tool::format_price($senderid->price, $senderid->currency->format) }}</td>
                                        <td>{{ $senderid->displayFrequencyTime() }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>


        @foreach($smsTypes as $type)
            <div class="row match-height">
                <!-- Revenue Card -->
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h4 class="card-title text-uppercase">{{ __('locale.labels.sms_statistics', ['sms_type' => __('locale.labels.'.$type.'_sms')]) }}</h4>
                        </div>
                        <div class="card-body">
                            <div id="{{ $type }}_sms_data"></div>
                        </div>
                    </div>
                </div>
                <!--/ Revenue Card -->

            </div>

        @endforeach


    </section>
@endsection

@section('vendor-script')
    {{--     Vendor js files --}}
    <script src="{{ asset(mix('vendors/js/charts/apexcharts.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/extensions/tether.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/extensions/shepherd.min.js')) }}"></script>
@endsection


@section('page-script')
    <!-- Page js files -->


    <script>


        $(window).on("load", function () {

            let $primary = '#7367F0';
            let $strok_color = '#b9c3cd';
            let $label_color = '#e7eef7';
            let $purple = '#df87f2';
            let $textMutedColor = '#b9b9c3';
            let $stroke_color_2 = '#d0ccff';

            let $plainSmsData = document.querySelector('#plain_sms_data');
            let $unicodeSmsData = document.querySelector('#unicode_sms_data');
            let $voiceSmsData = document.querySelector('#voice_sms_data');
            let $mmsSmsData = document.querySelector('#mms_sms_data');
            let $whatsappSmsData = document.querySelector('#whatsapp_sms_data');
            let $viberSmsData = document.querySelector('#viber_sms_data');
            let $otpSmsData = document.querySelector('#otp_sms_data');

            function createChartOptions(height, xAxis, dataSet) {
                return {
                    chart: {
                        height: height,
                        toolbar: {show: false},
                        zoom: {enabled: false},
                        type: 'line',
                        offsetX: -10
                    },
                    stroke: {
                        curve: 'smooth',
                        dashArray: [0, 5, 12],
                        width: [5, 7, 5]
                    },
                    grid: {
                        borderColor: $label_color,
                        padding: {
                            top: -20,
                            bottom: -10,
                            left: 20
                        }
                    },
                    legend: {
                        show: false
                    },
                    colors: [$stroke_color_2, $strok_color, $purple],
                    fill: {
                        type: 'gradient',
                        gradient: {
                            shade: 'dark',
                            inverseColors: false,
                            gradientToColors: [window.colors.solid.primary, $strok_color, $stroke_color_2],
                            shadeIntensity: 1,
                            type: 'horizontal',
                            opacityFrom: 1,
                            opacityTo: 1,
                            stops: [0, 100, 100, 100]
                        }
                    },
                    markers: {
                        size: 0,
                        hover: {
                            size: 5
                        }
                    },
                    xaxis: {
                        labels: {
                            style: {
                                colors: $textMutedColor,
                                fontSize: '1rem'
                            }
                        },
                        axisTicks: {
                            show: false
                        },
                        categories: xAxis,
                        axisBorder: {
                            show: false
                        },
                        tickPlacement: 'on'
                    },
                    yaxis: {
                        tickAmount: 5,
                        labels: {
                            style: {
                                colors: $textMutedColor,
                                fontSize: '1rem'
                            },
                            formatter: function (val) {
                                return val > 999 ? (val / 1000).toFixed(0) + 'k' : val;
                            }
                        }
                    },
                    tooltip: {
                        x: {show: false}
                    },
                    series: dataSet
                };
            }

// Instantiate the charts
            let plainSmsData = new ApexCharts($plainSmsData, createChartOptions(240, {!! $charts['plain']->xAxis() !!}, {!! $charts['plain']->dataSet() !!}));
            plainSmsData.render();

            let unicodeSmsData = new ApexCharts($unicodeSmsData, createChartOptions(240, {!! $charts['unicode']->xAxis() !!}, {!! $charts['unicode']->dataSet() !!}));
            unicodeSmsData.render();

            let voiceSmsData = new ApexCharts($voiceSmsData, createChartOptions(240, {!! $charts['voice']->xAxis() !!}, {!! $charts['voice']->dataSet() !!}));
            voiceSmsData.render();

            let mmsSmsData = new ApexCharts($mmsSmsData, createChartOptions(240, {!! $charts['mms']->xAxis() !!}, {!! $charts['mms']->dataSet() !!}));
            mmsSmsData.render();

            let whatsappSmsData = new ApexCharts($whatsappSmsData, createChartOptions(240, {!! $charts['whatsapp']->xAxis() !!}, {!! $charts['whatsapp']->dataSet() !!}));
            whatsappSmsData.render();

            let viberSmsData = new ApexCharts($viberSmsData, createChartOptions(240, {!! $charts['viber']->xAxis() !!}, {!! $charts['viber']->dataSet() !!}));
            viberSmsData.render();

            let otpSmsData = new ApexCharts($otpSmsData, createChartOptions(240, {!! $charts['otp']->xAxis() !!}, {!! $charts['otp']->dataSet() !!}));
            otpSmsData.render();

            // revenue chart
            // -----------------------------

            let revenueChartOptions = {
                chart: {
                    height: 270,
                    toolbar: {show: false},
                    type: 'line',
                    dropShadow: {
                        enabled: true,
                        top: 20,
                        left: 2,
                        blur: 6,
                        opacity: 0.20
                    },
                },
                stroke: {
                    curve: 'smooth',
                    width: 4,
                },
                grid: {
                    borderColor: $label_color,
                },
                legend: {
                    show: false,
                },
                colors: [$purple],
                fill: {
                    type: 'gradient',
                    gradient: {
                        shade: 'dark',
                        inverseColors: false,
                        gradientToColors: [$primary],
                        shadeIntensity: 1,
                        type: 'horizontal',
                        opacityFrom: 1,
                        opacityTo: 1,
                        stops: [0, 100, 100, 100]
                    },
                },
                markers: {
                    size: 0,
                    hover: {
                        size: 5
                    }
                },
                xaxis: {
                    labels: {
                        style: {
                            colors: $strok_color,
                        }
                    },
                    axisTicks: {
                        show: false,
                    },
                    categories: {!! $charts['plain']->xAxis() !!},
                    axisBorder: {
                        show: false,
                    },
                    tickPlacement: 'on',
                    type: 'string'
                },
                yaxis: {
                    tickAmount: 5,
                    labels: {
                        style: {
                            color: $strok_color,
                        },
                        formatter: function (val) {
                            return val > 999 ? (val / 1000).toFixed(1) + 'k' : val.toFixed(1);
                        }
                    }
                },
                tooltip: {
                    x: {show: false}
                },
                series: {!! $revenue_chart->dataSet() !!}

            }

            let revenueChart = new ApexCharts(
                document.querySelector("#revenue-chart"),
                revenueChartOptions
            );

            revenueChart.render();

        });


        // Client growth Chart
        // ----------------------------------

        let clientGrowthChartoptions = {
            chart: {
                stacked: true,
                type: 'bar',
                toolbar: {show: false},
                height: 290,
            },
            plotOptions: {
                bar: {
                    columnWidth: '70%'
                }
            },
            colors: ['#7367F0'],
            series: {!! $customer_growth->dataSet() !!},
            grid: {
                borderColor: '#e7eef7',
                padding: {
                    left: 0,
                    right: 0
                }
            },
            legend: {
                show: true,
                position: 'top',
                horizontalAlign: 'left',
                offsetX: 0,
                fontSize: '14px',
                markers: {
                    radius: 50,
                    width: 10,
                    height: 10,
                }
            },
            dataLabels: {
                enabled: false
            },
            xaxis: {
                labels: {
                    style: {
                        colors: '#b9c3cd',
                    }
                },
                axisTicks: {
                    show: false,
                },
                categories: {!! $customer_growth->xAxis() !!},
                axisBorder: {
                    show: false,
                },
            },
            yaxis: {
                tickAmount: 5,
                labels: {
                    style: {
                        color: '#b9c3cd',
                    },
                    formatter: function (val) {
                        return val.toFixed(1)
                    }
                }
            },
            tooltip: {
                x: {show: false}
            },
        }

        let clientGrowthChart = new ApexCharts(
            document.querySelector("#customer-growth"),
            clientGrowthChartoptions
        );

        clientGrowthChart.render();


        // sms history Chart
        // -----------------------------

        let smsHistoryChartoptions = {
            chart: {
                type: 'pie',
                height: 325,
                dropShadow: {
                    enabled: false,
                    blur: 5,
                    left: 1,
                    top: 1,
                    opacity: 0.2
                },
                toolbar: {
                    show: false
                }
            },
            labels: ["{{ __('locale.labels.delivered') }}", "{{ __('locale.labels.failed') }}"],
            series: {!! $sms_history->dataSet() !!},
            dataLabels: {
                enabled: false
            },
            legend: {show: false},
            stroke: {
                width: 5
            },
            colors: ['#7367F0', '#EA5455'],
            fill: {
                type: 'gradient',
                gradient: {
                    gradientToColors: ['#9c8cfc', '#f29292']
                }
            }
        }

        let smsHistoryChart = new ApexCharts(
            document.querySelector("#sms-reports"),
            smsHistoryChartoptions
        );

        smsHistoryChart.render();


    </script>
@endsection

