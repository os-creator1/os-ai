@extends('layouts/contentLayoutMaster')

@section('title', __('locale.plans.pricing'))

@section('vendor-style')
    <!-- vendor css files -->
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/tables/datatable/dataTables.bootstrap5.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/tables/datatable/responsive.bootstrap5.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/tables/datatable/buttons.bootstrap5.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/extensions/sweetalert2.min.css')) }}">
@endsection


@section('content')

    <section class="users-edit">

        <div class="row">
            <div class="col-12">

                <ul class="nav nav-pills mb-2" role="tablist">
                    <!-- Account -->
                    <li class="nav-item">
                        <a class="nav-link @if (old('tab') == 'coverage' || old('tab') == null) active @endif" id="coverage-tab" data-bs-toggle="tab" href="#coverage" aria-controls="coverage" role="tab" aria-selected="true">
                            <i data-feather="globe" class="font-medium-3 me-50"></i>
                            <span class="fw-bold">{{__('locale.labels.coverage')}}</span>
                        </a>
                    </li>

                    <!-- information -->
                    <li class="nav-item">
                        <a class="nav-link {{ old('tab') == 'pricing' ? 'active':null }}" id="pricing-tab" data-bs-toggle="tab" href="#pricing" aria-controls="pricing" role="tab" aria-selected="false">
                            <i data-feather="tag" class="font-medium-3 me-50"></i>
                            <span class="fw-bold">{{ __('locale.plans.pricing') }}</span>
                        </a>
                    </li>


                </ul>


                <div class="tab-content">

                    <div class="tab-pane  @if (old('tab') == 'coverage' || old('tab') == null) active @endif" id="coverage" aria-labelledby="coverage-tab" role="tabpanel">

                        <div class="card">
                            <div class="card-body py-2 my-25">
                                <table class="table pricing_table datatables-basic">
                                    <thead>
                                    <tr>
                                        <th></th>
                                        <th>{{ __('locale.labels.id') }}</th>
                                        <th>{{__('locale.labels.name')}} </th>
                                        <th>{{__('locale.labels.iso_code')}}</th>
                                        <th>{{__('locale.labels.country_code')}}</th>
                                        <th>{{__('locale.labels.actions')}}</th>
                                    </tr>
                                    </thead>
                                </table>
                            </div>
                        </div>

                    </div>

                    <div class="tab-pane {{ old('tab') == 'pricing' ? 'active':null }}" id="pricing" aria-labelledby="pricing-tab" role="tabpanel">

                        <div class="card">
                            <div class="card-body py-2 my-25">
                                <div class="table-responsive">
                                    <table class="table table-hover table-bordered mb-0">
                                        <thead class="thead-primary">
                                        <tr class="text-center">
                                            <th colspan="2">{{ __('locale.plans.recharge_volume') }}</th>
                                            <th>{{ __('locale.labels.per_unit_price') }}</th>
                                            <th class="text-nowrap">{{ __('locale.plans.number_of_units') }}</th>
                                        </tr>
                                        </thead>
                                        <tbody class="text-center">
                                        @if($plan->getCreditPrices()->count() > 0)
                                            @foreach ($plan->getCreditPrices()->orderBy('unit_from')->get() as $key => $item)
                                                <tr>

                                                    <td>
                                                        <span>{{ str_replace('{PRICE}', '', $plan->currency->format) }} {{ $item->unit_from }}</span>
                                                    </td>

                                                    <td>
                                                        <span>{{ str_replace('{PRICE}', '', $plan->currency->format) }} {{ $item->unit_to }}</span>
                                                    </td>

                                                    <td>
                                                        <span>{{ str_replace('{PRICE}', '', $plan->currency->format) }} {{ $item->per_credit_cost }}</span>
                                                    </td>
                                                    <td>
                                                        <span class="number_of_units"> {{ $item->calculateUnits() }}</span>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        @endif
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
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
    <script src="{{ asset(mix('vendors/js/extensions/polyfill.min.js')) }}"></script>
@endsection


