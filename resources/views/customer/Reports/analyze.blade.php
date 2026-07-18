@extends('layouts/contentLayoutMaster')

@section('title', __('locale.menu.Dashboard'))

@section('vendor-style')
    <!-- vendor css files -->
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/charts/apexcharts.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/pickers/flatpickr/flatpickr.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
@endsection

@section('page-style')
    <link rel="stylesheet" href="{{ asset(mix('css/base/plugins/forms/pickers/form-flat-pickr.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('css/base/plugins/charts/chart-apex.css')) }}">
@endsection

@section('content')
    <!-- apex charts section start -->
    <section>
        <div class="row match-height">
            <!-- Area Chart starts -->
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex flex-sm-row flex-column justify-content-md-between align-items-start justify-content-start">
                        <div>
                            <h4 class="card-title">{{ __('locale.labels.sms_count') }}</h4>
                            <span class="card-subtitle text-muted">{{ __('locale.labels.sms_statistics_different_channel') }}</span>
                        </div>


                        <form action="{{ route('customer.reports.analyze') }}" method="post">
                            @csrf
                            <div class="d-flex align-items-center">

                                <select id="date-range-select" name="dateRangeSelect" class="form-select">
                                    <option @if(isset($request->dateRangeSelect) && $request->dateRangeSelect =="today") selected
                                            @endif value="today">
                                        {{ __('locale.labels.today') }}
                                    </option>

                                    <option @if(isset($request->dateRangeSelect) && $request->dateRangeSelect =="yesterday") selected
                                            @endif value="yesterday">
                                        {{ __('locale.labels.yesterday') }}
                                    </option>

                                    <option @if(isset($request->dateRangeSelect) && $request->dateRangeSelect =="this-week") selected
                                            @endif value="this-week">
                                        {{ __('locale.labels.this_week') }}
                                    </option>

                                    <option @if(isset($request->dateRangeSelect) && $request->dateRangeSelect =="last-week") selected
                                            @endif value="last-week">
                                        {{ __('locale.labels.last_week') }}
                                    </option>

                                    <option @if(isset($request->dateRangeSelect) && $request->dateRangeSelect =="last-7-days") selected
                                            @endif value="last-7-days">
                                        {{ __('locale.labels.last_7_days') }}
                                    </option>

                                    <option @if(isset($request->dateRangeSelect) && $request->dateRangeSelect =="last-30-days") selected
                                            @endif value="last-30-days">
                                        {{ __('locale.labels.last_30_days') }}
                                    </option>

                                    <option @if(isset($request->dateRangeSelect) && $request->dateRangeSelect =="last-60-days") selected
                                            @endif value="last-60-days">
                                        {{ __('locale.labels.last_60_days') }}
                                    </option>

                                    <option @if(isset($request->dateRangeSelect) && $request->dateRangeSelect =="last-90-days") selected
                                            @endif value="last-90-days">
                                        {{ __('locale.labels.last_90_days') }}
                                    </option>

                                    <option @if(isset($request->dateRangeSelect) && $request->dateRangeSelect =="this-year") selected
                                            @endif value="this-year">
                                        {{ __('locale.labels.this_year') }}
                                    </option>

                                    <option @if(isset($request->dateRangeSelect) && $request->dateRangeSelect =="last-year") selected
                                            @endif value="last-year">
                                        {{ __('locale.labels.last_year') }}
                                    </option>

                                    <option @if(isset($request->dateRangeSelect) && $request->dateRangeSelect =="period") selected
                                            @endif value="period">
                                        {{ __('locale.labels.custom_period') }}
                                    </option>
                                </select>

                                <input type="text" name="dateRange" value="{!! $request->dateRange ?? date('Y-m-d') !!}"
                                       class="form-control flat-picker bg-transparent border-0 shadow-none"
                                       placeholder="YYYY-MM-DD" id="date-range"/>


                                <button class="btn btn-sm" type="submit"><i class="text-primary"
                                                                            data-feather="search"></i></button>
                            </div>
                        </form>

                    </div>
                    <div class="card-body">
                        <div id="sms-unit-count"></div>
                    </div>
                </div>

                @if (count($reports) > 0)
                    @foreach ($reports as $report)
                        <div class="card card-statistics">
                            <div class="card-header">
                                <h4 class="card-title text-uppercase">{{ __('locale.labels.sms_statistics', ['sms_type' => $report->sms_type]) }}</h4>
                            </div>
                            <div class="card-body statistics-body">
                                <div class="row">
                                    <div class="col-md-3 col-sm-6 col-12 mb-2 mb-md-0">
                                        <div class="d-flex flex-row">
                                            <div class="avatar bg-light-primary me-2">
                                                <div class="avatar-content">
                                                    <i data-feather="send" class="avatar-icon"></i>
                                                </div>
                                            </div>
                                            <div class="my-auto">
                                                <h4 class="fw-bolder mb-0">{{ $report->total_sms }}</h4>
                                                <p class="card-text font-small-3 mb-0">{{ __('locale.labels.total') }} {{ __('locale.labels.sms') }}</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3 col-sm-6 col-12 mb-2 mb-md-0">
                                        <div class="d-flex flex-row">
                                            <div class="avatar bg-light-info me-2">
                                                <div class="avatar-content">
                                                    <i data-feather="dollar-sign" class="avatar-icon"></i>
                                                </div>
                                            </div>
                                            <div class="my-auto">
                                                <h4 class="fw-bolder mb-0">{{ $report->total_cost }}</h4>
                                                <p class="card-text font-small-3 mb-0">{{ __('locale.labels.total') }} {{ __('locale.labels.cost') }}</p>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-md-3 col-sm-6 col-12">
                                        <div class="d-flex flex-row">
                                            <div class="avatar bg-light-success me-2">
                                                <div class="avatar-content">
                                                    <i data-feather="check-square" class="avatar-icon"></i>
                                                </div>
                                            </div>
                                            <div class="my-auto">
                                                <h4 class="fw-bolder mb-0">{{ $report->delivered_sms }}</h4>
                                                <p class="card-text font-small-3 mb-0">{{ __('locale.labels.delivered') }}</p>
                                            </div>
                                        </div>
                                    </div>


                                    <div class="col-md-3 col-sm-6 col-12 mb-2 mb-sm-0">
                                        <div class="d-flex flex-row">
                                            <div class="avatar bg-light-danger me-2">
                                                <div class="avatar-content">
                                                    <i data-feather="x-square" class="avatar-icon"></i>
                                                </div>
                                            </div>
                                            <div class="my-auto">
                                                <h4 class="fw-bolder mb-0">{{ $report->not_delivered_sms }}</h4>
                                                <p class="card-text font-small-3 mb-0">{{ __('locale.labels.failed') }}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                @else
                    <div class="alert alert-primary" role="alert">
                        <div class="alert-body">
                            <strong>{{ __('locale.labels.opps') }}</strong> {{ __('locale.datatables.no_results') }}
                        </div>
                    </div>
                @endif


            </div>
            <!-- Area Chart ends -->


            <!-- Apex charts section end -->
        </div>
    </section>
