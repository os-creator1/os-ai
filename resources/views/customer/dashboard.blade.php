@php use App\Library\Tool;use App\Models\Campaigns; @endphp
@extends('layouts/contentLayoutMaster')

@section('title', __('locale.menu.Dashboard'))

@section('vendor-style')
    {{-- vendor css files --}}
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/charts/apexcharts.css')) }}">
@endsection
@section('page-style')
    {{-- Page css files --}}
    <link rel="stylesheet" href="{{ asset(mix('css/base/pages/dashboard-ecommerce.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('css/base/plugins/charts/chart-apex.css')) }}">
@endsection

@section('content')
    {{-- Dashboard Analytics Start --}}
    <section>

        @unless($userAnnouncements->isEmpty())
            <div class="row match-height">
                <div class="col-12 announcement-card">
                    <div class="card">
                        <div class="card-header">
                            <div class="d-flex align-items-center">
                                <h4 class="card-title">{{ __('locale.menu.Announcements') }}</h4>
                            </div>
                            <a href="#" class="mark_read h5 text-muted text-uppercase"><i data-feather="x-circle"
                                                                                          class="font-medium-3 cursor-pointer"></i> {{ __('locale.buttons.close') }}
                            </a>
                        </div>
                        <hr class="my-0">
                        <div class="card-body alert-primary">
                            <ul class="timeline ms-50">
                                @foreach($userAnnouncements as $announcement)
                                    @if($announcement->pivot->read_at === null)
                                        <li class="timeline-item">
                                            <span class="timeline-point timeline-point-indicator"></span>
                                            <div class="timeline-event">
                                                <div class="d-flex justify-content-between flex-sm-row flex-column mb-sm-0 mb-1">
                                                    <a href="{{ route('user.account.announcement.view', $announcement->uid) }}">
                                                        <h6>{{ $announcement->title }}</h6></a>
                                                    <span class="timeline-event-time me-1">{{ $announcement->created_at->diffForHumans() }}</span>
                                                </div>
                                                <p>{!! $announcement->description !!}</p>

                                            </div>
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        @endunless

        @php
            $isAdminImpersonation = session()->has('admin_user_id') && session()->has('temp_user_id');
        @endphp

        @if(Auth::check() && Auth::user()->parent_id !== null && !$isAdminImpersonation)
            <div class="row match-height">
                <div class="col-12 announcement-card">
                    <div class="card border shadow-sm">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h4 class="card-title mb-0">{{ __('locale.sub_accounts.manage_account') }}</h4>
                            <a href="#" class="mark_read h5 text-muted text-uppercase">
                                <i data-feather="x-circle" class="font-medium-3 cursor-pointer"></i>
                                {{ __('locale.buttons.close') }}
                            </a>
                        </div>
                        <hr class="my-0">

                        <div class="card-body alert-primary text-center">
                            <p class="mb-2">{{ __('locale.sub_accounts.login_as_parent_message') }}</p>
                            <a href="{{ route('user.account.login_as', Auth::user()->parent->uid) }}"
                               class="btn btn-primary">
                                {{ __('locale.sub_accounts.login_as_parent') }}:
                                <strong>{{ Auth::user()->parent->displayName() }}</strong>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        @endif


        <div class="row match-height">
            <div class="col-lg-6 col-sm-6 col-12">
                <div class="card">
                    <div class="card-header"></div>
                    <div class="card-body">
                        <h3 class="text-primary">{{ \App\Helpers\Helper::greetingMessage()}}</h3>
                        <p class="font-medium-2 mt-2">{{ __('locale.description.dashboard', ['brandname' => config('app.name')]) }}</p>

                        <div class="row d-flex justify-content-center">
                            @can('sms_quick_send')
                                <div class="col-lg-4 col-sm-6 col-6 pb-1">
                                    <a href="{{ route('customer.sms.quick_send') }}"
                                       class="btn btn-sm btn-warning text-nowrap"><i
                                                data-feather="send"></i>

                                        <span>{{__('locale.menu.Quick Send')}}</span>
                                    </a>
                                </div>
                            @endcan

                            @can('sms_campaign_builder')
                                <div class="col-lg-4 col-sm-6 col-6 pb-1">
                                    <a href="{{ route('customer.sms.campaign_builder') }}"
                                       class="btn btn-sm btn-success text-nowrap"><i
                                                data-feather="server"></i>

                                        <span>{{__('locale.menu.Campaign Builder')}}</span></a>
                                </div>
                            @endcan

                            @can('view_contact_group')
                                <div class="col-lg-4 col-sm-6 col-6 pb-1">
                                    <a href="{{ route('customer.contacts.index') }}"
                                       class="btn btn-sm btn-info text-nowrap"><i
                                                data-feather="user"></i>
                                        <span>{{__('locale.contacts.contact_groups')}}</span>
                                    </a>
                                </div>
                            @endcan


                        </div>

                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-body">
                        <h3 class="text-primary">{{ __('locale.labels.current_plan')  }}</h3>
                        @if(isset(Auth::user()->customer) && Auth::user()->customer->activeSubscription() == null)
                            <h3 class="mt-1 text-danger">{{ __('locale.subscription.no_active_subscription') }}</h3>
                        @else

                            @if(isset(Auth::user()->customer))
                                <p class="mb-2 mt-1 font-medium-2">{!! __('locale.subscription.you_are_currently_subscribed_to_plan',
                                        [
                                                'plan' => auth()->user()->customer->subscription->plan->name,
                                                'price' => Tool::format_price(auth()->user()->customer->subscription->plan->price, auth()->user()->customer->subscription->plan->currency->format),
                                                'remain' => Tool::formatHumanTime(auth()->user()->customer->subscription->current_period_ends_at),
                                                'end_at' => Tool::customerDateTime(auth()->user()->customer->subscription->current_period_ends_at)
                                        ]) !!}</p>
                            @endif
                        @endif

                        <div class="d-flex justify-content-between">
                            <a href="{{ route('customer.subscriptions.index') }}" class="btn btn-sm btn-primary me-1"><i
                                        data-feather="info"></i> {{ __('locale.labels.more_info') }}</a>
                            @if (isset(Auth::user()->customer) && Auth::user()->customer->activeSubscription())
                                <a href="{{ route('customer.subscriptions.change_plan', auth()->user()->customer->subscription->uid) }}"
                                   class="btn btn-sm btn-info"><i
                                            data-feather="credit-card"></i> {{ __('locale.labels.packages') }}</a>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-end">
                        <h4 class="card-title text-uppercase">{{ __('locale.labels.sms_reports') }}</h4>
                    </div>
                    <div class="card-content">
                        <div class="card-body p-0">
                            <div id="sms-reports" class="my-2"></div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <div class="row match-height">

            <div class="col-lg-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2 class="fw-bolder mb-0">

                                @php
                                    $campaigns = Campaigns::where('user_id', Auth::user()->id);
                                    $totalCamp = $campaigns->count();
                                    $deliveredCamp = $campaigns->where('status', '!=', Campaigns::STATUS_DONE)->count();
                                @endphp

                                <sup>{{ $deliveredCamp }}</sup>
                                / {{ $totalCamp }}</h2>
                            <p class="card-text">{{ str_plural(__('locale.menu.Campaigns')) }}</p>
                        </div>
                        <a href="{{route('customer.reports.campaigns')}}">
                            <div class="avatar bg-light-info p-50 m-0">
                                <div class="avatar-content">
                                    <i data-feather="pie-chart" class="text-info font-medium-5"></i>
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

                                <sup>{{ $deliveredCount }}</sup>
                                / {{ $total_sms_sent }}</h2>
                            <p class="card-text">{{ __('locale.labels.delivered') }}</p>
                        </div>
                        <a href="{{route('customer.reports.all')}}">
                            <div class="avatar bg-light-success p-50 m-0">
                                <div class="avatar-content">
                                    <i data-feather="phone-outgoing" class="text-success font-medium-5"></i>
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

                                <sup>{{ $undeliveredCount }}</sup>
                                / {{ $total_sms_sent }}</h2>
                            <p class="card-text">{{ __('locale.labels.failed') }}</p>
                        </div>
                        <a href="{{route('customer.reports.all')}}">
                            <div class="avatar bg-light-danger p-50 m-0">
                                <div class="avatar-content">
                                    <i data-feather="x-square" class="text-danger font-medium-5"></i>
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
                            <h2 class="fw-bolder mb-0">{{ Auth::user()->customer->smsTemplateCounts() }}</h2>
                            <p class="card-text">{{ str_plural(__('locale.permission.sms_template')) }}</p>
                        </div>
                        <a href="{{ route('customer.templates.index') }}">
                            <div class="avatar bg-light-warning p-50 m-0">
                                <div class="avatar-content">
                                    <i data-feather="inbox" class="text-warning font-medium-5"></i>
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
                        @if(isset(Auth::user()->customer) && Auth::user()->customer->activeSubscription() != null)
                            <div>
                                <h2 class="fw-bolder mb-0"> {{ Auth::user()->customer->listsCount() != null ? Tool::format_number(Auth::user()->customer->listsCount()): 0 }}</h2>
                                <p class="card-text">{{ __('locale.contacts.contact_groups') }}</p>
                            </div>
                        @else
                            <div>
                                <h2 class="fw-bolder mb-0"> 0</h2>
                                <p class="card-text">{{ __('locale.contacts.contact_groups') }}</p>
                            </div>
                        @endif
                        <a href="{{ route('customer.contacts.index') }}">
                            <div class="avatar bg-light-primary p-50 m-0">
                                <div class="avatar-content">
                                    <i data-feather="users" class="text-primary font-medium-5"></i>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-header">
                        @if(isset(Auth::user()->customer) && Auth::user()->customer->activeSubscription() != null)
                            <div>
                                <h2 class="fw-bolder mb-0">{{ Auth::user()->customer->subscriberCounts() != null ? Tool::format_number(Auth::user()->customer->subscriberCounts()) : 0 }}</h2>
                                <p class="card-text">{{ __('locale.menu.Contacts') }}</p>
                            </div>
                        @else
                            <div>
                                <h2 class="fw-bolder mb-0">0</h2>
                                <p class="card-text">{{ __('locale.menu.Contacts') }}</p>
                            </div>
                        @endif
                        <a href="{{ route('customer.contacts.index') }}">
                            <div class="avatar bg-light-success p-50 m-0">
                                <div class="avatar-content">
                                    <i data-feather="user" class="text-success font-medium-5"></i>
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
                                <sup>{{ \App\Models\Invoices::where('user_id', Auth::user()->id)->where('status', \App\Models\Invoices::STATUS_UNPAID)->orWhere('status', \App\Models\Invoices::STATUS_PENDING)->count() }}</sup>
                                / {{ \App\Models\Invoices::where('user_id', Auth::user()->id)->count() }}</h2>
                            <p class="card-text">{{ str_plural(__('locale.menu.Invoices')) }}</p>
                        </div>
                        <a href="{{ route('customer.subscriptions.index') }}">
                            <div class="avatar bg-light-info p-50 m-0">
                                <div class="avatar-content">
                                    <i data-feather="shopping-cart" class="text-info font-medium-5"></i>
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
                            <h2 class="fw-bolder mb-0">{{ Auth::user()->customer->blacklistCounts() }}</h2>
                            <p class="card-text">{{ str_plural(__('locale.menu.Blacklist')) }}</p>
                        </div>
                        <a href="{{ route('customer.blacklists.index') }}">
                            <div class="avatar bg-light-danger p-50 m-0">
                                <div class="avatar-content">
                                    <i data-feather="user-x" class="text-danger font-medium-5"></i>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>


        @canany(['quick_send', 'sms_campaign_builder', 'sms_bulk_messages'])
            <div class="row match-height">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h4 class="card-title text-uppercase">{{ __('locale.labels.sms_statistics', ['sms_type' => __('locale.labels.plain_sms')]) }}</h4>
                        </div>
                        <div class="card-body">
                            <div id="plain_sms_data"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row match-height">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h4 class="card-title text-uppercase">{{ __('locale.labels.sms_statistics', ['sms_type' => __('locale.labels.unicode_sms')]) }}</h4>
                        </div>
                        <div class="card-body">
                            <div id="unicode_sms_data"></div>
                        </div>
                    </div>
                </div>
            </div>
        @endcanany

        @canany(['voice_campaign_builder', 'voice_quick_send', 'voice_bulk_messages'])
            <div class="row match-height">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h4 class="card-title text-uppercase">{{ __('locale.labels.sms_statistics', ['sms_type' => __('locale.labels.voice_sms')]) }}</h4>
                        </div>
                        <div class="card-body">
                            <div id="voice_sms_data"></div>
                        </div>
                    </div>
                </div>
            </div>
        @endcanany


        @canany(['mms_campaign_builder', 'mms_quick_send', 'mms_bulk_messages'])
            <div class="row match-height">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h4 class="card-title text-uppercase">{{ __('locale.labels.sms_statistics', ['sms_type' => __('locale.labels.mms_sms')]) }}</h4>
                        </div>
                        <div class="card-body">
                            <div id="mms_sms_data"></div>
                        </div>
                    </div>
                </div>
            </div>
        @endcanany


        @canany(['whatsapp_campaign_builder', 'whatsapp_quick_send', 'whatsapp_bulk_messages'])
            <div class="row match-height">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h4 class="card-title text-uppercase">{{ __('locale.labels.sms_statistics', ['sms_type' => __('locale.labels.whatsapp_sms')]) }}</h4>
                        </div>
                        <div class="card-body">
                            <div id="whatsapp_sms_data"></div>
                        </div>
                    </div>
                </div>
            </div>
        @endcanany


        @canany(['viber_campaign_builder', 'viber_quick_send', 'viber_bulk_messages'])
            <div class="row match-height">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h4 class="card-title text-uppercase">{{ __('locale.labels.sms_statistics', ['sms_type' => __('locale.labels.viber_sms')]) }}</h4>
                        </div>
                        <div class="card-body">
                            <div id="viber_sms_data"></div>
                        </div>
                    </div>
                </div>
            </div>
        @endcanany


        @canany(['otp_campaign_builder', 'otp_quick_send', 'otp_bulk_messages'])
            <div class="row match-height">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h4 class="card-title text-uppercase">{{ __('locale.labels.sms_statistics', ['sms_type' => __('locale.labels.otp_sms')]) }}</h4>
                        </div>
                        <div class="card-body">
                            <div id="otp_sms_data"></div>
                        </div>
                    </div>
                </div>
            </div>
        @endcanany


    </section>
    <!-- Dashboard Analytics end -->