@section('page-script')

    <script>

        $(document).ready(function () {
            "use strict"


            $('.datatables-basic').DataTable({

                "processing": true,
                "serverSide": true,
                "ajax": {
                    "url": "{{ route('user.account.pricing') }}",
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
                                '<span class="action-view text-primary cursor-pointer" data-value=' + full['by_plan'] + ' data-id=' + full['uid'] + '>' +
                                feather.icons['tag'].toSvg({class: 'font-medium-4'}) +
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

        });

        $('table').delegate(".action-view", "click", function (e) {
            e.stopPropagation();
            let id = $(this).data('id'),
                value = $(this).data('value'),
                dataType;

            if (value === 'no') {
                dataType = 'customer';
            } else {
                dataType = 'plan';
            }

            $.ajax({
                url: "{{ route('user.account.pricing-view') }}",
                type: "POST",
                data: {
                    _token: "{{csrf_token()}}",
                    dataType: dataType,
                    uid: id
                },
                success: function (data) {
                    let ViewData = $.parseJSON(data.data);
                    let html = `
            <div class="table-responsive">
                <table class="table">
                    <thead class="thead-primary">
                        <tr class="text-center">
                            <th colspan="2">All Values are calculate on the basis of sms units</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td width="70%">{{ __('locale.labels.plain_sms') }}</td>
                            <td>${ViewData.plain_sms}</td>
                        </tr>
                        <tr>
                            <td width="70%">{{ __('locale.labels.voice_sms') }}</td>
                            <td>${ViewData.voice_sms}</td>
                        </tr>
                        <tr>
                            <td width="70%">{{ __('locale.labels.mms_sms') }}</td>
                            <td>${ViewData.mms_sms}</td>
                        </tr>
                        <tr>
                            <td width="70%">{{ __('locale.labels.whatsapp_sms') }}</td>
                            <td>${ViewData.whatsapp_sms}</td>
                        </tr>
                        <tr>
                            <td width="70%">{{ __('locale.labels.viber_sms') }}</td>
                            <td>${ViewData.viber_sms}</td>
                        </tr>
                        <tr>
                            <td width="70%">{{ __('locale.labels.otp_sms') }}</td>
                            <td>${ViewData.otp_sms}</td>
                        </tr>
                        <tr>
                            <td width="70%">{{ __('locale.labels.receive') }} {{ __('locale.labels.plain_sms') }}</td>
                            <td>${ViewData.receive_plain_sms}</td>
                        </tr>

                        <tr>
                            <td width="70%">{{ __('locale.labels.receive') }} {{ __('locale.labels.voice_sms') }}</td>
                            <td>${ViewData.receive_voice_sms}</td>
                        </tr>
                        <tr>
                            <td width="70%">{{ __('locale.labels.receive') }} {{ __('locale.labels.mms_sms') }}</td>
                            <td>${ViewData.receive_mms_sms}</td>
                        </tr>
                        <tr>
                            <td width="70%">{{ __('locale.labels.receive') }} {{ __('locale.labels.whatsapp_sms') }}</td>
                            <td>${ViewData.receive_whatsapp_sms}</td>
                        </tr>
                        <tr>
                            <td width="70%">{{ __('locale.labels.receive') }} {{ __('locale.labels.viber_sms') }}</td>
                            <td>${ViewData.receive_viber_sms}</td>
                        </tr>
                        <tr>
                            <td width="70%">{{ __('locale.labels.receive') }} {{ __('locale.labels.otp_sms') }}</td>
                            <td>${ViewData.receive_otp_sms}</td>
                        </tr>
                    </tbody>
                </table>
            </div>`;

                    Swal.fire({
                        html: html
                    });
                },
                error: function (reject) {
                    handleAjaxError(reject);
                }
            });

            function handleAjaxError(reject) {
                let errorMessage = reject.responseJSON.message || "{{__('locale.labels.attention')}}";
                let toastrOptions = {
                    closeButton: true,
                    positionClass: 'toast-top-right',
                    progressBar: true,
                    newestOnTop: true,
                    rtl: isRtl
                };

                if (reject.status === 422) {
                    let errors = reject.responseJSON.errors;
                    $.each(errors, function (key, value) {
                        toastr['warning'](value[0], "{{__('locale.labels.attention')}}", toastrOptions);
                    });
                } else {
                    toastr['warning'](errorMessage, "{{__('locale.labels.attention')}}", toastrOptions);
                }
            }


        });

    </script>

@endsection
