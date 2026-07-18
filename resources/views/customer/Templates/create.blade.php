@extends('layouts/contentLayoutMaster')
@if(isset($template))
    @section('title', __('locale.templates.update_template'))
@else
    @section('title', __('locale.templates.add_template'))
@endif


@section('vendor-style')
    <!-- vendor css files -->
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">

@endsection

@section('content')

    <!-- Basic Vertical form layout section start -->
    <section id="basic-vertical-layouts">
        <div class="row match-height">
            <div class="col-md-6 col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">@if(isset($template))
                                {{ __('locale.templates.update_template') }}
                            @else
                                {{ __('locale.templates.add_template') }}
                            @endif </h4>
                    </div>
                    <div class="card-content">
                        <div class="card-body">

                            @if(config('app.trai_dlt')  && Auth::user()->customer->activeSubscription() !== null  && Auth::user()->customer->activeSubscription()->plan->is_dlt)
                                <p><code>{!!  __('locale.templates.dlt_description') !!}</code></p>
                            @endif

                            <form class="form form-vertical"
                                  @if(isset($template)) action="{{ route('customer.templates.update',  $template->uid) }}"
                                  @else action="{{ route('customer.templates.store') }}" @endif method="post">
                                @if(isset($template))
                                    {{ method_field('PUT') }}
                                @endif
                                @csrf
                                <div class="form-body">
                                    <div class="row">

                                        <div class="col-12">
                                            <div class="mb-1">
                                                <label for="name"
                                                       class="form-label required">{{ __('locale.labels.name') }}</label>
                                                <input type="text" id="name"
                                                       class="form-control @error('name') is-invalid @enderror"
                                                       value="{{ old('name',  $template->name ?? null) }}" name="name"
                                                       required placeholder="{{__('locale.labels.required')}}"
                                                       autofocus>
                                                @error('name')
                                                <div class="invalid-feedback">
                                                    {{ $message }}
                                                </div>
                                                @enderror
                                            </div>
                                        </div>


                                        @if(config('app.trai_dlt') && Auth::user()->customer->activeSubscription() !== null && Auth::user()->customer->activeSubscription()->plan->is_dlt)
                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="dlt_template_id"
                                                           class="form-label required">{{ __('locale.templates.dlt_template_id') }}</label>
                                                    <input type="text" id="dlt_template_id"
                                                           class="form-control @error('dlt_template_id') is-invalid @enderror"
                                                           value="{{ old('dlt_template_id',  $template->dlt_template_id ?? null) }}"
                                                           name="dlt_template_id" required
                                                           placeholder="{{__('locale.labels.required')}}">
                                                    @error('dlt_template_id')
                                                    <div class="invalid-feedback">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>


                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label class="form-label required"
                                                           for="category">{{__('locale.labels.category')}}</label>
                                                    <select class="form-select" id="category" name="dlt_category">
                                                        <option value="promotional"
                                                                @if(isset($template->dlt_category) && $template->dlt_category =='promotional') selected @endif>{{ __('locale.labels.promotional') }}</option>
                                                        <option value="transactional"
                                                                @if(isset($template->dlt_category) && $template->dlt_category =='transactional') selected @endif>{{ __('locale.labels.transactional') }}</option>
                                                        <option value="service_explicit"
                                                                @if(isset($template->dlt_category) && $template->dlt_category =='service_explicit') selected @endif>{{ __('locale.labels.service_explicit') }}</option>
                                                        <option value="service_implicit"
                                                                @if(isset($template->dlt_category) && $template->dlt_category =='service_implicit') selected @endif>{{ __('locale.labels.service_implicit') }}</option>
                                                    </select>

                                                    @error('dlt_category')
                                                    <p><small class="text-danger">{{ $message }}</small></p>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="sender_id"
                                                           class="form-label required">{{__('locale.labels.sender_id')}}</label>
                                                    <select class="form-select select2" name="sender_id">
                                                        @foreach($sender_ids as $sender_id)
                                                            <option value="{{$sender_id->id}}"
                                                                    {{ isset($template->sender_id) && $template->sender_id == $sender_id->id ? 'selected': null }}>
                                                                {{$sender_id->sender_id}}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                </div>

                                                @error('sender_id')
                                                <p><small class="text-danger">{{ $message }}</small></p>
                                                @enderror
                                            </div>

                                            @if(isset($template))
                                                <div class="col-12">
                                                    <div class="mb-1">
                                                        <label class="form-label required"
                                                               for="approved">{{__('locale.labels.approve')}}</label>
                                                        <select class="form-select" id="approved" name="approved">
                                                            <option value="in_review"
                                                                    @if($template->approved == 'in_review') selected @endif>{{ __('locale.labels.in_review') }}</option>
                                                            <option value="approved"
                                                                    @if($template->approved == 'approved') selected @endif>{{ __('locale.labels.yes') }}</option>
                                                            <option value="block"
                                                                    @if($template->approved == 'block') selected @endif>{{ __('locale.labels.no') }}</option>
                                                        </select>

                                                        @error('approved')
                                                        <p><small class="text-danger">{{ $message }}</small></p>
                                                        @enderror
                                                    </div>
                                                </div>
                                            @endif
                                        @endif


                                        <div class="col-12">
                                            <div class="mb-1">
                                                <label class="form-label">{{__('locale.labels.available_tag')}}</label>
                                                <select class="form-select select2" id="available_tag">
                                                    <option value="PHONE">{{ __('locale.labels.phone') }}</option>
                                                    <option value="FIRST_NAME">{{ __('locale.labels.first_name') }}</option>
                                                    <option value="LAST_NAME">{{ __('locale.labels.last_name') }}</option>
                                                    <option value="EMAIL">{{ __('locale.labels.email') }}</option>
                                                    <option value="USERNAME">{{ __('locale.labels.username') }}</option>
                                                    <option value="COMPANY">{{ __('locale.labels.company') }}</option>
                                                    <option value="ADDRESS">{{ __('locale.labels.address') }}</option>
                                                </select>
                                            </div>
                                        </div>


                                        <div class="col-12">
                                            <div class="mb-1">
                                                <label for="message"
                                                       class="form-label required">{{__('locale.labels.message')}}</label>
                                                <textarea class="form-control" name="message" rows="5"
                                                          id="message">{{ old('message',  $template->message ?? null) }}</textarea>

                                                <small class="text-primary text-uppercase"
                                                       id="remaining">160 {{ __('locale.labels.characters_remaining') }}</small>
                                                <small class="text-primary text-uppercase float-end"
                                                       id="messages">1 {{ __('locale.labels.message') }} (s)</small>
                                                @error('message')
                                                <div class="invalid-feedback">
                                                    {{ $message }}
                                                </div>
                                                @enderror
                                            </div>
                                        </div>


                                        <div class="col-12">
                                            <input type="hidden" name="user_id" value="{{ Auth::user()->id }}">
                                            <input type="hidden" name="user_type" value="customer">
                                            <button type="submit" class="btn btn-primary me-1 mb-1"><i
                                                        data-feather="save"></i> {{ __('locale.buttons.save') }}
                                            </button>
                                            <button type="reset" class="btn btn-outline-warning mb-1"><i
                                                        data-feather="refresh-cw"></i> {{ __('locale.buttons.reset') }}
                                            </button>
                                        </div>

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
    <script src="{{ asset(mix('js/scripts/sms-counter.js')) }}"></script>
    <script>
        $(document).ready(function () {

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


            let $remaining = $('#remaining'),
                $messages = $remaining.next(),
                $get_msg = $("#message"),
                merge_state = $('#available_tag'),
                firstInvalid = $('form').find('.is-invalid').eq(0);

            if (firstInvalid.length) {
                $('body, html').stop(true, true).animate({
                    'scrollTop': firstInvalid.offset().top - 200 + 'px'
                }, 200);
            }

            function get_character() {
                if ($get_msg[0].value !== null) {
                    let data = SmsCounter.count($get_msg[0].value, true);
                    $remaining.text(data.remaining + " {!! __('locale.labels.characters_remaining') !!}");
                    $messages.text(data.messages + " {!! __('locale.labels.message') !!}" + '(s)');
                }
            }

            merge_state.on('change', function () {
                const caretPos = $get_msg[0].selectionStart;
                const textAreaTxt = $get_msg.val();
                let txtToAdd = this.value;
                if (txtToAdd) {
                    txtToAdd = '{' + txtToAdd + '}';
                }

                $get_msg.val(textAreaTxt.substring(0, caretPos) + txtToAdd + textAreaTxt.substring(caretPos));
            });

            $get_msg.keyup(get_character);
        });
    </script>
@endsection