@endsection


@section('vendor-script')
    {{--     Vendor js files --}}
    <script src="{{ asset(mix('vendors/js/charts/apexcharts.min.js')) }}"></script>
@endsection


@section('page-script')

    <script>
      function percentage(partialValue, totalValue) {
        return (100 * partialValue) / totalValue;
      }

      $(window).on("load", function() {

        let $strok_color = "#b9c3cd";
        let $label_color = "#e7eef7";
        let $purple = "#df87f2";
        let $textMutedColor = "#b9b9c3";
        let $stroke_color_2 = "#d0ccff";

        let $plainSmsData = document.querySelector("#plain_sms_data");
        let $unicodeSmsData = document.querySelector("#unicode_sms_data");
        let $voiceSmsData = document.querySelector("#voice_sms_data");
        let $mmsSmsData = document.querySelector("#mms_sms_data");
        let $whatsappSmsData = document.querySelector("#whatsapp_sms_data");
        let $viberSmsData = document.querySelector("#viber_sms_data");
        let $otpSmsData = document.querySelector("#otp_sms_data");


        $(".mark_read").on("click", function(e) {
          e.stopPropagation();

          $.ajax({
            url: "{{ route('user.account.announcement.mark-all-as-read') }}",
            type: "POST",
            data: {
              _token: "{{csrf_token()}}"
            },
            success: function(response) {
              if (response.success) {
                $(".announcement-card").fadeOut(1000);
              } else {
                toastr["warning"](response.message, "{{__('locale.labels.attention')}}", {
                  closeButton: true,
                  positionClass: "toast-top-right",
                  progressBar: true,
                  newestOnTop: true,
                  rtl: isRtl
                });
              }
            },
            error: function(error) {
              toastr["warning"](error.responseText, "{{__('locale.labels.attention')}}", {
                closeButton: true,
                positionClass: "toast-top-right",
                progressBar: true,
                newestOnTop: true,
                rtl: isRtl
              });
            }
          });

        });

        function createChartOptions(height, xAxis, dataSet) {
          return {
            chart: {
              height: height,
              toolbar: { show: false },
              zoom: { enabled: false },
              type: "line",
              offsetX: -10
            },
            stroke: {
              curve: "smooth",
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
              type: "gradient",
              gradient: {
                shade: "dark",
                inverseColors: false,
                gradientToColors: [window.colors.solid.primary, $strok_color, $stroke_color_2],
                shadeIntensity: 1,
                type: "horizontal",
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
                  fontSize: "1rem"
                }
              },
              axisTicks: {
                show: false
              },
              categories: xAxis,
              axisBorder: {
                show: false
              },
              tickPlacement: "on"
            },
            yaxis: {
              tickAmount: 5,
              labels: {
                style: {
                  colors: $textMutedColor,
                  fontSize: "1rem"
                },
                formatter: function(val) {
                  return val > 999 ? (val / 1000).toFixed(0) + "k" : val;
                }
              }
            },
            tooltip: {
              x: { show: false }
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


        // sms history Chart
        // -----------------------------

        let smsHistoryChartoptions = {
          chart: {
            type: "pie",
            height: 180,
            toolbar: {
              show: false
            }
          },
          labels: ["{{ __('locale.labels.delivered') }}", "{{ __('locale.labels.failed') }}"],
          series: {!! $sms_history->dataSet() !!},
          dataLabels: {
            enabled: false
          },
          legend: { show: false },
          stroke: {
            width: 4
          },
          colors: ["#7367F0", "#EA5455"]
        };

        let smsHistoryChart = new ApexCharts(
          document.querySelector("#sms-reports"),
          smsHistoryChartoptions
        );

        smsHistoryChart.render();

      });

    </script>

@endsection
