@extends('layouts/contentLayoutMaster')

@section('title', $customer->displayName())
@section('vendor-style')
    <!-- vendor css files -->
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/tables/datatable/dataTables.bootstrap5.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/tables/datatable/responsive.bootstrap5.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/tables/datatable/buttons.bootstrap5.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/extensions/sweetalert2.min.css')) }}">
    <link rel='stylesheet' href="{{ asset(mix('vendors/css/animate/animate.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
@endsection


@section('page-style')
    <!-- Page css files -->
    <link rel="stylesheet" href="{{ asset(mix('css/base/plugins/extensions/ext-component-sweet-alerts.css')) }}">
@endsection

@section('content')

    <section class="users-edit">

        <div class="row">
            <div class="col-12">

                <ul class="nav nav-pills mb-2" role="tablist">
                    <!-- Account -->
                    <li class="nav-item">
                        <a class="nav-link @if (old('tab') == 'account' || old('tab') == null) active @endif"
                           id="account-tab" data-bs-toggle="tab" href="#account" aria-controls="account" role="tab"
                           aria-selected="true">
                            <i data-feather="user" class="font-medium-3 me-50"></i>
                            <span class="fw-bold">{{__('locale.labels.account')}}</span>
                        </a>
                    </li>

                    <!-- information -->
                    <li class="nav-item">
                        <a class="nav-link {{ old('tab') == 'information' ? 'active':null }}" id="information-tab"
                           data-bs-toggle="tab" href="#information" aria-controls="information" role="tab"
                           aria-selected="false">
                            <i data-feather="info" class="font-medium-3 me-50"></i>
                            <span class="fw-bold">{{ __('locale.labels.information') }}</span>
                        </a>
                    </li>


                    <!-- permissions -->
                    <li class="nav-item">
                        <a class="nav-link {{ old('tab') == 'permission' ? 'active':null }}" id="permission-tab"
                           data-bs-toggle="tab" href="#permission" aria-controls="permission" role="tab"
                           aria-selected="false">
                            <i data-feather="lock" class="font-medium-3 me-50"></i>
                            <span class="fw-bold">{{ __('locale.labels.permissions') }}</span>
                        </a>
                    </li>


                    <!-- subscriptions -->
                    <li class="nav-item">
                        <a class="nav-link {{ old('tab') == 'usms_subscription' ? 'active':null }}"
                           id="usms_subscription-tab" data-bs-toggle="tab" href="#usms_subscription"
                           aria-controls="usms_subscription" role="tab" aria-selected="false">
                            <i data-feather="bookmark" class="font-medium-3 me-50"></i>
                            <span class="fw-bold">{{ __('locale.menu.Subscriptions') }}</span>
                        </a>
                    </li>

                    <!-- pricing -->
                    <li class="nav-item">
                        <a class="nav-link {{ old('tab') == 'usms_pricing' ? 'active':null }}" id="usms_pricing-tab"
                           data-bs-toggle="tab" href="#usms_pricing" aria-controls="usms_pricing" role="tab"
                           aria-selected="false">
                            <i data-feather="tag" class="font-medium-3 me-50"></i>
                            <span class="fw-bold">{{ __('locale.plans.pricing') }}</span>
                        </a>
                    </li>

                    <!-- Version 3.8 -->
                    <!-- sending server -->
                    <li class="nav-item">
                        <a class="nav-link {{ old('tab') == 'usms_sending_server' ? 'active':null }}"
                           id="usms_sending_server-tab" data-bs-toggle="tab" href="#usms_sending_server"
                           aria-controls="usms_sending_server" role="tab" aria-selected="false">
                            <i data-feather="send" class="font-medium-3 me-50"></i>
                            <span class="fw-bold">{{ __('locale.menu.Sending Servers') }}</span>
                        </a>
                    </li>


                    {{--Webhook URL For Inbound--}}
                    <li class="nav-item">
                        <a class="nav-link {{ old('tab') == 'usms_webhook_url' ? 'active':null }}"
                           id="usms_webhook_url-tab" data-bs-toggle="tab" href="#usms_webhook_url"
                           aria-controls="usms_webhook_url" role="tab" aria-selected="false">
                            <i data-feather="link" class="font-medium-3 me-50"></i>
                            <span class="fw-bold">{{ __('locale.developers.webhook_url') }}</span>
                        </a>
                    </li>


                    @if(config('app.trai_dlt') == 1
                        && isset($customer->customer)
                        && $customer->customer->activeSubscription()?->plan?->is_dlt)
                        {{--Entity ID--}}
                        <li class="nav-item">
                            <a class="nav-link {{ old('tab') == 'usms_dlt_entity_id' ? 'active':null }}"
                               id="usms_dlt_entity_id-tab" data-bs-toggle="tab" href="#usms_dlt_entity_id"
                               aria-controls="usms_dlt_entity_id" role="tab" aria-selected="false">
                                <i data-feather="key" class="font-medium-3 me-50"></i>
                                <span class="fw-bold">{{ __('locale.labels.dlt') }} {{ __('locale.labels.entity_id') }}</span>
                            </a>
                        </li>

                        {{--Telemarketer ID--}}
                        <li class="nav-item">
                            <a class="nav-link {{ old('tab') == 'usms_telemarketer_id' ? 'active':null }}"
                               id="usms_telemarketer_id-tab" data-bs-toggle="tab" href="#usms_telemarketer_id"
                               aria-controls="usms_telemarketer_id" role="tab" aria-selected="false">
                                <i data-feather="headphones" class="font-medium-3 me-50"></i>
                                <span class="fw-bold">{{ __('locale.labels.dlt') }} {{ __('locale.labels.telemarketer_id') }}</span>
                            </a>
                        </li>
                    @endif


                </ul>


                <div class="tab-content">

                    <div class="tab-pane  @if (old('tab') == 'account' || old('tab') == null) active @endif"
                         id="account" aria-labelledby="account-tab" role="tabpanel">
                        <!-- users edit account form start -->
                        @include('admin.customer._account')
                        <!-- users edit account form ends -->

                    </div>

                    <div class="tab-pane {{ old('tab') == 'information' ? 'active':null }}" id="information"
                         aria-labelledby="information-tab" role="tabpanel">
                        <!-- users edit Info form start -->
                        @include('admin.customer._information')
                        <!-- users edit Info form ends -->
                    </div>

                    <div class="tab-pane {{ old('tab') == 'permission' ? 'active':null }}" id="permission"
                         aria-labelledby="permission-tab" role="tabpanel">
                        <!-- user permission form start -->
                        @include('admin.customer._permissions')
                        <!-- user permission form end -->
                    </div>

                    <div class="tab-pane {{ old('tab') == 'usms_subscription' ? 'active':null }}" id="usms_subscription"
                         aria-labelledby="usms_subscription-tab" role="tabpanel">
                        @include('admin.customer._subscription')
                    </div>
                    {{--
                                        <div class="tab-pane" id="notifications" aria-labelledby="notifications-tab" role="tabpanel">
                                            @include('admin.customer._notifications')
                                        </div>--}}

                    <div class="tab-pane {{ old('tab') == 'usms_pricing' ? 'active':null }}" id="usms_pricing"
                         aria-labelledby="usms_pricing-tab" role="tabpanel">
                        @if(isset($customer->customer->subscription) && $customer->customer->activeSubscription() !== null)
                            @include('admin.customer._pricing')
                        @else
                            <div class="card">
                                <div class="card-header border-bottom">
                                    <h4 class="card-title">{{ __('locale.plans.pricing')}}</h4>
                                </div>
                                <div class="card-body pt-1">
                                    <div class="col-12">
                                        <h5 class="text-center text-info">{!! __('locale.subscription.no_active_subscription')  !!}</h5>
                                        <div class="row justify-content-center mt-2">
                                            <a href="{{ route('admin.subscriptions.create', ['customer_id' => $customer->id]) }}"
                                               class="btn btn-primary">{{ __('locale.buttons.new_subscription') }}</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>

                    {{--Version 3.8--}}
                    {{--Sending server--}}
                    <div class="tab-pane {{ old('tab') == 'usms_sending_server' ? 'active':null }}"
                         id="usms_sending_server" aria-labelledby="usms_sending_server-tab" role="tabpanel">
                        @if(isset($customer->customer->subscription) && $customer->customer->activeSubscription() !== null)
                            @include('admin.customer._sending_server')
                        @else
                            <div class="card">
                                <div class="card-header border-bottom">
                                    <h4 class="card-title">{{ __('locale.plans.pricing')}}</h4>
                                </div>
                                <div class="card-body pt-1">
                                    <div class="col-12">
                                        <h5 class="text-center text-info">{!! __('locale.subscription.no_active_subscription')  !!}</h5>
                                        <div class="row justify-content-center mt-2">
                                            <a href="{{ route('admin.subscriptions.create', ['customer_id' => $customer->id]) }}"
                                               class="btn btn-primary">{{ __('locale.buttons.new_subscription') }}</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>

                    <div class="tab-pane {{ old('tab') == 'usms_webhook_url' ? 'active':null }}"
                         id="usms_webhook_url" aria-labelledby="usms_webhook_url-tab" role="tabpanel">
                        @include('admin.customer._webhook')
                    </div>

                    @php
                        $subscription = $customer->customer->activeSubscription() ?? null;
                        $plan = $subscription ? $subscription->plan ?? null : null;
                    @endphp

                    @if(config('app.trai_dlt') == 1
                        && isset($customer->customer)
                        && $customer->customer->activeSubscription()?->plan?->is_dlt)

                        {{--Dlt Entity ID--}}
                        <div class="tab-pane {{ old('tab') == 'usms_dlt_entity_id' ? 'active':null }}"
                             id="usms_dlt_entity_id" aria-labelledby="usms_dlt_entity_id-tab" role="tabpanel">
                            @include('admin.customer._dlt_entity_id')
                        </div>

                        {{--Telemarketer ID--}}
                        <div class="tab-pane {{ old('tab') == 'usms_telemarketer_id' ? 'active':null }}"
                             id="usms_telemarketer_id" aria-labelledby="usms_telemarketer_id-tab" role="tabpanel">
                            @include('admin.customer._dlt_telemarketer_id')
                        </div>
                    @endif

                </div>
            </div>
        </div>
    </section>
