@extends('layouts/contentLayoutMaster')

@section('title', __('locale.menu.Tax Settings'))

@section('vendor-style')
    <!-- vendor css files -->
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/extensions/sweetalert2.min.css')) }}">
@endsection

@section('content')

    <!-- Basic Vertical form layout section start -->
    <section id="basic-vertical-layouts">
        <div class="row match-height">
            <div class="col-md-8 col-12">
                <div class="card">
                    <div class="card-header"></div>
                    <div class="card-content">
                        <div class="card-body">

                            <form id="TaxSettingsForm" method="POST">
                                @csrf

                                <div class="d-flex">
                                    <div class="form-check form-check-primary">

                                        <input type="hidden" name="tax[enabled]" value="no"/>
                                        <input type="checkbox" id="taxEnabled" class="form-check-input"
                                               name="tax[enabled]"
                                               {{ \App\Models\AppConfig::getTaxSettings()['enabled'] == 'yes' ? 'checked' : '' }}
                                               value="yes"/>

                                        <label class="form-check-label text-primary bold fw-bolder"
                                               for="taxEnabled">{{ __('locale.tax.enable_tax') }}</label>
                                        <p class="text-uppercase">{{ __('locale.tax.enable_tax_description') }}</p>
                                    </div>
                                </div>

                                <div class="tax-settings" style="display: none;">
                                    <div class="mt-2 d-flex align-items-center">
                                        <div class="me-1">
                                            <span class="font-medium-1">{{ __('locale.tax.default_tax_rate') }}</span>
                                        </div>

                                        <div class="input-group input-group-merge" style="width:100px !important">
                                            <input type="number" id="defaultTaxRate" class="form-control text-end"
                                                   maxlength="6"
                                                   name="tax[default_rate]"
                                                   value="{{ \App\Models\AppConfig::getTaxSettings()['default_rate'] }}"/>
                                            <span class="input-group-text">%</span>
                                        </div>
                                        <div class="mx-1">
                                            <button type="button"
                                                    class="btn btn-primary set-default-rate">{{ __('locale.buttons.set') }}</button>
                                        </div>
                                    </div>

                                    <hr>
                                    <p class="mt-2">{{ __('locale.tax.country_specific_tax_rates') }}</p>
                                    <div class="mt-4 d-flex align-items-center">
                                        <div class="me-2 fw-bolder">
                                            <span>{{ __('locale.tax.tax_by_country') }}</span>
                                        </div>

                                        <div class="form-group me-2 mb-0" style="width:200px">
                                            <select id="countryId" class="select2 form-select" name="country_id">
                                                @foreach(\App\Models\Country::getSelectOptions() as $country)
                                                    <option value="{{ $country['value'] }}">{{ $country['text'] }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <button type="button"
                                                    class="btn btn-success add-country-tax">{{ __('locale.buttons.add') }}</button>
                                        </div>
                                    </div>

                                    <div class="country-taxes">
                                        <!-- Country-specific tax rates will load here dynamically -->
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- // Basic Vertical form layout section end -->

@endsection


@section('vendor-script')
    <!-- vendor files -->
    <script src="{{ asset(mix('vendors/js/forms/select/select2.full.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/extensions/sweetalert2.all.min.js')) }}"></script>
@endsection



@section('page-script')

    <script>
        $(document).ready(function () {

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

            let firstInvalid = $('form').find('.is-invalid').eq(0),
                taxEnabled = $('#taxEnabled'),
                countryTaxes = $('.country-taxes');

            if (firstInvalid.length) {
                $('body, html').stop(true, true).animate({
                    'scrollTop': firstInvalid.offset().top - 200 + 'px'
                }, 200);
            }


            // Check initial state of checkbox and show/hide tax settings
            toggleTaxSettings();


            // Show or hide tax settings on checkbox change
            taxEnabled.on('change', function () {
                toggleTaxSettings();
            });

            // Function to show/hide the tax-settings div based on checkbox status
            function toggleTaxSettings() {
                if (taxEnabled.is(':checked')) {
                    $('.tax-settings').show();
                } else {
                    $('.tax-settings').hide();
                }
            }


            // Enable/disable tax
            taxEnabled.change(function () {
                setDefaultRate();
            });


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


            // AJAX function to update tax settings
            function setDefaultRate() {
                $.ajax({
                    url: "{{ url(config('app.admin_path').'/tax/settings')}}",
                    type: 'POST',
                    data: $('#TaxSettingsForm').serialize(),
                    success: function (data) {
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
                                closeButton: true,
                                positionClass: 'toast-top-right',
                                progressBar: true,
                                newestOnTop: true,
                                rtl: isRtl
                            });
                        }
                    }
                });
            }


            $('.set-default-rate').on('click', function () {
                setDefaultRate();
            });

            // Add country-specific tax rate
            $('.add-country-tax').on('click', function (e) {

                e.stopPropagation();

                let countryId = $('#countryId').val();
                let countryText = $('#countryId option:selected').text();

                if (countryId && countryText) {
                    Swal.fire({
                        title: 'Select tax rate of ' + countryText,
                        input: 'text',
                        inputAttributes: {
                            autocapitalize: 'off',
                            placeholder: 'Tax rate (eg. 10 for 10%)',
                            maxlength: 5,
                        },
                        showCancelButton: true,
                        cancelButtonText: "{{ __('locale.buttons.cancel') }}",
                        cancelButtonAriaLabel: "{{ __('locale.buttons.cancel') }}",
                        confirmButtonText: feather.icons['save'].toSvg({class: 'font-medium-1 me-50'}) + "{{ __('locale.buttons.save') }}",
                        customClass: {
                            confirmButton: 'btn btn-primary',
                            cancelButton: 'btn btn-outline-danger ms-1'
                        },
                        buttonsStyling: false,
                    }).then(function (result) {
                        if (result.value) {
                            $.ajax({
                                url: "{{ url(config('app.admin_path').'/tax/add')}}",
                                type: "POST",
                                data: {
                                    _method: 'POST',
                                    country_id: countryId,
                                    tax: result.value,
                                    _token: "{{csrf_token()}}"
                                },
                                success: function (data) {
                                    showResponseMessage(data);
                                    getCountries();
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
                                            closeButton: true,
                                            positionClass: 'toast-top-right',
                                            progressBar: true,
                                            newestOnTop: true,
                                            rtl: isRtl
                                        });
                                    }
                                }
                            })
                        }
                    })

                }
            });

            function getCountries() {
                $.ajax({
                    url: "{{ url(config('app.admin_path').'/tax/countries')}}",
                    type: 'GET',
                    success: function (data) {
                        countryTaxes.html(data);
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
                                closeButton: true,
                                positionClass: 'toast-top-right',
                                progressBar: true,
                                newestOnTop: true,
                                rtl: isRtl
                            });
                        }
                    }
                });
            }

            getCountries();


            $('.edit-country-tax').on('click', function (e) {
                e.preventDefault();
                let country_id = $(this).attr('data-value');

                console.log(country_id);
            });

            countryTaxes.delegate(".remove-country-tax", "click", function (e) {
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
                            url: "{{ url(config('app.admin_path').'/tax/remove/')}}" + '/' + id,
                            type: "POST",
                            data: {
                                _method: 'DELETE',
                                _token: "{{csrf_token()}}"
                            },
                            success: function (data) {
                                showResponseMessage(data);
                                getCountries();
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


            countryTaxes.delegate(".edit-country-tax", "click", function (e) {
                e.stopPropagation();
                let country_id = $(this).data('value')
                let taxRate = $(this).data('tax');


                Swal.fire({
                    title: "{{ __('locale.tax.update_your_tax_rate') }}",
                    input: 'text',
                    inputValue: taxRate,
                    inputAttributes: {
                        autocapitalize: 'off'
                    },
                    showCancelButton: true,
                    cancelButtonText: "{{ __('locale.buttons.cancel') }}",
                    cancelButtonAriaLabel: "{{ __('locale.buttons.cancel') }}",
                    confirmButtonText: feather.icons['save'].toSvg({class: 'font-medium-1 me-50'}) + "{{ __('locale.buttons.save') }}",
                    customClass: {
                        confirmButton: 'btn btn-primary',
                        cancelButton: 'btn btn-outline-danger ms-1'
                    },
                    buttonsStyling: false,
                }).then(function (result) {
                    if (result.value) {
                        $.ajax({
                            url: "{{ url(config('app.admin_path').'/tax/add')}}",
                            type: "POST",
                            data: {
                                _method: 'POST',
                                country_id: country_id,
                                tax: result.value,
                                _token: "{{csrf_token()}}"
                            },
                            success: function (data) {
                                showResponseMessage(data);
                                getCountries();
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
                                        closeButton: true,
                                        positionClass: 'toast-top-right',
                                        progressBar: true,
                                        newestOnTop: true,
                                        rtl: isRtl
                                    });
                                }
                            }
                        })
                    }
                })

            });

        });
    </script>
@endsection
