@extends('layouts/contentLayoutMaster')

@section('title', $title)

@section('vendor-style')
    {{-- vendor css files --}}
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/tables/datatable/dataTables.bootstrap5.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/tables/datatable/responsive.bootstrap5.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/tables/datatable/buttons.bootstrap5.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/extensions/sweetalert2.min.css')) }}">

    <link rel="stylesheet" href="{{ asset(mix('vendors/css/pickers/flatpickr/flatpickr.min.css')) }}">
@endsection


@section('page-style')
    <link rel="stylesheet" href="{{ asset(mix('css/base/plugins/forms/pickers/form-flat-pickr.css')) }}">
@endsection

@section('content')

    <!-- Basic table -->
    <section id="datatables-basic">

        <div class="row match-height">
            <div class="col-lg-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2 class="fw-bolder mb-0">{{ $reportStatusCounts->delivered_count }}</h2>
                            <p class="card-text">{{  __('locale.labels.delivered') }}</p>
                        </div>

                        <div class="avatar bg-light-success p-50 m-0">
                            <div class="avatar-content">
                                <i data-feather="check-square" class="font-medium-5"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2 class="fw-bolder mb-0">{{ $reportStatusCounts->enroute_count }}</h2>
                            <p class="card-text">{{  __('locale.labels.enroute') }}</p>
                        </div>

                        <div class="avatar bg-light-primary p-50 m-0">
                            <div class="avatar-content">
                                <i data-feather="truck" class="font-medium-5"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2 class="fw-bolder mb-0">{{ $reportStatusCounts->expired_count }}</h2>
                            <p class="card-text">{{  __('locale.labels.expired') }}</p>
                        </div>

                        <div class="avatar bg-light-warning p-50 m-0">
                            <div class="avatar-content">
                                <i data-feather="alert-triangle" class="font-medium-5"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>


            <div class="col-lg-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2 class="fw-bolder mb-0">{{ $reportStatusCounts->undelivered_count }}</h2>
                            <p class="card-text">{{  __('locale.labels.undelivered') }}</p>
                        </div>

                        <div class="avatar bg-light-danger p-50 m-0">
                            <div class="avatar-content">
                                <i data-feather="x-square" class="font-medium-5"></i>
                            </div>
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
                            <h2 class="fw-bolder mb-0">{{ $reportStatusCounts->rejected_count }}</h2>
                            <p class="card-text">{{  __('locale.labels.rejected') }}</p>
                        </div>

                        <div class="avatar bg-light-danger p-50 m-0">
                            <div class="avatar-content">
                                <i data-feather="x-circle" class="font-medium-5"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2 class="fw-bolder mb-0">{{ $reportStatusCounts->accepted_count }}</h2>
                            <p class="card-text">{{  __('locale.labels.accepted') }}</p>
                        </div>

                        <div class="avatar bg-light-primary p-50 m-0">
                            <div class="avatar-content">
                                <i data-feather="check-circle" class="font-medium-5"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2 class="fw-bolder mb-0">{{ $reportStatusCounts->skipped_count }}</h2>
                            <p class="card-text">{{  __('locale.labels.skipped') }}</p>
                        </div>

                        <div class="avatar bg-light-warning p-50 m-0">
                            <div class="avatar-content">
                                <i data-feather="skip-forward" class="font-medium-5"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>


            <div class="col-lg-3 col-sm-6 col-12">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2 class="fw-bolder mb-0">{{ $reportStatusCounts->failed_count }}</h2>
                            <p class="card-text">{{  __('locale.labels.failed') }}</p>
                        </div>

                        <div class="avatar bg-light-danger p-50 m-0">
                            <div class="avatar-content">
                                <i data-feather="x-octagon" class="font-medium-5"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>


        </div>


        <div class="mb-3 mt-2">
            @if(Auth::user()->customer->getOption('delete_sms_history') == 'yes')
                <div class="btn-group">
                    <button
                            class="btn btn-primary fw-bold dropdown-toggle"
                            type="button"
                            id="bulk_actions"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                    >
                        {{ __('locale.labels.actions') }}
                    </button>
                    <div class="dropdown-menu" aria-labelledby="bulk_actions">

                        {{--                        <a class="dropdown-item bulk-dlr" href="#"><i
                                                            data-feather="file-text"></i> DLR</a>--}}

                        <a class="dropdown-item bulk-delete" href="#"><i
                                    data-feather="trash"></i> {{ __('locale.datatables.bulk_delete') }}</a>
                    </div>
                </div>
            @endif

            @if(Auth::user()->customer->getOption('list_export') == 'yes')
                <div class="btn-group">
                    <a href="#" class="btn btn-info waves-light waves-effect fw-bold mx-1" data-bs-toggle="modal"
                       data-bs-target="#exportData"> {{__('locale.buttons.export')}} <i
                                data-feather="file-text"></i></a>
                </div>

                <div class="modal fade" id="exportData" tabindex="-1" role="dialog" aria-labelledby="addSendingSever"
                     aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered" role="document">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h4 class="modal-title" id="myModalLabel33">{{__('locale.buttons.export')}}</h4>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"
                                        aria-label="Close"></button>
                            </div>

                            <form action="{{ route('customer.reports.export.all') }}" method="post">
                                @csrf
                                <div class="modal-body">

                                    <div class="row">

                                        <div class="col-md-6">
                                            <div class="mb-1">
                                                <label for="start-date-picker"
                                                       class="form-label">{{ __('locale.labels.start_time') }}:</label>
                                                <input type="text" id="start-date-picker" name="start_date"
                                                       class="form-control date_picker" placeholder="YYYY-MM-DD" />
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="mb-1">
                                                <label for="start-time-picker" class="form-label"></label>
                                                <input type="text" id="start-time-picker"
                                                       class="form-control time_picker text-left" name="start_time"
                                                       placeholder="HH:MM" />
                                            </div>
                                        </div>

                                    </div>


                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-1">
                                                <label for="end-date-picker"
                                                       class="form-label">{{ __('locale.labels.end_time') }}:</label>
                                                <input type="text" id="end-date-picker" name="end_date"
                                                       class="form-control date_picker" placeholder="YYYY-MM-DD" />
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="mb-1">
                                                <label for="end-time-picker" class="form-label"></label>
                                                <input type="text" id="end-time-picker"
                                                       class="form-control time_picker text-left" name="end_time"
                                                       placeholder="HH:MM" />
                                            </div>
                                        </div>

                                    </div>


                                    <div class="mb-1">
                                        <label class="form-label" for="direction">{{ __('locale.labels.direction') }}
                                            : </label>
                                        <select class="form-select" name="direction">
                                            <option value="0">{{ __('locale.labels.select_one') }}</option>
                                            <option value="outgoing">{{ __('locale.labels.outgoing') }}</option>
                                            <option value="incoming">{{ __('locale.labels.incoming') }}</option>
                                            <option value="api">{{ __('locale.labels.api') }}</option>
                                        </select>
                                    </div>

                                    <div class="mb-1">
                                        <label class="form-label" for="type">{{ __('locale.labels.type') }}: </label>
                                        <select class="form-select" name="type">
                                            <option value="0">{{ __('locale.labels.select_one') }}</option>
                                            <option value="plain">{{ __('locale.labels.plain') }}</option>
                                            <option value="unicode">{{ __('locale.labels.unicode') }}</option>
                                            <option value="voice">{{ __('locale.labels.voice') }}</option>
                                            <option value="mms">{{ __('locale.labels.mms') }}</option>
                                            <option value="whatsapp">{{ __('locale.labels.whatsapp') }}</option>
                                            <option value="viber">{{ __('locale.menu.Viber') }}</option>
                                            <option value="otp">{{ __('locale.menu.OTP') }}</option>
                                            <option value="api">{{ __('locale.labels.api') }}</option>
                                        </select>
                                    </div>


                                    <div class="mb-1">
                                        <label class="form-label">{{__('locale.labels.status')}}: </label>
                                        <input type="text" name="status" class="form-control">
                                    </div>


                                    <div class="mb-1">
                                        <label class="form-label">{{__('locale.labels.to')}}: </label>
                                        <input type="text" name="to" class="form-control">
                                    </div>

                                    <div class="mb-1">
                                        <label class="form-label">{{__('locale.labels.from')}}: </label>
                                        <input type="text" name="from" class="form-control">
                                    </div>
                                </div>

                                <div class="modal-footer">
                                    <button type="submit" class="btn btn-primary"><i
                                                data-feather="file-text"></i> {{ __('locale.labels.generate') }}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

            @endif
        </div>


        <div class="row match-height">
            <div class="col-12">
                <div class="card">
                    <div class="card-header"></div>
                    <div class="card-content">
                        <div class="card-body">


                            <div class="row">

                                <div class="col-md-6">

                                    <div class="mb-1">
                                        <label for="date-range-select"
                                               class="form-label">{{ __('locale.labels.period') }}:</label>
                                        <select id="date-range-select" name="dateRangeSelect" class="form-select">
                                            <option value="0">{{ __('locale.labels.select_one') }}</option>
                                            <option value="today">{{ __('locale.labels.today') }}</option>
                                            <option value="yesterday">{{ __('locale.labels.yesterday') }}</option>
                                            <option value="this-week">{{ __('locale.labels.this_week') }}</option>
                                            <option value="last-week">{{ __('locale.labels.last_week') }}</option>
                                            <option value="last-7-days">{{ __('locale.labels.last_7_days') }}</option>
                                            <option value="last-30-days">{{ __('locale.labels.last_30_days') }}</option>
                                            <option value="last-60-days">{{ __('locale.labels.last_60_days') }}</option>
                                            <option value="last-90-days">{{ __('locale.labels.last_90_days') }}</option>
                                            <option value="this-year">{{ __('locale.labels.this_year') }}</option>
                                            <option value="last-year">{{ __('locale.labels.last_year') }}</option>
                                            <option value="period">{{ __('locale.labels.custom_period') }}</option>
                                        </select>
                                    </div>

                                    <div class="mb-1">
                                        <label></label>
                                        <input type="text" name="dateRange" class="form-control flat-picker"
                                               placeholder="YYYY-MM-DD" id="date-range" />
                                    </div>


                                    <div class="mb-1">
                                        <label class="form-label" for="direction">{{ __('locale.labels.direction') }}
                                            : </label>
                                        <select class="form-select" name="direction" id="direction">
                                            <option value="0">{{ __('locale.labels.select_one') }}</option>
                                            <option value="outgoing">{{ __('locale.labels.outgoing') }}</option>
                                            <option value="incoming">{{ __('locale.labels.incoming') }}</option>
                                            <option value="api">{{ __('locale.labels.api') }}</option>
                                        </select>
                                    </div>

                                    <div class="mb-1">
                                        <label class="form-label" for="type">{{ __('locale.labels.type') }}: </label>
                                        <select class="form-select" name="type" id="type">
                                            <option value="0">{{ __('locale.labels.select_one') }}</option>
                                            <option value="plain">{{ __('locale.labels.plain') }}</option>
                                            <option value="unicode">{{ __('locale.labels.unicode') }}</option>
                                            <option value="voice">{{ __('locale.labels.voice') }}</option>
                                            <option value="mms">{{ __('locale.labels.mms') }}</option>
                                            <option value="whatsapp">{{ __('locale.labels.whatsapp') }}</option>
                                            <option value="viber">{{ __('locale.menu.Viber') }}</option>
                                            <option value="otp">{{ __('locale.menu.OTP') }}</option>
                                            <option value="api">{{ __('locale.labels.api') }}</option>
                                        </select>
                                    </div>

                                </div>

                                <div class="col-md-6">

                                    <div class="mb-1">
                                        <label class="form-label" for="customer_status">{{__('locale.labels.status')}}
                                            : </label>
                                        <input type="text" name="customer_status" id="customer_status"
                                               class="form-control">
                                    </div>

                                    <div class="mb-1">
                                        <label class="form-label" for="to">{{__('locale.labels.to')}}: </label>
                                        <input type="text" name="to" class="form-control" id="to"
                                               @if(isset($recipient)) value="{{ $recipient }}" @endif>
                                    </div>


                                    <div class="mb-1">
                                        <label class="form-label" for="from">{{__('locale.labels.from')}}: </label>
                                        <input type="text" name="from" id="from" class="form-control">
                                    </div>


                                    <div class="mb-1">
                                        <label class="form-label" for="message_id">{{__('locale.labels.message_id')}}
                                            : </label>
                                        <input type="text" name="message_id" class="form-control" id="message_id">
                                    </div>
                                </div>


                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card">


                    <div id="processingLoader" class="processing-loader" style="display: none;">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <strong class="text-success">{{ __('locale.datatables.processing') }}</strong>
                                    <div class="spinner-border ms-auto text-success spinner-grow" role="status"
                                         aria-hidden="true"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <table class="table datatables-basic">
                        <thead>
                        <tr>
                            <th></th>
                            <th></th>
                            <th>{{__('locale.labels.id')}} <br><small
                                        class="text-muted">{{ __('locale.labels.date') }}</small></th>
                            <th>{{__('locale.labels.direction')}} <br><small
                                        class="text-muted">{{ __('locale.labels.type') }}</small></th>
                            <th>{{__('locale.labels.from')}} <br><small
                                        class="text-muted">{{ __('locale.labels.to') }}</small></th>
                            <th>{{__('locale.labels.sms_count')}} <br><small
                                        class="text-muted">{{ __('locale.labels.cost') }}</small></th>
                            <th>{{__('locale.labels.status')}}</th>
                            <th>{{__('locale.labels.actions')}}</th>
                        </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
    </section>
    <!--/ Basic table -->