@endsection

@section('vendor-script')
    <!-- vendor files -->
    <script src="{{ asset(mix('vendors/js/charts/apexcharts.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/pickers/flatpickr/flatpickr.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/forms/select/select2.full.min.js')) }}"></script>
@endsection

@section('page-script')
    <script>


        $(function () {
            'use strict';

            // Basic Select2 select
            $(".select2").each(function () {
                let $this = $(this);
                $this.wrap('<div class="position-relative"></div>');
                $this.select2({
                    // the following code is used to disable x-scrollbar when click in select input and
                    // take 100% width in responsive also
                    dropdownAutoWidth: true,
                    width: '100%',
                    dropdownParent: $this.parent()
                });
            });


            // Initialize flatpickr date range picker
            $("#date-range").flatpickr({
                mode: "range",
                dateFormat: "Y-m-d",
            });

            // Show flatpickr date range picker on select option
            $("#date-range-select").on("change", function () {
                const optionValue = $(this).val();
                const range = [];
                const today = new Date();
                const dayOfWeek = today.getDay();

                switch (optionValue) {
                    case "today":
                        range.push(new Date());
                        break;
                    case "yesterday":
                        range.push(new Date());
                        range.push(new Date());
                        range[0].setDate(range[0].getDate() - 1);
                        range[1].setDate(range[1].getDate() - 1);
                        break;
                    case "this-week":
                        range.push(new Date(today.getFullYear(), today.getMonth(), today.getDate() - dayOfWeek));
                        range.push(new Date(today.getFullYear(), today.getMonth(), today.getDate() - dayOfWeek + 6));
                        break;
                    case "last-week":
                        range.push(new Date(today.getFullYear(), today.getMonth(), today.getDate() - dayOfWeek - 7));
                        range.push(new Date(today.getFullYear(), today.getMonth(), today.getDate() - dayOfWeek - 1));
                        break;
                    case "last-7-days":
                        range.push(new Date());
                        range.push(new Date());
                        range[0].setDate(range[0].getDate() - 7);
                        break;
                    case "last-30-days":
                    case 'period':
                        range.push(new Date());
                        range.push(new Date());
                        range[0].setDate(range[0].getDate() - 30);
                        break;
                    case "last-60-days":
                        range.push(new Date());
                        range.push(new Date());
                        range[0].setDate(range[0].getDate() - 60);
                        break;
                    case "last-90-days":
                        range.push(new Date());
                        range.push(new Date());
                        range[0].setDate(range[0].getDate() - 90);
                        break;
                    case "this-year":
                        range.push(new Date(new Date().getFullYear(), 0, 1));
                        range.push(new Date(new Date().getFullYear(), 11, 31));
                        break;
                    case "last-year":
                        range.push(new Date(new Date().getFullYear() - 1, 0, 1));
                        range.push(new Date(new Date().getFullYear() - 1, 11, 31));
                        break;
                }

                // Set the selected range in the flatpickr date range picker
                $("#date-range").flatpickr({
                    defaultDate: range,
                    mode: 'range'
                });
            });


            let isRtl = $('html').attr('data-textdirection') === 'rtl',
                chartColors = {
                    column: {
                        unicode: '#00FFD2',
                        whatsapp: '#FFFFD2',
                        mms: '#C5FAD5',
                        voice: '#AA96DA',
                        sms: '#F7C5CC',
                        bg: '#f8d3ff'
                    },

                };


            // Area Chart
            // --------------------------------------------------------------------
            let areaChartEl = document.querySelector('#sms-unit-count'),
                areaChartConfig = {
                    chart: {
                        height: 400,
                        type: 'bar',
                        stacked: true,
                        parentHeightOffset: 0,
                        toolbar: {
                            show: true
                        }
                    },
                    plotOptions: {
                        bar: {
                            columnWidth: '15%',
                            colors: {
                                backgroundBarColors: [
                                    chartColors.column.bg,
                                    chartColors.column.bg,
                                    chartColors.column.bg,
                                    chartColors.column.bg,
                                    chartColors.column.bg
                                ],
                                backgroundBarRadius: 10
                            }
                        }
                    },
                    dataLabels: {
                        enabled: false
                    },
                    legend: {
                        show: true,
                        position: 'top',
                        horizontalAlign: 'start'
                    },
                    colors: [chartColors.column.sms, chartColors.column.voice, chartColors.column.mms, chartColors.column.whatsapp, chartColors.column.unicode],
                    stroke: {
                        show: true,
                        colors: ['transparent']
                    },
                    grid: {
                        xaxis: {
                            lines: {
                                show: true
                            }
                        }
                    },
                    series: {!! $chart->dataSet() !!},
                    xaxis: {
                        categories: {!! $chart->xAxis() !!},
                    },
                    fill: {
                        opacity: 1
                    },
                    yaxis: {
                        opposite: isRtl
                    }
                };
            if (typeof areaChartEl !== undefined && areaChartEl !== null) {
                let areaChart = new ApexCharts(areaChartEl, areaChartConfig);
                areaChart.render();
            }

        });
    </script>
@endsection
