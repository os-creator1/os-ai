@extends('layouts/contentLayoutMaster')

@section('title', __('locale.phone_numbers.buy_number'))

@section('vendor-style')
    {{-- vendor css files --}}
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/tables/datatable/dataTables.bootstrap5.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/tables/datatable/responsive.bootstrap5.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/tables/datatable/buttons.bootstrap5.min.css')) }}">

@endsection

@section('content')

    <!-- Basic table -->
    <section id="datatables-basic">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <table class="table datatables-basic">
                        <thead>
                        <tr>
                            <th>{{__('locale.labels.number')}}</th>
                            <th>{{__('locale.plans.price')}}</th>
                            <th>{{__('locale.labels.capabilities')}}</th>
                            <th>{{__('locale.labels.actions')}}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($numbers as $number)
                            <tr>
                                <td>{{ $number->phoneNumber }}</td>
                                <td><div>
                                        <p class='text-bold-600'>{{ \App\Library\Tool::format_price(5,auth()->user()->customer->subscription->plan->currency->format) }}</p>
                                        <p class='text-muted'>{{ \App\Library\Tool::formatHumanTime(\Carbon\Carbon::now()->addMonth()) }}</p>
                                    </div>
                                    </td>
                                <td>
                                    @if ($number->capabilities['SMS'] == true)
                                        <span class="badge bg-primary text-uppercase me-1">
                                        <i data-feather="message-square" class="me-25"></i>
                                        <span>{{ __('locale.labels.sms') }}</span>
                                    </span>
                                    @endif

                                    @if ($number->capabilities['voice'] == true)
                                        <span class="badge bg-success text-uppercase me-1">
                                        <i data-feather="phone-call" class="me-25"></i>
                                        <span>{{ __('locale.labels.voice') }}</span>
                                    </span>
                                    @endif

                                    @if ($number->capabilities['MMS'] == true)
                                        <span class="badge bg-success text-uppercase me-1">
                                        <i data-feather="image" class="me-25"></i>
                                        <span>{{ __('locale.labels.mms') }}</span>
                                    </span>
                                    @endif
                                </td>
                                <td>
                                    <a href="{{ route('customer.numbers.purchase', ['number' => $number->phoneNumber, 'sending_server' => $sending_server]) }}"><i class="font-medium-4 text-primary" data-feather="shopping-cart"></i> </a>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
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
    <script src="{{ asset(mix('vendors/js/tables/datatable/datatables.buttons.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/tables/datatable/buttons.html5.min.js')) }}"></script>

@endsection
@section('page-script')
    {{-- Page js files --}}
    <script>
        $(document).ready(function () {
            "use strict"

            $('.datatables-basic').DataTable({
                "processing": true,
                "columns": [
                    {"data": "number"},
                    {"data": "price"},
                    {"data": "capabilities"},
                    {"data": "action", orderable: false, searchable: false}
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

                            return data ? $('<table class="table"/>').append('<tbody>' + data + '</tbody>') : false;
                        }
                    }
                },
                aLengthMenu: [[10, 20, 50, 100], [10, 20, 50, 100]],

                order: [[0, "desc"]],
                displayLength: 20,
            });

        });
    </script>

@endsection
