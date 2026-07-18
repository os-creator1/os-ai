@extends('layouts/contentLayoutMaster')

@section('title', $device['alias'])

@section('vendor-style')
    {{-- vendor css files --}}
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/extensions/sweetalert2.min.css')) }}">
@endsection

@section('page-style')
    <style>
        .overlay {
            display: none;
            position: fixed;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            z-index: 999;
            background: rgba(255, 255, 255, 0.8) url("https://media.giphy.com/media/kUTME7ABmhYg5J3psM/giphy.gif") center no-repeat;
        }

        /* Turn off scrollbar when body element has the loading class */
        body.loading {
            overflow: hidden;
        }

        /* Make spinner image visible when body element has the loading class */
        body.loading .overlay {
            display: block;
        }
    </style>
@endsection

@section('content')
    <!-- Basic Vertical form layout section start -->
    <section id="basic-vertical-layouts">
        <div class="row">

            <div class="col-12">

                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">{{ $device['alias'] }}({{ $device['phone'] }})</h4>
                        <div class="btn-group">
                            <button
                                    class="btn btn-primary dropdown-toggle"
                                    type="button"
                                    id="dropdownMenuButton"
                                    data-bs-toggle="dropdown"
                                    aria-expanded="false"
                            >
                                {{ __('locale.labels.actions') }}
                            </button>
                            <div class="dropdown-menu" aria-labelledby="dropdownMenuButton">
                                <a class="dropdown-item reboot" href="#" data-id="{{ $server['uid'] }}"><i data-feather="rotate-cw"></i> {{ __('locale.labels.reboot') }}</a>
                                <a class="dropdown-item reset" href="#" data-id="{{ $server['uid'] }}"><i data-feather="power"></i> {{ __('locale.labels.recreate') }}</a>
                                <a class="dropdown-item scan" href="#" data-id="{{ $server['uid'] }}"><i data-feather="unlock"></i> {{ __('locale.labels.authorize') }}</a>
                                <a class="dropdown-item sync" href="#" data-id="{{ $server['uid'] }}"><i data-feather="refresh-cw"></i> {{ __('locale.labels.synchronize') }}</a>
                                <a class="dropdown-item start" href="#" data-id="{{ $server['uid'] }}"><i data-feather="play-circle"></i> {{ __('locale.labels.start') }}</a>
                            </div>
                        </div>
                    </div>
                    <hr>
                    <div class="card-content">
                        <div class="card-body">
                            <div class="row">

                                <div class="col-sm-12 col-md-12 col-lg-3">
                                    <fieldset class="form-group m-0">
                                        <h5>{{ __('locale.labels.id') }}</h5>
                                        <p class="m-0">
                                            <button class="btn btn-link p-0"><small>{{ $device['id'] }}</small></button>
                                        </p>
                                    </fieldset>
                                </div>


                                <div class="col-6 col-sm-4 col-md-3">
                                    <fieldset class="form-group m-0">
                                        <h5>{{ __('locale.labels.phone') }}</h5>
                                        <p class="m-0">{{ $device['phone'] }}</p>
                                    </fieldset>
                                </div>

                                <div class="col-6 col-sm-4 col-md-3">
                                    <fieldset class="form-group m-0">
                                        <h5>{{ __('locale.labels.whatsapp') }} {{ __('locale.labels.id') }}</h5>
                                        <p class="m-0">{{ $device['wid'] }}</p>
                                    </fieldset>
                                </div>


                                <div class="col-6 col-sm-4 col-md-3">
                                    <div class="h5">{{ __('locale.menu.Plan') }}</div>
                                    <p class="m-0"><span>{{ $device['billing']['subscription']['plan'] }}</span></p>
                                </div>


                                <div class="col-12 my-5" style="border-top: 1px solid rgba(0, 40, 100, 0.12);"></div>

                                <div class="col-sm-3 col-md-3 col-lg-3">
                                    <fieldset class="form-group m-0">
                                        <h5>{{ __('locale.labels.battery') }}</h5>
                                        <p class="m-0 truncate" style="width: 160px;"><i data-feather="battery-charging"></i> <span>{{ $device['info']['battery'] }}</span>%</p>
                                    </fieldset>
                                </div>
                                <div class="col-sm-6 col-md-4 col-lg-3">
                                    <fieldset class="form-group m-0"><h5>{{ __('locale.labels.status') }}</h5>
                                        <p class="m-0">
                                            <span>{{ ucfirst($device['status']) }}</span>

                                            <a href="#"
                                               data-bs-toggle="tooltip" data-bs-placement="top"
                                               data-bs-original-title="{{ __('locale.labels.device_status') }}" class="ml-1 btn btn-outline-primary btn-sm reboot"><i data-feather="rotate-cw"></i></a>
                                        </p>
                                    </fieldset>
                                </div>
                                <div class="col-sm-6 col-md-4 col-lg-6">
                                    <fieldset class="form-group m-0">
                                        <h5>{{ __('locale.labels.session_status') }}</h5>
                                        <p class="m-0">
                                            <span>
                                                @if($device['session']['status'] == 'online')
                                                    <span>{{ ucfirst($device['session']['status']) }}</span>
                                                    <a href="#" data-bs-toggle="tooltip" data-id="{{$server['uid']}}" data-bs-placement="top" data-bs-original-title="{{ __('locale.labels.sync_session_status') }}" class="btn btn-sm btn-outline-primary ml-1 sync">
                                                    <i data-feather="refresh-cw"></i>
                                                </a>
                                                @else
                                                    <span class="text-danger">{{ __('locale.exceptions.unauthorized') }}</span>
                                                    <a href="#" data-id="{{$server['uid']}}" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-original-title="{{ __('locale.labels.authorize_device') }}" class="btn btn-sm btn-outline-primary ml-1 scan">
                                                    {{ ucfirst($device['session']['status']) }}
                                                </a>
                                                @endif

                                            </span>
                                        </p>
                                    </fieldset>
                                </div>


                                <div class="col-12 my-5" style="border-top: 1px solid rgba(0, 40, 100, 0.12);"></div>
                                <div class="col-5 col-md-3">
                                    <h5>{{ __('locale.labels.whatsapp') }} {{ __('locale.labels.version') }}</h5>

                                    <p class="m-0 truncate" style="width: 170px;">
                                        <i class="mr-1" data-feather="message-circle"></i>
                                        @if($device['info']['isBusiness'])
                                            <span>{{ __('locale.labels.business') }}</span>
                                        @else
                                            <span>{{ __('locale.auth.personal') }}</span>
                                        @endif
                                        / <span>{{ $device['info']['waVersion'] }}</span>
                                    </p>
                                </div>

                                @if(isset($device['session']['appVersion']))
                                    <div class="col-5 col-md-3"><h5>{{ __('locale.labels.whatsapp') }} {{ __('locale.labels.client') }}</h5>
                                        <span>{{ $device['session']['appVersion'] }}</span>
                                    </div>
                                @endif

                                @if(isset($device['info']['platform']['version']))
                                    <div class="col-5 col-md-3">
                                        <fieldset class="m-0">
                                            <h5>{{ __('locale.labels.platform') }}</h5>
                                            <p class="m-0 truncate" style="width: 160px;">
                                                <span>{{ $device['info']['platform']['version'] }}</span>
                                            </p>
                                        </fieldset>
                                    </div>
                                @endif

                                @if(isset($device['info']['platform']['manufacturer']) && isset($device['info']['platform']['model']))
                                    <div class="col-5 col-md-3">
                                        <fieldset class="form-group m-0">
                                            <h5>{{ __('locale.labels.hardware') }}</h5>
                                            <p class="m-0 text-truncate" style="width: 160px;"><i class="mr-2" data-feather="smartphone"></i>
                                                {{ $device['info']['platform']['manufacturer'] }}<span> / {{ $device['info']['platform']['model'] }}</span></p></fieldset>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
        <div class="overlay"></div>
    </section>
    <!-- // Basic Vertical form layout section end -->