@endsection


@section('vendor-script')
    {{-- vendor files --}}
    <script src="{{ asset(mix('vendors/js/tables/datatable/jquery.dataTables.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/tables/datatable/dataTables.bootstrap5.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/tables/datatable/dataTables.responsive.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/tables/datatable/responsive.bootstrap5.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/tables/datatable/datatables.checkboxes.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/tables/datatable/datatables.buttons.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/tables/datatable/buttons.html5.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/tables/datatable/dataTables.rowGroup.min.js')) }}"></script>

    <script src="{{ asset(mix('vendors/js/pickers/flatpickr/flatpickr.min.js')) }}"></script>

    <script src="{{ asset(mix('vendors/js/extensions/sweetalert2.all.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/extensions/polyfill.min.js')) }}"></script>

@endsection
@section('page-script')
    {{-- Page js files --}}
    <script>
      $(document).ready(function() {
        "use strict";

        let datePicker = $(".date_picker"),
          dateRangePicker = $("#date-range"),
          processingLoader = $("#processingLoader"),
          timePicker = $(".time_picker");

        if (datePicker.length) {
          datePicker.flatpickr({
            maxDate: "today",
            dateFormat: "Y-m-d"
          });
        }

        if (timePicker.length) {
          timePicker.flatpickr({
            enableTime: true,
            noCalendar: true,
            dateFormat: "H:i"
          });
        }

        // Initialize flatpickr date range picker
        dateRangePicker.flatpickr({
          mode: "range",
          dateFormat: "Y-m-d",
          onClose: function(selectedDates, dateStr, instance) {
            // Hide the flatpickr date range picker after selection
            instance.close();
          }
        });

        // Show flatpickr date range picker on select option
        $("#date-range-select").on("change", function() {
          const optionValue = $(this).val();
          const range = [];
          const today = new Date();
          const dayOfWeek = today.getDay();

          switch (optionValue) {
            case "0":
              dateRangePicker.val(null);
              break;
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
            case "period":
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
          dateRangePicker.flatpickr({
            defaultDate: range,
            mode: "range"
          });
        });


        //show response message
        function showResponseMessage(data) {

          if (data.status === "success") {
            toastr["success"](data.message, '{{__('locale.labels.success')}}!!', {
              closeButton: true,
              positionClass: "toast-top-right",
              progressBar: true,
              newestOnTop: true,
              rtl: isRtl
            });
            dataListView.draw(false);
          } else if (data.status === "error") {
            toastr["error"](data.message, '{{ __('locale.labels.opps') }}!', {
              closeButton: true,
              positionClass: "toast-top-right",
              progressBar: true,
              newestOnTop: true,
              rtl: isRtl
            });
            dataListView.draw(false);
          } else {
            toastr["warning"]("{{__('locale.exceptions.something_went_wrong')}}", '{{ __('locale.labels.warning') }}!', {
              closeButton: true,
              positionClass: "toast-top-right",
              progressBar: true,
              newestOnTop: true,
              rtl: isRtl
            });
          }


          // Fix: Replace icons after redraw
          setTimeout(() => {
            feather.replace();
          }, 100); // slight delay to ensure DOM update completes
        }

        // init table dom
        let Table = $("table");

        // init list view datatable
        let dataListView = $(".datatables-basic")
          .on("preXhr.dt", function() {
            processingLoader.show();
          })
          .on("draw.dt", function() {
            processingLoader.hide();
          }).DataTable({

            "processing": true,
            "serverSide": true,
            "bFilter": false,
            "ajax": {
              "url": "{{ route('customer.reports.search.all', ['recipient' => $recipient]) }}",
              "dataType": "json",
              "type": "POST",
              "data": function(d) {
                // Add search values to data object
                d.direction = $("#direction").val();
                d.type = $("#type").val();
                d.message_id = $("#message_id").val();
                d.dateRange = dateRangePicker.val();
                d.customerStatus = $("#customer_status").val();
                d.from = $("#from").val();
                d.to = $("#to").val();
                d._token = "{{csrf_token()}}";
              }
            },
            "columns": [
              { "data": "responsive_id", orderable: false, searchable: false },
              { "data": "uid" },
              { "data": "user_id" },
              { "data": "direction" },
              { "data": "from" },
              { "data": "sms_count" },
              { "data": "customer_status" },
              { "data": "action", orderable: false, searchable: false }
            ],

            searchDelay: 1500,
            columnDefs: [
              {
                // For Responsive
                className: "control",
                orderable: false,
                responsivePriority: 2,
                targets: 0
              },
              {
                // For Checkboxes
                targets: 1,
                orderable: false,
                responsivePriority: 3,
                render: function(data) {
                  return (
                    "<div class=\"form-check\"> <input class=\"form-check-input dt-checkboxes\" type=\"checkbox\" value=\"\" id=\"" +
                    data +
                    "\" /><label class=\"form-check-label\" for=\"" +
                    data +
                    "\"></label></div>"
                  );
                },
                checkboxes: {
                  selectAllRender:
                    "<div class=\"form-check\"> <input class=\"form-check-input\" type=\"checkbox\" value=\"\" id=\"checkboxSelectAll\" /><label class=\"form-check-label\" for=\"checkboxSelectAll\"></label></div>",
                  selectRow: true
                }
              },
              {
                //direction and type
                targets: 2,
                render: function(data, type, full) {
                  const uid = full["uid"] || "N/A";
                  const createdAt = full["created_at"] || "N/A";

                  return `
      <div class="d-flex flex-column">
        <span>${uid}</span>
        <span>${createdAt}</span>
      </div>
    `;
                }
              },
              {
                //direction and type
                targets: 3,
                render: function(data, type, full) {
                  const smsType = full["sms_type"] || "N/A";
                  const direction = full["direction"] || "N/A";

                  return `
      <div class="d-flex flex-column">
        <span>${direction}</span>
        <span>${smsType}</span>
      </div>
    `;
                }
              },

              {
                //from and to
                targets: 4,
                responsivePriority: 4,
                render: function(data, type, full) {
                  const from = full["from"] || "N/A";
                  const to = full["to"] || "N/A";

                  return `
      <div class="d-flex flex-column">
        <span class="emp_name text-truncate mb-1">${from}</span>
        <span class="emp_name text-truncate">${to}</span>
      </div>
    `;
                }
              },

              {
                //sms count and cost
                targets: 5,
                render: function(data, type, full) {
                  const sms_count = full["sms_count"] || "N/A";
                  const cost = full["cost"] || "N/A";

                  return `
      <div class="d-flex flex-column">
        <span class="emp_name text-truncate mb-1">{{ __('locale.labels.sms_count') }}: ${sms_count}</span>
        <span class="emp_name text-truncate">{{ __('locale.labels.cost') }}: ${cost}</span>
      </div>
    `;
                }
              },


              {
                // Actions
                targets: -1,
                title: '{{ __('locale.labels.actions') }}',
                orderable: false,
                render: function(data, type, full) {
                  let $actions = "";
                  if (full["can_delete"]) {
                    $actions += "<span class=\"action-delete text-danger pe-1 cursor-pointer\" data-id=" + full["uid"] + ">" +
                      feather.icons["trash"].toSvg({ class: "font-medium-4" }) +
                      "</span>";
                  }

                  if (full["dlr"]) {
                    $actions += "<span class=\"action-dlr text-primary pe-1 cursor-pointer\" data-bs-toggle=\"tooltip\" data-bs-placement=\"top\" title=\"DLR\" data-id=" + full["uid"] + ">" +
                      feather.icons["file-text"].toSvg({ class: "font-medium-4" }) +
                      "</span>";
                  }

                  return (
                    $actions +
                    "<span class=\"action-view text-primary pe-1 cursor-pointer\" data-id=" + full["uid"] + ">" +
                    feather.icons["eye"].toSvg({ class: "font-medium-4" }) +
                    "</span>"
                  );
                }
              }
            ],
            dom: "<\"d-flex justify-content-between align-items-center mx-0 row\"<\"col-sm-12 col-md-6\"l><\"col-sm-12 col-md-6\"f>>t<\"d-flex justify-content-between mx-0 row\"<\"col-sm-12 col-md-6\"i><\"col-sm-12 col-md-6\"p>>",

            language: {
              paginate: {
                // remove previous & next text from pagination
                previous: "&nbsp;",
                next: "&nbsp;"
              },
              sLengthMenu: "_MENU_",
              sZeroRecords: "{{ __('locale.datatables.no_results') }}",
              sSearch: "{{ __('locale.datatables.search') }}",
              sProcessing: "{{ __('locale.datatables.processing') }}",
              sInfo: "{{ __('locale.datatables.showing_entries', ['start' => '_START_', 'end' => '_END_', 'total' => '_TOTAL_']) }}"
            },
            responsive: {
              details: {
                type: "column",
                renderer: function(api, rowIdx, columns) {
                  let data = $.map(columns, function(col) {
                    return col.title !== "" // ? Do not show row in modal popup if title is blank (for check box)
                      ? "<tr data-dt-column=\"" +
                      col.columnIndex +
                      "\">" +
                      "<td>" +
                      col.title +
                      ":" +
                      "</td> " +
                      "<td>" +
                      col.data +
                      "</td>" +
                      "</tr>"
                      : "";
                  }).join("");

                  return data ? $("<table class=\"table\"/>").append("<tbody>" + data + "</tbody>") : false;
                }
              }
            },
            aLengthMenu: [[10, 20, 50, 100], [10, 20, 50, 100]],
            select: {
              style: "multi"
            },
            order: [[2, "desc"]],
            displayLength: 10
          });


        // Apply search on select fields
        $("select").on("change", function() {
          const column = dataListView.column($(this).attr("name"));
          column.search($(this).val()).draw();
        });


        // Apply search on input fields
        let debounceTimer;

        $("input").on("keyup", function() {
          clearTimeout(debounceTimer);
          debounceTimer = setTimeout(function() {
            const column = dataListView.column($(this).attr("name"));
            column.search($(this).val()).draw();
          }.bind(this), 500);
        });

        // Apply search on date range field
        dateRangePicker.on("change", function() {
          const column = dataListView.column($(this).attr("name"));
          column.search($(this).val()).draw();
        });

        // On view
        Table.delegate(".action-view", "click", function(e) {
          e.stopPropagation();
          let id = $(this).data("id");
          $.ajax({
            url: "{{ url('/reports')}}" + "/" + id + "/view",
            type: "POST",
            data: {
              _token: "{{csrf_token()}}"
            },
            success: function(data) {
              Swal.fire({
                html: `<div class="table-responsive">
<table class="table">

        <tbody>
            <tr>
                <td width="35%">{{ __('locale.labels.from') }}</td>
                <td>` + data.data.from + `</td>
            </tr>
            <tr>
                <td width="35%">{{ __('locale.labels.to') }}</td>
                <td>` + data.data.to + `</td>
            </tr>
            <tr>
                <td width="35%">{{ __('locale.labels.message') }}</td>
                <td>` + data.data.message + `</td>
            </tr>
            <tr>
                <td width="35%">{{ __('locale.labels.type') }}</td>
                <td>` + data.data.sms_type + `</td>
            </tr>
            <tr>
                <td width="35%">{{ __('locale.labels.status') }}</td>
                <td>` + data.data.customer_status + `</td>
            </tr>
            <tr>
                <td width="35%">{{ __('locale.labels.sms_count') }}</td>
                <td>` + data.data.sms_count + `</td>
            </tr>
            <tr>
                <td width="35%">{{ __('locale.labels.cost') }}</td>
                <td>` + data.data.cost + `</td>
            </tr>

</tbody>
</table>
</div>
`
              });
            },
            error: function(reject) {
              if (reject.status === 422) {
                let errors = reject.responseJSON.errors;
                $.each(errors, function(key, value) {
                  toastr["warning"](value[0], "{{__('locale.labels.attention')}}", {
                    closeButton: true,
                    positionClass: "toast-top-right",
                    progressBar: true,
                    newestOnTop: true,
                    rtl: isRtl
                  });
                });
              } else {
                toastr["warning"](reject.responseJSON.message, "{{__('locale.labels.attention')}}", {
                  closeButton: true,
                  positionClass: "toast-top-right",
                  progressBar: true,
                  newestOnTop: true,
                  rtl: isRtl
                });
              }
            }
          });

        });

        // On Delete
        Table.delegate(".action-delete", "click", function(e) {
          e.stopPropagation();
          let id = $(this).data("id");
          Swal.fire({
            title: "{{ __('locale.labels.are_you_sure') }}",
            text: "{{ __('locale.labels.able_to_revert') }}",
            icon: "warning",
            showCancelButton: true,
            confirmButtonText: "{{ __('locale.labels.delete_it') }}",
            customClass: {
              confirmButton: "btn btn-primary",
              cancelButton: "btn btn-outline-danger ms-1"
            },
            buttonsStyling: false
          }).then(function(result) {
            if (result.value) {
              $.ajax({
                url: "{{ url('/reports')}}" + "/" + id + "/destroy",
                type: "POST",
                data: {
                  _token: "{{csrf_token()}}"
                },
                success: function(data) {
                  showResponseMessage(data);
                },
                error: function(reject) {
                  if (reject.status === 422) {
                    let errors = reject.responseJSON.errors;
                    $.each(errors, function(key, value) {
                      toastr["warning"](value[0], "{{__('locale.labels.attention')}}", {
                        closeButton: true,
                        positionClass: "toast-top-right",
                        progressBar: true,
                        newestOnTop: true,
                        rtl: isRtl
                      });
                    });
                  } else {
                    toastr["warning"](reject.responseJSON.message, "{{__('locale.labels.attention')}}", {
                      closeButton: true,
                      positionClass: "toast-top-right",
                      progressBar: true,
                      newestOnTop: true,
                      rtl: isRtl
                    });
                  }
                }
              });
            }
          });
        });

        //Bulk Delete
        $(".bulk-delete").on("click", function(e) {

          e.preventDefault();

          Swal.fire({
            title: "{{__('locale.labels.are_you_sure')}}",
            text: "{{__('locale.campaigns.delete_sms')}}",
            icon: "warning",
            showCancelButton: true,
            confirmButtonText: "{{__('locale.labels.delete_selected')}}",
            customClass: {
              confirmButton: "btn btn-primary",
              cancelButton: "btn btn-outline-danger ms-1"
            },
            buttonsStyling: false
          }).then(function(result) {
            if (result.value) {
              let sms_ids = [];
              let rows_selected = dataListView.column(1).checkboxes.selected();

              $.each(rows_selected, function(index, rowId) {
                sms_ids.push(rowId);
              });

              if (sms_ids.length > 0) {

                $.ajax({
                  url: "{{ route('customer.reports.batch_action') }}",
                  type: "POST",
                  data: {
                    _token: "{{csrf_token()}}",
                    action: "destroy",
                    ids: sms_ids
                  },
                  success: function(data) {
                    showResponseMessage(data);
                  },
                  error: function(reject) {
                    if (reject.status === 422) {
                      let errors = reject.responseJSON.errors;
                      $.each(errors, function(key, value) {
                        toastr["warning"](value[0], "{{__('locale.labels.attention')}}", {
                          closeButton: true,
                          positionClass: "toast-top-right",
                          progressBar: true,
                          newestOnTop: true,
                          rtl: isRtl
                        });
                      });
                    } else {
                      toastr["warning"](reject.responseJSON.message, "{{__('locale.labels.attention')}}", {
                        closeButton: true,
                        positionClass: "toast-top-right",
                        progressBar: true,
                        newestOnTop: true,
                        rtl: isRtl
                      });
                    }
                  }
                });
              } else {
                toastr["warning"]("{{__('locale.labels.at_least_one_data')}}", "{{__('locale.labels.attention')}}", {
                  closeButton: true,
                  positionClass: "toast-top-right",
                  progressBar: true,
                  newestOnTop: true,
                  rtl: isRtl
                });
              }

            }
          });
        });


        // On DLR
        Table.delegate(".action-dlr", "click", function(e) {
          e.stopPropagation();
          let id = $(this).data("id");
          Swal.fire({
            title: "Check Delivery Report",
            text: "Are you sure you want to check delivery report?",
            icon: "success",
            showCancelButton: true,
            confirmButtonText: "{{ __('locale.labels.yes') }}",
            customClass: {
              confirmButton: "btn btn-primary",
              cancelButton: "btn btn-outline-danger ms-1"
            },
            buttonsStyling: false
          }).then(function(result) {
            if (result.value) {
              e.stopPropagation();
              $.ajax({
                url: "{{ url('/reports')}}" + "/" + id + "/dlr",
                type: "POST",
                data: {
                  _token: "{{csrf_token()}}"
                },
                success: function(data) {
                  showResponseMessage(data);
                },
                error: function(reject) {
                  if (reject.status === 422) {
                    let errors = reject.responseJSON.errors;
                    $.each(errors, function(key, value) {
                      toastr["warning"](value[0], "{{__('locale.labels.attention')}}", {
                        closeButton: true,
                        positionClass: "toast-top-right",
                        progressBar: true,
                        newestOnTop: true,
                        rtl: isRtl
                      });
                    });
                  } else {
                    toastr["warning"](reject.responseJSON.message, "{{__('locale.labels.attention')}}", {
                      closeButton: true,
                      positionClass: "toast-top-right",
                      progressBar: true,
                      newestOnTop: true,
                      rtl: isRtl
                    });
                  }
                }
              });

            }
          });
        });


        //Bulk DLR
        $(".bulk-dlr").on("click", function(e) {

          e.preventDefault();

          Swal.fire({
            title: "Check Delivery Report",
            text: "Are you sure you want to check delivery report?",
            icon: "success",
            showCancelButton: true,
            confirmButtonText: "Check",
            customClass: {
              confirmButton: "btn btn-primary",
              cancelButton: "btn btn-outline-danger ms-1"
            },
            buttonsStyling: false
          }).then(function(result) {
            if (result.value) {
              let sms_ids = [];
              let rows_selected = dataListView.column(1).checkboxes.selected();

              $.each(rows_selected, function(index, rowId) {
                sms_ids.push(rowId);
              });

              if (sms_ids.length > 0) {

                $.ajax({
                  url: "{{ route('customer.reports.batch_action') }}",
                  type: "POST",
                  data: {
                    _token: "{{csrf_token()}}",
                    action: "dlr",
                    ids: sms_ids
                  },
                  success: function(data) {
                    showResponseMessage(data);
                  },
                  error: function(reject) {
                    if (reject.status === 422) {
                      let errors = reject.responseJSON.errors;
                      $.each(errors, function(key, value) {
                        toastr["warning"](value[0], "{{__('locale.labels.attention')}}", {
                          closeButton: true,
                          positionClass: "toast-top-right",
                          progressBar: true,
                          newestOnTop: true,
                          rtl: isRtl
                        });
                      });
                    } else {
                      toastr["warning"](reject.responseJSON.message, "{{__('locale.labels.attention')}}", {
                        closeButton: true,
                        positionClass: "toast-top-right",
                        progressBar: true,
                        newestOnTop: true,
                        rtl: isRtl
                      });
                    }
                  }
                });
              } else {
                toastr["warning"]("{{__('locale.labels.at_least_one_data')}}", "{{__('locale.labels.attention')}}", {
                  closeButton: true,
                  positionClass: "toast-top-right",
                  progressBar: true,
                  newestOnTop: true,
                  rtl: isRtl
                });
              }

            }
          });
        });


      });

    </script>
@endsection
