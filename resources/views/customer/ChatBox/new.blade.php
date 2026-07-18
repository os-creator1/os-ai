@extends('layouts/contentLayoutMaster')

@section('title', __('locale.labels.new_conversion'))


@section('vendor-style')
    <!-- vendor css files -->
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
@endsection


@section('content')
    <!-- Basic Vertical form layout section start -->
    <section id="basic-vertical-layouts">
        <div class="row">
            <div class="col-md-6 col-12">
                <div class="alert alert-info" role="alert">
                    <div class="alert-body d-flex align-items-center">
                        <i data-feather="info" class="me-50"></i>
                        <span class="text-uppercase"> {{ __('locale.template_tags.not_work_with_quick_send')  }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="row match-height">
            <div class="col-md-6 col-12">

                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">{{ __('locale.labels.new_conversion') }}</h4>
                        <a href="{{ route('customer.chatbox.index') }}"
                           class="text-primary d-block d-md-none">{{ __('locale.menu.Chat Box') }}</a>
                    </div>
                    <div class="card-content">
                        <div class="card-body">

                            <form class="form form-vertical" action="{{ route('customer.chatbox.sent') }}"
                                  method="post">
                                @csrf
                                <div class="row">

                                    @if($sendingServers->count() > 0)
                                        <div class="col-12">
                                            <div class="mb-1">
                                                <label for="sending_server"
                                                       class="form-label required">{{ __('locale.labels.sending_server') }}</label>
                                                <select class="select2 form-select" name="sending_server">
                                                    @foreach($sendingServers as $server)
                                                        @if(isset($server->sendingServer) && $server->sendingServer->status == 1 && $server->sendingServer->two_way)
                                                            <option value="{{$server->sendingServer->id}}"> {{ $server->sendingServer->name }}</option>
                                                        @endif
                                                    @endforeach
                                                </select>

                                                @error('sending_server')
                                                <p><small class="text-danger">{{ $message }}</small></p>
                                                @enderror
                                            </div>
                                        </div>

                                    @endif

                                    <div class="col-12">

                                        <div class="mb-1">
                                            <label for="sender_id"
                                                   class="form-label required">{{__('locale.labels.originator')}}</label>
                                            <select class="form-select select2" id="sender_id" name="sender_id">
                                                @foreach($phone_numbers as $number)
                                                    <option value="{{$number->number}}"> {{ $number->number }}</option>
                                                @endforeach
                                            </select>

                                            @error('sender_id')
                                            <p><small class="text-danger">{{ $message }}</small></p>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="country_code"
                                                   class="form-label required">{{ __('locale.labels.recipient') }}</label>
                                            <div class="input-group">
                                                <div style="width: 8rem">
                                                    <select class="form-select select2" id="country_code"
                                                            name="country_code">
                                                        @foreach($coverage as $code)
                                                            <option value="{{ $code->country_id }}">
                                                                +{{ $code->country->country_code }} </option>
                                                        @endforeach
                                                    </select>
                                                </div>

                                                <input type="text"
                                                       id="recipient"
                                                       class="form-control @error('recipient') is-invalid @enderror"
                                                       value="{{ old('recipient',  $recipient ?? null) }}"
                                                       name="recipient"
                                                       required
                                                       placeholder="{{__('locale.labels.required')}}"
                                                >

                                            </div>

                                            @error('recipient')
                                            <p><small class="text-danger">{{ $message }}</small></p>
                                            @enderror
                                            @error('country_code')
                                            <p><small class="text-danger">{{ $message }}</small></p>
                                            @enderror
                                        </div>
                                    </div>


                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label class="sms_template form-label">{{__('locale.permission.sms_template')}}</label>
                                            <select class="form-select select2" id="sms_template">
                                                <option>{{ __('locale.labels.select_one') }}</option>
                                                @foreach($templates as $template)
                                                    <option value="{{$template->id}}">{{ $template->name }}</option>
                                                @endforeach

                                            </select>
                                        </div>
                                    </div>


                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="message"
                                                   class="required">{{ __('locale.labels.message') }}</label>
                                            <textarea class="form-control" id="message" rows="4" required
                                                      name="message">{{old('message')}}</textarea>
                                            @error('message')
                                            <p><small class="text-danger">{{ $message }}</small></p>
                                            @enderror
                                        </div>
                                    </div>

                                </div>

                                <div class="row">
                                    <div class="col-12">
                                        <input type="hidden" name="sms_type" value="plain">
                                        <button type="submit" class="btn btn-primary mr-1 mb-1 float-end">
                                            <i data-feather="send"></i> {{__('locale.buttons.send')}}
                                        </button>
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
@endsection


@section('page-script')

    <script>

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

        let firstInvalid = $('form').find('.is-invalid').eq(0);

        if (firstInvalid.length) {
            $('body, html').stop(true, true).animate({
                'scrollTop': firstInvalid.offset().top - 200 + 'px'
            }, 200);
        }

        $("#sms_template").on('change', function () {

            let template_id = $(this).val(),
                $get_msg = $("#message");

            $.ajax({
                url: "{{ url('templates/show-data')}}" + '/' + template_id,
                type: "POST",
                data: {
                    _token: "{{csrf_token()}}"
                },
                cache: false,
                success: function (data) {
                    if (data.status === 'success') {
                        const caretPos = $get_msg[0].selectionStart;
                        const textAreaTxt = $get_msg.val();
                        let txtToAdd = data.message;

                        $get_msg.val(textAreaTxt.substring(0, caretPos) + txtToAdd + textAreaTxt.substring(caretPos)).val().length;

                    } else {
                        toastr['warning'](data.message, "{{ __('locale.labels.attention') }}", {
                            closeButton: true,
                            positionClass: 'toast-top-right',
                            progressBar: true,
                            newestOnTop: true,
                            rtl: isRtl
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
            });
        });

    </script>
@endsection