@endsection


@section('vendor-script')
    {{-- vendor files --}}
    <script src="{{ asset(mix('vendors/js/extensions/sweetalert2.all.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/extensions/polyfill.min.js')) }}"></script>

@endsection
@section('page-script')
    {{-- Page js files --}}
    <script>

        $(document).on({
            ajaxStart: function () {
                $("body").addClass("loading");
            },
            ajaxStop: function () {
                $("body").removeClass("loading");
            }
        });

        $(document).ready(function () {
            "use strict"

            //show response message
            function showResponseMessage(data) {

                $("body").removeClass("loading");

                if (data.status === 'success') {
                    toastr['success'](data.message, '{{__('locale.labels.success')}}!!', {
                        closeButton: true,
                        positionClass: 'toast-top-right',
                        progressBar: true,
                        newestOnTop: true,
                        rtl: isRtl,
                        timeout: 6000
                    });

                    setTimeout(function () {// wait for 5 secs(2)
                        location.reload(); // then reload the page.(3)
                    }, 6000);
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

            // On Reboot
            $('.reboot').on('click', function (e) {
                e.stopPropagation();
                let id = $(this).data('id');

                Swal.fire({
                    title: "Do you want to reboot the session?",
                    text: "Session authorization is not usually required after the reboot.",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: "Reboot",
                    customClass: {
                        confirmButton: 'btn btn-primary',
                        cancelButton: 'btn btn-outline-danger ms-1'
                    },
                    buttonsStyling: false,
                }).then(function (result) {
                    if (result.value) {

                        $("body").addClass("loading");

                        $.ajax({
                            url: "{{ url(config('app.admin_path').'/sending-servers')}}" + '/' + id + '/reboot',
                            type: "POST",
                            data: {
                                _token: "{{csrf_token()}}"
                            },
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
                        })
                    }
                })
            });

            // On Reboot
            $('.scan').on('click', function (e) {
                e.stopPropagation();
                let id = $(this).data('id');

                Swal.fire({
                    title: 'Authorize session',
                    icon: 'success',
                    html:
                        '<h3>Please, pick up your device and open WhatsApp</h3>, ' +
                        '<h5>To prevent conflict, please close all existing WhatsApp Web open sessions</h5>, ' +
                        '<h6>It will take more than 2 minutes, please don\'t reload the page</h6>, ' +
                        '<p>' +
                        '<video width="540" autoplay="autoplay" controls="controls" muted="muted" loop="loop" preload="">' +
                        '<source src="https://app.whatsender.io/videos/scanqr.mp4" type="video/mp4">' +
                        '</video>' +
                        '</p> ',
                    showCloseButton: true,
                    showCancelButton: true,
                    focusConfirm: false,
                    confirmButtonText: "I'm ready, let's do it",
                    customClass: {
                        confirmButton: 'btn btn-primary',
                        cancelButton: 'btn btn-outline-danger ms-1'
                    },
                    width: 'auto',
                    buttonsStyling: false
                }).then(function (result) {
                    if (result.value) {
                        $.ajax({
                            url: "{{ url(config('app.admin_path').'/sending-servers')}}" + '/' + id + '/scan',
                            type: "POST",
                            data: {
                                _token: "{{csrf_token()}}"
                            },
                            success: function (data) {
                                if (data.status === 'error') {
                                    toastr['info'](data.message, "{{__('locale.labels.attention')}}", {
                                        closeButton: true,
                                        positionClass: 'toast-top-right',
                                        progressBar: true,
                                        newestOnTop: true,
                                        rtl: isRtl
                                    });
                                } else {
                                    Swal.fire({
                                        icon: 'success',
                                        title: 'Session authorization',
                                        showCloseButton: true,
                                        showCancelButton: true,
                                        focusConfirm: false,
                                        confirmButtonText: "Done",
                                        customClass: {
                                            confirmButton: 'btn btn-primary',
                                            cancelButton: 'btn btn-outline-danger ms-1'
                                        },
                                        width: 'auto',
                                        buttonsStyling: false,
                                        html: data.image,
                                    }).then(function (result) {
                                        if (result.value) {
                                            toastr['success']('Session authorization was successful', '{{__('locale.labels.success')}}!!', {
                                                closeButton: true,
                                                positionClass: 'toast-top-right',
                                                progressBar: true,
                                                newestOnTop: true,
                                                rtl: isRtl,
                                                timeout: 6000
                                            });

                                            setTimeout(function () {// wait for 5 secs(2)
                                                location.reload(); // then reload the page.(3)
                                            }, 6000);
                                        }
                                    });
                                }

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
                });

            });

            // On Reset
            $('.reset').on('click', function (e) {
                e.stopPropagation();
                let id = $(this).data('id');

                Swal.fire({
                    title: 'Session recreation confirmation',
                    icon: 'success',
                    html:
                        '<h5>Session QR authorization will be required after the recreation.</h5>, ' +
                        '<h5>The device will be unable to send or receive messages until it is authorized again.</h5>, ' +
                        '<h5>This process typically takes less than 2 minutes on which the device will be unavailable.</h5>, ' +
                        '<h5>No messages will be lost during this process unless explicitly requested below.</h5>,',
                    showCloseButton: true,
                    showCancelButton: true,
                    focusConfirm: false,
                    confirmButtonText: "Recreate Device",
                    customClass: {
                        confirmButton: 'btn btn-primary',
                        cancelButton: 'btn btn-outline-danger ms-1'
                    },
                    width: 'auto',
                    buttonsStyling: false
                }).then(function (result) {
                    if (result.value) {
                        $.ajax({
                            url: "{{ url(config('app.admin_path').'/sending-servers')}}" + '/' + id + '/reset',
                            type: "POST",
                            data: {
                                _token: "{{csrf_token()}}"
                            },
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
                        })
                    }
                });

            });


            // On Sync
            $('.sync').on('click', function (e) {
                e.stopPropagation();
                let id = $(this).data('id');

                Swal.fire({
                    title: "Do you want to synchronize the session?",
                    text: "This process might take up to 1 minute.",
                    icon: 'success',
                    showCancelButton: true,
                    confirmButtonText: "Sync",
                    customClass: {
                        confirmButton: 'btn btn-primary',
                        cancelButton: 'btn btn-outline-danger ms-1'
                    },
                    buttonsStyling: false,
                }).then(function (result) {
                    if (result.value) {
                        $.ajax({
                            url: "{{ url(config('app.admin_path').'/sending-servers')}}" + '/' + id + '/sync',
                            type: "POST",
                            data: {
                                _token: "{{csrf_token()}}"
                            },
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
                        })
                    }
                })
            });

            // On Sync
            $('.start').on('click', function (e) {
                e.stopPropagation();
                let id = $(this).data('id');

                Swal.fire({
                    title: "Do you want to start the session?",
                    text: "This process might take up to 1 minute.",
                    icon: 'success',
                    showCancelButton: true,
                    confirmButtonText: "Start",
                    customClass: {
                        confirmButton: 'btn btn-primary',
                        cancelButton: 'btn btn-outline-danger ms-1'
                    },
                    buttonsStyling: false,
                }).then(function (result) {
                    if (result.value) {
                        $.ajax({
                            url: "{{ url(config('app.admin_path').'/sending-servers')}}" + '/' + id + '/start',
                            type: "POST",
                            data: {
                                _token: "{{csrf_token()}}"
                            },
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
                        })
                    }
                })
            });

        });
    </script>

@endsection
