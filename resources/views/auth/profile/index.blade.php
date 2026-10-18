@extends('layouts.contentLayoutMaster')

@section('title', $user->displayName())

@section('page-style')
    <link rel="stylesheet" href="{{ asset('css/settings-redesign.css') }}?v={{ is_file(public_path('css/settings-redesign.css')) ? filemtime(public_path('css/settings-redesign.css')) : 1 }}">
@endsection

@section('vendor-style')
    {{-- Page Css files --}}

    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">

    <link rel="stylesheet" href="{{ asset(mix('vendors/css/tables/datatable/dataTables.bootstrap5.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/tables/datatable/responsive.bootstrap5.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/tables/datatable/buttons.bootstrap5.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/extensions/sweetalert2.min.css')) }}">

    <link rel="stylesheet" href="{{ asset(mix('css/base/plugins/forms/form-validation.css')) }}">

@endsection

@section('content')
    @php
        // Which tab opens: ?tab=, the tab a controller redirected with, or the tab that owns a validation error.
        $activeTab = request()->input('tab') ?: old('tab');
        if (! $activeTab) {
            $activeTab = match (true) {
                $errors->hasAny(['current_password', 'password', 'password_confirmation']) => 'security',
                $errors->hasAny(['phone', 'company', 'website', 'address', 'city', 'state', 'postcode', 'country', 'financial_address', 'financial_city', 'financial_postcode', 'tax_number']) => 'information',
                default => 'account',
            };
        }
    @endphp
    <!-- users edit start -->
    <section class="users-edit sr-page sr-page--narrow">

        <div class="sr-head">
            <div class="sr-head__main">
                <h1 class="sr-title">Your account</h1>
                <p class="sr-lede">These settings are yours and apply in every Business you can open.</p>
            </div>
        </div>

        <ul class="nav sr-tabs" role="tablist">

            <li class="nav-item">
                <a class="nav-link {{ $activeTab === 'account' ? 'active' : '' }}"
                   id="account-tab" data-bs-toggle="tab" href="#account" aria-controls="account" role="tab"
                   aria-selected="true">
                    <x-ds-icon name="user" /> {{__('locale.labels.account')}}
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link {{ $activeTab === 'security' ? 'active' : '' }}" id="security-tab"
                   data-bs-toggle="tab" href="#security" aria-controls="security" role="tab" aria-selected="true">
                    <x-ds-icon name="lock" /> {{__('locale.labels.security')}}
                </a>
            </li>

            @if(config('app.two_factor'))
                <li class="nav-item">
                    <a class="nav-link {{ $activeTab === 'two_factor' ? 'active' : '' }}" id="two-factor-tab"
                       data-bs-toggle="tab" href="#two-factor" aria-controls="two-factor" role="tab"
                       aria-selected="false">
                        <x-ds-icon name="log-in" /> {{__('locale.labels.two_factor_authentication')}}
                    </a>
                </li>
            @endif

            @if($user->active_portal == 'customer')
                <li class="nav-item">
                    <a class="nav-link {{ $activeTab === 'information' ? 'active' : '' }}" id="information-tab"
                       data-bs-toggle="tab" href="#information" aria-controls="information" role="tab"
                       aria-selected="false">
                        <x-ds-icon name="info" />{{__('locale.labels.information')}}
                    </a>
                </li>

                {{-- No "Webhook URL" tab: forwarding inbound messages to a URL is
                     messaging infrastructure, not a personal preference. The stored
                     users.webhook_url, its save route and the legacy inbound
                     forwarding that reads it are untouched. --}}
            @endif



            @if(config('app.trai_dlt') == 1
                && isset($user->customer)
                && $user->customer->activeSubscription()?->plan?->is_dlt)
                {{--Entity ID--}}
                <li class="nav-item">
                    <a class="nav-link {{ $activeTab === 'dlt_entity_id' ? 'active' : '' }}"
                       id="dlt_entity_id-tab" data-bs-toggle="tab" href="#dlt_entity_id"
                       aria-controls="dlt_entity_id" role="tab" aria-selected="false">
                        <x-ds-icon name="key" class="font-medium-3 me-50" />
                        <span class="fw-bold">{{ __('locale.labels.dlt') }} {{ __('locale.labels.entity_id') }}</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link {{ $activeTab === 'dlt_telemarketer_id' ? 'active' : '' }}"
                       id="dlt_telemarketer_id-tab" data-bs-toggle="tab" href="#dlt_telemarketer_id"
                       aria-controls="dlt_telemarketer_id" role="tab" aria-selected="false">
                        <x-ds-icon name="headphones" class="font-medium-3 me-50" />
                        <span class="fw-bold">{{ __('locale.labels.dlt') }} {{ __('locale.labels.telemarketer_id') }}</span>
                    </a>
                </li>
            @endif

        </ul>


        <div class="tab-content">

            <div class="tab-pane {{ $activeTab === 'account' ? 'active' : '' }}"
                 id="account" aria-labelledby="account-tab" role="tabpanel">
                <!-- users edit account form start -->
                @include('auth.profile._accounts')
                <!-- users edit account form ends -->
            </div>

            <div class="tab-pane {{ $activeTab === 'security' ? 'active' : '' }}" id="security"
                 aria-labelledby="security-tab" role="tabpanel">
                <!-- users edit Info form start -->
                @include('auth.profile._security')
                <!-- users edit Info form ends -->
            </div>



            @if($user->active_portal == 'customer')
                <div class="tab-pane {{ $activeTab === 'information' ? 'active' : '' }}" id="information"
                     aria-labelledby="information-tab" role="tabpanel">
                    <!-- users edit Info form start -->
                    @include('auth.profile._information')
                    <!-- users edit Info form ends -->
                </div>

            @endif


            @if(config('app.two_factor'))
                <div class="tab-pane {{ $activeTab === 'two_factor' ? 'active' : '' }}" id="two-factor"
                     aria-labelledby="two-factor-tab" role="tabpanel">
                    @include('auth.profile._two_factor_authentication')
                </div>
            @endif



            @if(config('app.trai_dlt') == 1
                && isset($user->customer)
                && $user->customer->activeSubscription()?->plan?->is_dlt)
                <div class="tab-pane {{ $activeTab === 'dlt_entity_id' ? 'active' : '' }}"
                     id="dlt_entity_id" aria-labelledby="dlt_entity_id-tab" role="tabpanel">
                    @include('auth.profile._dlt_entity_id')
                </div>

                <div class="tab-pane {{ $activeTab === 'dlt_telemarketer_id' ? 'active' : '' }}"
                     id="dlt_telemarketer_id" aria-labelledby="dlt_telemarketer_id-tab" role="tabpanel">
                    @include('auth.profile._dlt_telemarketer_id')
                </div>
            @endif


        </div>
    </section>
    <!-- users edit ends -->