@endsection

@section('vendor-script')

    <script src="{{ asset(mix('vendors/js/tables/datatable/jquery.dataTables.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/tables/datatable/dataTables.bootstrap5.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/tables/datatable/dataTables.responsive.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/tables/datatable/responsive.bootstrap5.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/tables/datatable/datatables.buttons.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/tables/datatable/buttons.html5.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/extensions/sweetalert2.all.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/forms/select/select2.full.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/extensions/polyfill.min.js')) }}"></script>
@endsection


@section('page-script')
    {{-- Page js files --}}
    <script src="{{asset('js/scripts/components/components-navs.js')}}"></script>

    <script>

        $(document).ready(function () {
            "use strict"

            {{--let userText = $("#copy-to-webhook-input");--}}
            {{--let btnCopy = $("#btn-webhook-url-copy");--}}

            {{--// copy text on click--}}
            {{--btnCopy.on("click", function () {--}}

            {{--    let webHookUrlText;--}}
            {{--    webHookUrlText = userText.html();--}}
            {{--    copyToWebHookURL(webHookUrlText);--}}

            {{--});--}}


            {{--function copyToWebHookURL(text) {--}}

            {{--    let textArea = document.createElement("textarea");--}}
            {{--    textArea.value = text;--}}
            {{--    document.body.appendChild(textArea);--}}
            {{--    textArea.select();--}}

            {{--    try {--}}
            {{--        let successful = document.execCommand('copy');--}}
            {{--        let msg = successful ? 'Copied' : 'Failed to copy';--}}

            {{--        toastr['success'](msg, '{{__('locale.labels.success')}}!!', {--}}
            {{--            closeButton: true,--}}
            {{--            positionClass: 'toast-top-right',--}}
            {{--            progressBar: true,--}}
            {{--            newestOnTop: true,--}}
            {{--            rtl: isRtl--}}
            {{--        });--}}
            {{--    } catch (err) {--}}
            {{--        toastr['info']('Oops, unable to copy ' + err, '{{ __('locale.labels.warning') }}!', {--}}
            {{--            closeButton: true,--}}
            {{--            positionClass: 'toast-top-right',--}}
            {{--            progressBar: true,--}}
            {{--            newestOnTop: true,--}}
            {{--            rtl: isRtl--}}
            {{--        });--}}
            {{--    }--}}
            {{--    document.body.removeChild(textArea);--}}
            {{--}--}}


            $('#usms_pricing-tab').on('click', function () {
                $($.fn.dataTable.tables(true)).DataTable()
                    .columns.adjust()
                    .responsive.recalc();
            });

            let firstInvalid = $('form').find('.is-invalid').eq(0);
            const selectAll = document.querySelector('#selectAll'),
                checkboxList = document.querySelectorAll('[type="checkbox"]');
            selectAll.addEventListener('change', t => {
                checkboxList.forEach(e => {
                    e.checked = t.target.checked;
                });
            });

            if (firstInvalid.length) {
                $('body, html').stop(true, true).animate({
                    'scrollTop': firstInvalid.offset().top - 200 + 'px'
                }, 200);
            }


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


            //show response message
            function showResponseMessage(data) {
                if (data.status === 'success') {
                    toastr['success'](data.message, '{{__('locale.labels.success')}}!!', {
                        closeButton: true,
                        positionClass: 'toast-top-right',
                        progressBar: true,
                        newestOnTop: true,
                        rtl: isRtl
                    });
                } else if (data.status === 'error') {
                    toastr['error'](data.message, '{{ __('locale.labels.opps') }}!', {
                        closeButton: true,
                        positionClass: 'toast-top-right',
                        progressBar: true,
                        newestOnTop: true,
                        rtl: isRtl
                    });
                } else {
                    toastr['warning']("{{__('locale.exceptions.something_went_wrong')}}", '{{ __('locale.labels.warning') }}!', {
                        closeButton: true,
                        positionClass: 'toast-top-right',
                        progressBar: true,
                        newestOnTop: true,
                        rtl: isRtl
                    });
                }
            }


            // On Remove Avatar
            $('#remove-avatar').on("click", function (e) {

                e.stopPropagation();
                let id = $(this).data('id');
                Swal.fire({
                    title: "{{ __('locale.labels.are_you_sure') }}",
                    text: "{{ __('locale.labels.able_to_revert') }}",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: "{{ __('locale.labels.delete_it') }}",
                    customClass: {
                        confirmButton: 'btn btn-primary',
                        cancelButton: 'btn btn-outline-danger ms-1'
                    },
                    buttonsStyling: false,

                }).then(function (result) {
                    if (result.value) {
                        $.ajax({
                            url: "{{ url(config('app.admin_path').'/customers')}}" + '/' + id + '/remove-avatar',
                            type: "POST",
                            data: {
                                _method: 'POST',
                                _token: "{{csrf_token()}}"
                            },
                            success: function (data) {
                                showResponseMessage(data);
                                setTimeout(function () {
                                    location.reload();
                                }, 5000);
                            },
                            error: function (reject) {
                                if (reject.status === 422) {
                                    let errors = reject.responseJSON.errors;
                                    $.each(errors, function (key, value) {
                                        toastr['warning'](value[0], "{{__('locale.labels.attention')}}", {
                                            closeButton: true,
                                            positionClass: 'toast-top-right',
                                            progressBar: true,
                                            newestOnTop: true,
                                            rtl: isRtl
                                        });
                                    });
                                } else {
                                    toastr['warning'](reject.responseJSON.message, "{{__('locale.labels.attention')}}", {
                                        positionClass: 'toast-top-right',
                                        containerId: 'toast-top-right',
                                        progressBar: true,
                                        closeButton: true,
                                        newestOnTop: true
                                    });
                                }
                            }
                        })
                    }
                })
            });

            /*Version 3.7*/
            // init list view datatable
            let dataListView = $('.datatables-basic').DataTable({

                "processing": true,
                "serverSide": true,
                "ajax": {
                    "url": "{{ route('admin.customers.pricing', $customer->uid) }}",
                    "dataType": "json",
                    "type": "POST",
                    "data": {_token: "{{csrf_token()}}"}
                },
                "columns": [
                    {"data": 'responsive_id', orderable: false, searchable: false},
                    {"data": "uid"},
                    {"data": "name", orderable: false},
                    {"data": "iso_code", orderable: false},
                    {"data": "country_code", orderable: false},
                    {"data": "status", orderable: false, searchable: false},
                    {"data": "action", orderable: false, searchable: false}
                ],

                searchDelay: 1500,
                columnDefs: [
                    {
                        // For Responsive
                        className: 'control',
                        orderable: false,
                        responsivePriority: 2,
                        targets: 0
                    },
                    {
                        targets: 1,
                        visible: false
                    },
                    {
                        // Actions
                        targets: -1,
                        title: '{{ __('locale.labels.actions') }}',
                        orderable: false,
                        render: function (data, type, full) {
                            return (

                                '<a href="' + full['edit'] + '" class="text-primary pe-1">' +
                                feather.icons['edit'].toSvg({class: 'font-medium-4'}) +
                                '</a>' +
                                '<span class="action-price-delete text-danger cursor-pointer" data-id=' + full['uid'] + '>' +
                                feather.icons['trash'].toSvg({class: 'font-medium-4'}) +
                                '</span>'

                            );
                        }
                    }
                ],
                dom: '<"d-flex justify-content-between align-items-center mx-0 row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>t<"d-flex justify-content-between mx-0 row"<"col-sm-12 col-md-6"i><"col-sm-12 col-md-6"p>>',

                language: {
                    paginate: {
                        // remove previous & next text from pagination
                        previous: '&nbsp;',
                        next: '&nbsp;'
                    },
                    sLengthMenu: "_MENU_",
                    sZeroRecords: "{{ __('locale.datatables.no_results') }}",
                    sSearch: "{{ __('locale.datatables.search') }}",
                    sProcessing: "{{ __('locale.datatables.processing') }}",
                    sInfo: "{{ __('locale.datatables.showing_entries', ['start' => '_START_', 'end' => '_END_', 'total' => '_TOTAL_']) }}"
                },
                responsive: {
                    details: {
                        display: $.fn.dataTable.Responsive.display.modal({
                            header: function (row) {
                                let data = row.data();
                                return 'Details of ' + data['name'];
                            }
                        }),
                        type: 'column',
                        renderer: function (api, rowIdx, columns) {
                            let data = $.map(columns, function (col) {
                                return col.title !== '' // ? Do not show row in modal popup if title is blank (for check box)
                                    ? '<tr data-dt-row="' +
                                    col.rowIdx +
                                    '" data-dt-column="' +
                                    col.columnIndex +
                                    '">' +
                                    '<td>' +
                                    col.title +
                                    ':' +
                                    '</td> ' +
                                    '<td>' +
                                    col.data +
                                    '</td>' +
                                    '</tr>'
                                    : '';
                            }).join('');

                            return data ? $('<table class="table pricing_table"/>').append('<tbody>' + data + '</tbody>') : false;
                        }
                    }
                },
                aLengthMenu: [[10, 20, 50, 100], [10, 20, 50, 100]],
                select: {
                    style: "multi"
                },
                order: [[1, "asc"]],
                displayLength: 10,
            });

            let Table = $(".pricing_table");

            //change status
            Table.delegate(".get_coverage_status", "click", function () {

                let coverage = $(this).data('id');
                let url = "{{ url(config('app.admin_path').'/customers/'.$customer->uid.'/coverage')}}" + '/' + coverage + '/active';

                $.ajax({
                    url: url,
                    type: "POST",
                    data: {
                        _token: "{{csrf_token()}}"
                    },
                    success: function (data) {
                        showResponseMessage(data);
                    }
                });
            });


            // On Delete
            Table.delegate(".action-price-delete", "click", function (e) {
                e.stopPropagation();
                let id = $(this).data('id');

                let url = "{{ url(config('app.admin_path').'/customers/'.$customer->uid.'/coverage')}}" + '/' + id + '/delete';
                Swal.fire({
                    title: "{{ __('locale.labels.are_you_sure') }}",
                    text: "{{ __('locale.labels.able_to_revert') }}",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: "{{ __('locale.labels.delete_it') }}",
                    customClass: {
                        confirmButton: 'btn btn-primary',
                        cancelButton: 'btn btn-outline-danger ms-1'
                    },
                    buttonsStyling: false,
                }).then(function (result) {
                    if (result.value) {
                        $.ajax({
                            url: url,
                            type: "POST",
                            data: {
                                _token: "{{csrf_token()}}"
                            },
                            success: function (data) {
                                dataListView.draw(false);
                                showResponseMessage(data);
                            },
                            error: function (reject) {
                                if (reject.status === 422) {
                                    let errors = reject.responseJSON.errors;
                                    $.each(errors, function (key, value) {
                                        toastr['warning'](value[0], "{{__('locale.labels.attention')}}", {
                                            closeButton: true,
                                            positionClass: 'toast-top-right',
                                            progressBar: true,
                                            newestOnTop: true,
                                            rtl: isRtl
                                        });
                                    });
                                } else {
                                    toastr['warning'](reject.responseJSON.message, "{{__('locale.labels.attention')}}", {
                                        positionClass: 'toast-top-right',
                                        containerId: 'toast-top-right',
                                        progressBar: true,
                                        closeButton: true,
                                        newestOnTop: true
                                    });
                                }
                            }
                        })
                    }
                })
            });


            /*Version 3.8*/

            $('.sending-server').delegate(".action-sending-server-delete", "click", function (e) {
                e.stopPropagation();
                let id = $(this).data('id');
                Swal.fire({
                    title: "{{ __('locale.labels.are_you_sure') }}",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: "{{ __('locale.labels.delete_it') }}",
                    customClass: {
                        confirmButton: 'btn btn-primary',
                        cancelButton: 'btn btn-outline-danger ms-1'
                    },
                    buttonsStyling: false,
                }).then(function (result) {
                    if (result.value) {
                        $.ajax({
                            url: "{{ route('admin.customers.sending-server.delete', $customer->uid)}}",
                            type: "POST",
                            data: {
                                _method: 'POST',
                                server_id: id,
                                _token: "{{csrf_token()}}"
                            },
                            success: function (data) {
                                showResponseMessage(data);
                                setTimeout(function () {
                                    location.reload();
                                }, 3000);
                            },
                            error: function (reject) {
                                if (reject.status === 422) {
                                    let errors = reject.responseJSON.errors;
                                    $.each(errors, function (key, value) {
                                        toastr['warning'](value[0], "{{__('locale.labels.attention')}}", {
                                            closeButton: true,
                                            positionClass: 'toast-top-right',
                                            progressBar: true,
                                            newestOnTop: true,
                                            rtl: isRtl
                                        });
                                    });
                                } else {
                                    toastr['warning'](reject.responseJSON.message, "{{__('locale.labels.attention')}}", {
                                        positionClass: 'toast-top-right',
                                        containerId: 'toast-top-right',
                                        progressBar: true,
                                        closeButton: true,
                                        newestOnTop: true
                                    });
                                }
                            }
                        })
                    }
                })
            });


            // copy text on click
            $(".copy-to-clipboard").on('click', function (e) {
                e.preventDefault()
                copyToClipboard($(this).data('text'));
            })

            function copyToClipboard(text) {

                try {

                    let $tempInput = $("<input>");
                    $("body").append($tempInput);
                    $tempInput.val(text).select();
                    let successful = document.execCommand("copy");
                    $tempInput.remove();
                    let msg = successful ? 'Copied' : 'Failed to copy';

                    toastr['success'](msg, '{{__('locale.labels.success')}}!!', {
                        closeButton: true,
                        positionClass: 'toast-top-right',
                        progressBar: true,
                        newestOnTop: true,
                        rtl: isRtl
                    });
                } catch (err) {
                    toastr['info']('Oops, unable to copy ' + err, '{{ __('locale.labels.warning') }}!', {
                        closeButton: true,
                        positionClass: 'toast-top-right',
                        progressBar: true,
                        newestOnTop: true,
                        rtl: isRtl
                    });
                }
            }


        });
    </script>

@endsection
