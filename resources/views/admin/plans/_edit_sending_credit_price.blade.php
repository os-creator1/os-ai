@extends('layouts/contentLayoutMaster')

@section('title', __('locale.buttons.update_pricing'))

@section('vendor-style')
    <!-- vendor css files -->
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/extensions/sweetalert2.min.css')) }}">
@endsection

@section('page-style')

    <style>
        .customized_select2 .select2-selection--multiple {
            border-left: 0;
            border-radius: 0 4px 4px 0;
            min-height: calc(1.5em + 0.75rem + 7px) !important;

        }

        .input-group > :not(:first-child):not(.dropdown-menu):not(.valid-tooltip):not(.valid-feedback):not(.invalid-tooltip):not(.invalid-feedback) {
            width: calc(100% - 60px);
        }
    </style>

@endsection

@section('content')
    <!-- Basic Vertical form layout section start -->
    <section id="basic-vertical-layouts">
        <div class="row match-height">

            <div class="col-12">
                <form action="{{ route('admin.plans.settings.update-credit-price', $plan->uid) }}"
                      method="post">
                    @csrf

                    <input type="hidden" value="{{$country->id}}" name="country_id">

                    <div class="row d-flex align-items-end">

                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">


                                    <div class="table-responsive">
                                        <table class="table field-list">
                                            <thead>
                                            <tr>
                                                <th colspan="3"
                                                    class="text-center">{{ __('locale.plans.recharge_volume') }}</th>
                                                <th>{{ __('locale.labels.per_unit_price') }}</th>
                                                <th class="text-center text-nowrap">{{ __('locale.plans.number_of_units') }}</th>
                                                <th>{{ __('locale.labels.actions') }}</th>
                                            </tr>
                                            <tbody>
                                            @if($plan->getCreditPrices($country->id)->count() > 0)
                                                @foreach ($plan->getCreditPrices($country->id)->first()->options as $key => $item)

                                                    <tr>
                                                        <td>
                                                            <input type="hidden" name="fields[{{ $item['uid'] }}][uid]"
                                                                   value="{{ $item['uid'] }}"/>
                                                        </td>
                                                        <td>
                                                            <div class="input-group input-group-merge">
                                                                <span class="input-group-text">{{ str_replace('{PRICE}', '', $plan->currency->format) }}</span>
                                                                <input type="number" class="form-control unit_from"
                                                                       name="fields[{{$item['uid']}}][unit_from]"
                                                                       value="{{ $item['unit_from'] }}"
                                                                       aria-describedby="unit_from">
                                                            </div>
                                                        </td>

                                                        <td>
                                                            <div class="input-group input-group-merge">
                                                                <span class="input-group-text">{{ str_replace('{PRICE}', '', $plan->currency->format) }}</span>
                                                                <input type="number" class="form-control unit_to"
                                                                       name="fields[{{$item['uid']}}][unit_to]"
                                                                       value="{{ $item['unit_to'] }}"
                                                                       aria-describedby="unit_to">
                                                            </div>
                                                        </td>

                                                        <td>
                                                            <div class="input-group input-group-merge">
                                                                <span class="input-group-text">{{ str_replace('{PRICE}', '', $plan->currency->format) }}</span>
                                                                <input type="text" class="form-control per_credit_cost"
                                                                       name="fields[{{$item['uid']}}][per_credit_cost]"
                                                                       value="{{ $item['per_credit_cost'] }}"
                                                                       aria-describedby="per_credit_cost">
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <span class="number_of_units"> </span>
                                                        </td>
                                                        <td>
                                                           <span class="remove-not-saved-field text-danger cursor-pointer"
                                                                 data-bs-toggle="tooltip" data-bs-placement="top"
                                                                 title="{{ __('locale.buttons.delete') }}"
                                                                 data-field-id='{{ $item['uid'] }}'><i
                                                                       data-feather="trash-2"
                                                                       class="feather-20"></i></span>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            @endif
                                            </tbody>
                                        </table>
                                    </div>


                                    <hr/>
                                    <div class="row">
                                        <div class="col-12">


                                            <button class="btn btn-icon btn-primary me-1" type="submit">
                                                <i data-feather="save" class="me-25"></i>
                                                <span>{{ __('locale.buttons.save_changes') }}</span>
                                            </button>

                                            <span sample-url="{{ route('admin.plans.settings.add-credit-price-field', $plan->uid) }}"
                                                  class="btn btn-relief-success me-1 add-custom-field-button"
                                                  type_name="text">
                                    <i data-feather="plus" class="me-25"></i>
                                    {{ __('locale.buttons.add_new') }}
                                </span>
                                        </div>
                                    </div>

                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </section>
@endsection



@section('vendor-script')
    <!-- vendor files -->
    <script src="{{ asset(mix('vendors/js/forms/select/select2.full.min.js')) }}"></script>

    <script src="{{ asset(mix('vendors/js/extensions/sweetalert2.all.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/extensions/polyfill.min.js')) }}"></script>
@endsection

@section('page-script')

    <script>
        $(document).ready(function () {
            let firstInvalid = $('form').find('.is-invalid').eq(0);

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


            // Function to calculate and update the number_of_units field
            function updateNumberOfUnits(element) {
                // Get the closest tr element
                let row = element.closest('tr');

                // Get values from the current row
                let unitFrom = parseFloat(row.find('.unit_from').val()) || 0;
                let unitTo = parseFloat(row.find('.unit_to').val()) || 0;
                let perCreditCost = parseFloat(row.find('.per_credit_cost').val()) || 0;

                let numberOfFromUnits = unitFrom / perCreditCost;
                let numberOfToUnits = unitTo / perCreditCost;

                // Update the number_of_units field in the current row
                if (unitFrom > 0 && unitTo > 0 && perCreditCost > 0) {
                    row.find('.number_of_units').text(parseInt(numberOfFromUnits).toLocaleString() + ' - ' + parseInt(numberOfToUnits).toLocaleString() + ' units');
                }
            }


            // Attach the update function to the change event of relevant fields
            $('.unit_from, .unit_to, .per_credit_cost').on('input', function () {
                updateNumberOfUnits($(this));
            });

            // Initialize the calculation on page load
            updateAllNumberOfUnits();

            function updateAllNumberOfUnits() {
                $('.unit_from, .unit_to, .per_credit_cost').each(function () {
                    updateNumberOfUnits($(this));
                });
            }

            function attachEventListeners() {
                $('.unit_from, .unit_to, .per_credit_cost').off('input').on('input', function () {
                    updateNumberOfUnits($(this));
                });
            }


            $(document).on("click", ".add-custom-field-button", function (e) {
                e.preventDefault();
                let sample_url = $(this).attr("sample-url");

                // ajax update custom sort
                $.ajax({
                    method: "GET",
                    url: sample_url,
                })
                    .done(function (msg) {
                        let index = $(".field-list tr").length;

                        msg = msg.replace(/__index__/g, index);

                        $(".field-list").append($("<div>").html(msg).find("table tbody").html());

                        feather.replace();
                        attachEventListeners();

                    });
            });

            $(document).on("click", ".remove-not-saved-field", function (e) {

                e.preventDefault();

                $("tr[parent=\"" + $(this).parents("tr").attr("rel") + "\"]").remove();
                $(this).parents("tr").remove();
            });

        });

    </script>
@endsection