@endsection

@section('vendor-script')
    {{-- Vendor js files --}}
    <script src="{{ asset(mix('vendors/js/forms/select/select2.full.min.js')) }}"></script>

    <script src="{{ asset(mix('vendors/js/tables/datatable/jquery.dataTables.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/tables/datatable/dataTables.bootstrap5.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/tables/datatable/dataTables.responsive.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/tables/datatable/responsive.bootstrap5.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/tables/datatable/datatables.checkboxes.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/tables/datatable/datatables.buttons.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/tables/datatable/buttons.html5.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/tables/datatable/dataTables.rowGroup.min.js')) }}"></script>

    <script src="{{ asset(mix('vendors/js/extensions/sweetalert2.all.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/extensions/polyfill.min.js')) }}"></script>

    <script src="{{ asset(mix('vendors/js/forms/validation/jquery.validate.min.js')) }}"></script>
@endsection

@section('page-script')
    {{-- Page js files --}}

    <script>

        $(document).ready(function () {
            "use strict"

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

              // Fix: Replace icons after redraw
              setTimeout(() => {
                feather.replace();
              }, 100); // slight delay to ensure DOM update completes
            }

            // On Remove Avatar
            $('#remove-avatar').on("click", function (e) {

                e.stopPropagation();
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
                            url: "{{ route('user.remove_avatar') }}",
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


            let canDelete = {{ json_encode(config('account.can_delete')) }};

            if (canDelete) {

                let deactivateAcc = document.querySelector('#formAccountDeactivation'),
                    deactivateButton = deactivateAcc.querySelector('.deactivate-account'),
                    form = $('.validate-form');


                // disabled submit button on checkbox unselect
                if (deactivateAcc) {
                    $(document).on('click', '#accountActivation', function () {
                        if (accountActivation.checked === true) {
                            deactivateButton.removeAttribute('disabled');
                        } else {
                            deactivateButton.setAttribute('disabled', 'disabled');
                        }
                    });
                }

                // Deactivate account alert
                const accountActivation = document.querySelector('#accountActivation');

                // Alert With Functional Confirm Button
                if (deactivateButton) {
                    deactivateButton.onclick = function () {
                        if (accountActivation.checked === true) {
                            Swal.fire({
                                text: "{{ __('locale.auth.want_to_delete_your_account') }}?",
                                icon: 'warning',
                                showCancelButton: true,
                                confirmButtonText: "{{ __('locale.labels.yes') }}",
                                customClass: {
                                    confirmButton: 'btn btn-primary',
                                    cancelButton: 'btn btn-outline-danger ms-2'
                                },
                                buttonsStyling: false
                            }).then(function (result) {
                                if (result.value) {
                                    $.ajax({
                                        url: "{{ route('user.account.delete')}}",
                                        type: "POST",
                                        data: {
                                            _token: "{{csrf_token()}}"
                                        },
                                        success: function (data) {
                                            showResponseMessage(data);
                                            setTimeout(function () {
                                                window.location.href = "{{ route('login') }}";
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
                                } else if (result.dismiss === Swal.DismissReason.cancel) {
                                    toastr['info']("Your account wasn't deactivated.");
                                }
                            });
                        }
                    };
                }

                // jQuery Validation for all forms
                // --------------------------------------------------------------------
                if (form.length) {
                    form.each(function () {
                        const $this = $(this);

                        $this.validate({
                            rules: {
                                accountActivation: {
                                    required: true
                                }
                            }
                        });
                    });
                }

            }


        });
    </script>

@endsection
