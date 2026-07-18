@php use App\Library\Tool; @endphp
@extends('layouts/contentLayoutMaster')

@section('title', __('locale.menu.Campaign Builder'))

@section('vendor-style')
    <!-- vendor css files -->
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/pickers/flatpickr/flatpickr.min.css')) }}">
@endsection

@section('page-style')

    <link rel="stylesheet" href="{{ asset(mix('css/base/plugins/forms/pickers/form-flat-pickr.css')) }}">

    <style>
        .customized_select2 .select2-selection--multiple,
        .customized_select2 .select2-selection--single,
        .input_sender_id {
            border-left: 0;
            border-radius: 0 4px 4px 0;
            min-height: 2.75rem !important;
        }

        .input-group > div.position-relative {
            flex-grow: 1;
        }
    </style>

@endsection

@section('content')

    <!-- Basic Vertical form layout section start -->
    <section id="basic-vertical-layouts campaign_builder">
        <div class="row match-height">
            <div class="col-md-8 col-12">
                <div class="card">
                    <div class="card-content">
                        <div class="card-body">

                            <form class="form form-vertical" action="{{ route('customer.viber.campaign_builder') }}"
                                  method="post" enctype="multipart/form-data">
                                @csrf
                                <div class="row">

                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="name"
                                                   class="required form-label">{{ __('locale.labels.name') }}</label>
                                            <input type="text"
                                                   id="name"
                                                   class="form-control @error('name') is-invalid @enderror"
                                                   value="{{ old('name') }}"
                                                   name="name" required
                                                   placeholder="{{__('locale.labels.required')}}" autofocus>
                                            @error('name')
                                            <p><small class="text-danger">{{ $message }}</small></p>
                                            @enderror
                                        </div>
                                    </div>

                                    @if($sendingServers->count() > 0)
                                        <div class="col-12">
                                            <div class="mb-1">
                                                <label for="sending_server"
                                                       class="form-label required">{{ __('locale.labels.sending_server') }}</label>
                                                <select class="select2 form-select" name="sending_server">
                                                    @foreach($sendingServers as $server)
                                                        @if(isset($server->sendingServer) && $server->sendingServer->status == 1 && $server->sendingServer->viber)
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

                                    @can('view_sender_id')
                                        @if(auth()->user()->customer->getOption('sender_id_verification') == 'yes')
                                            <div class="col-12">
                                                <p class="text-uppercase">{{ __('locale.labels.originator') }}</p>
                                            </div>
                                            <div class="col-md-6 col-12 customized_select2">
                                                <div class="mb-1">
                                                    <label for="sender_id_check"
                                                           class="form-label">{{ __('locale.labels.sender_id') }}
                                                        <a class="text-success text-decoration-underline mx-1 text-uppercase cursor-pointer text"
                                                           href="{{ route('customer.senderid.request') }}"
                                                           target="__blank">{{ __('locale.labels.request_new') }}</a>
                                                    </label>
                                                    <div class="input-group">
                                                        <div class="input-group-text">
                                                            <div class="form-check">
                                                                <input type="radio" class="form-check-input sender_id"
                                                                       name="originator" checked value="sender_id"
                                                                       id="sender_id_check" />
                                                                <label class="form-check-label"
                                                                       for="sender_id_check"></label>
                                                            </div>
                                                        </div>

                                                        <div style="width: 17rem">
                                                            <select class="form-select select2" id="sender_id"
                                                                    name="sender_id[]">
                                                                @foreach($sender_ids as $sender_id)
                                                                    <option value="{{$sender_id->sender_id}}"> {{ $sender_id->sender_id }} </option>
                                                                @endforeach
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        @else
                                            @can('view_numbers')
                                                <div class="col-md-6 col-12 customized_select2">

                                                    <div class="mb-1">
                                                        <label for="sender_id"
                                                               class="form-label">{{ __('locale.labels.sender_id') }}
                                                            <span class="text-success font-small-1 text-uppercase text">
                                                                ({{ __('locale.labels.select_one_or_insert_your_own') }})
                                                            </span>
                                                        </label>
                                                        <div class="input-group">
                                                            <div class="input-group-text">
                                                                <div class="form-check">
                                                                    <input type="radio"
                                                                           class="form-check-input sender_id"
                                                                           name="originator" checked value="sender_id"
                                                                           id="sender_id_check" />
                                                                    <label class="form-check-label"
                                                                           for="sender_id_check"></label>
                                                                </div>
                                                            </div>

                                                            <div style="width: 17rem">
                                                                <select class="form-select max-length input_sender_id"
                                                                        multiple
                                                                        id="sender_id_custom"
                                                                        name="sender_id[]">
                                                                    @if(isset($sender_ids))
                                                                        @foreach($sender_ids as $sender_id)
                                                                            <option value="{{$sender_id->sender_id}}"> {{ $sender_id->sender_id }} </option>
                                                                        @endforeach
                                                                    @endif
                                                                </select>
                                                            </div>
                                                        </div>
                                                    </div>

                                                </div>
                                            @else
                                                <div class="col-12">
                                                    <div class="mb-1">
                                                        <label for="sender_id"
                                                               class="form-label">{{__('locale.labels.sender_id')}}

                                                            <span class="text-success font-small-1 text-uppercase text">
                                                                ({{ __('locale.labels.select_one_or_insert_your_own') }})
                                                            </span>

                                                        </label>
                                                        <select class="form-select max-length input_sender_id"
                                                                multiple
                                                                id="sender_id_custom"
                                                                name="sender_id[]">
                                                            @if(isset($sender_ids))
                                                                @foreach($sender_ids as $sender_id)
                                                                    <option value="{{$sender_id->sender_id}}"> {{ $sender_id->sender_id }} </option>
                                                                @endforeach
                                                            @endif

                                                        </select>
                                                        @error('sender_id')
                                                        <p><small class="text-danger">{{ $message }}</small></p>
                                                        @enderror
                                                    </div>
                                                </div>
                                            @endcan
                                        @endif
                                    @endcan

                                    @can('view_numbers')
                                        <div class="col-md-6 col-12 customized_select2">
                                            <div class="mb-1">
                                                <label for="phone_number"
                                                       class="form-label">{{ __('locale.menu.Phone Numbers') }}
                                                    <a class="text-success text-decoration-underline mx-1 text-uppercase cursor-pointer text"
                                                       href="{{ route('customer.numbers.buy') }}"
                                                       target="__blank">{{ __('locale.labels.request_new') }}</a>
                                                </label>
                                                <div class="input-group">
                                                    <div class="input-group-text">
                                                        <div class="form-check">
                                                            <input type="radio" class="form-check-input phone_number"
                                                                   value="phone_number" name="originator"
                                                                   id="phone_number_check" />
                                                            <label class="form-check-label"
                                                                   for="phone_number_check"></label>
                                                        </div>
                                                    </div>

                                                    <div style="width: 17rem">
                                                        <select class="form-select select2" disabled id="phone_number"
                                                                name="phone_number[]" multiple>
                                                            @foreach($phone_numbers as $number)
                                                                <option value="{{ $number->number }}"> {{ $number->number }} </option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    @endcan


                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="contact_groups"
                                                   class="form-label required">{{ __('locale.contacts.contact_groups') }}</label>
                                            <select class="select2 form-select" required name="contact_groups[]"
                                                    multiple="multiple" id="contact_groups">
                                                @foreach($contact_groups as $group)
                                                    <option value="{{$group->id}}"> {{ $group->name }}
                                                        ({{Tool::number_with_delimiter($group->subscribersCount($group->cache))}} {{__('locale.menu.Contacts')}}
                                                        )
                                                    </option>
                                                @endforeach
                                            </select>

                                            @error('contact_groups')
                                            <p><small class="text-danger">{{ $message }}</small></p>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="col-md-6 col-12">
                                        <div class="mb-1">
                                            <label class="sms_template form-label"
                                                   for="sms_template">{{__('locale.permission.sms_template')}}</label>
                                            <select class="form-select select2" id="sms_template">
                                                <option value="">{{ __('locale.labels.select_one') }}</option>
                                                @foreach($templates as $template)
                                                    <option value="{{$template->id}}">{{ $template->name }}</option>
                                                @endforeach

                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-md-6 col-12">
                                        <div class="mb-1">
                                            <label class="form-label"
                                                   for="available_tag">{{__('locale.labels.available_tag')}}</label>
                                            <select class="form-select select2" id="available_tag"></select>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="mb-1 position-relative">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <label for="message" class="form-label required fw-semibold mb-0">
                                                    {{ __('locale.labels.message') }}
                                                </label>

                                                @if(config('services.openai.active'))
                                                    <button type="button"
                                                            class="btn btn-outline-primary mb-1 btn-sm"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#aiMessageModal">
                                                        ✨ {{ __('locale.labels.generate_with_ai') }}
                                                    </button>
                                                @endif
                                            </div>

                                            <textarea placeholder="{{ __('locale.labels.type_message') }}"
                                                      class="form-control"
                                                      name="message"
                                                      rows="5"
                                                      id="message"></textarea>

                                            <div class="d-flex justify-content-between mt-1 small text-uppercase text-primary">
                                                <div>
                                                    {{ __('locale.labels.remaining') }}:
                                                    <span id="remaining" class="fw-bold">160</span>
                                                    ( <span class="text-success"
                                                            id="charCount">0</span> {{ __('locale.labels.characters') }}
                                                    )
                                                </div>
                                                <div>
                                                    {{ __('locale.labels.message') }}(s):
                                                    <span id="messages" class="fw-bold">1</span>
                                                    ({{ __('locale.labels.encoding') }}:
                                                    <span class="text-success" id="encoding">GSM_7BIT</span>)
                                                </div>
                                            </div>

                                            @error('message')
                                            <small class="text-danger d-block mt-1">{{ $message }}</small>
                                            @enderror
                                        </div>
                                    </div>

                                    <!-- Spinner Overlay -->
                                    <div id="aiLoader"
                                         class="position-fixed top-0 start-0 w-100 h-100 bg-white bg-opacity-75 d-none justify-content-center align-items-center"
                                         style="z-index: 1055;">
                                        <div class="spinner-border text-primary" role="status"
                                             style="width: 3rem; height: 3rem;">
                                            <span class="visually-hidden">{{ __('locale.labels.loading') }} ...</span>
                                        </div>
                                    </div>


                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="mms_file"
                                                   class="form-label">{{__('locale.labels.mms_file')}}</label>
                                            <input type="file" name="mms_file" class="form-control" id="mms_file"
                                                   accept="image/*,video/*" />
                                            @error('mms_file')
                                            <div class="text-danger">
                                                {{ $message }}
                                            </div>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="mb-1">
                                            <div class="form-check form-check-inline">
                                                <input type="checkbox" id="schedule" class="form-check-input schedule"
                                                       value="true"
                                                       name="schedule" {{ old('schedule') ? "checked" : null }}>
                                                <label class="form-check-label"
                                                       for="schedule">{{__('locale.campaigns.schedule_campaign')}}
                                                    ?</label>
                                            </div>
                                            <p>
                                                <small class="text-primary px-2">{{__('locale.campaigns.schedule_campaign_note')}}</small>
                                            </p>
                                        </div>
                                    </div>

                                </div>

                                <div class="row schedule_time">
                                    <div class="col-md-6">
                                        <div class="mb-1">
                                            <label for="schedule_date"
                                                   class="form-label">{{ __('locale.labels.date') }}</label>
                                            <input type="text" id="schedule_date" name="schedule_date"
                                                   class="form-control schedule_date" placeholder="YYYY-MM-DD" />
                                            @error('schedule_date')
                                            <p><small class="text-danger">{{ $message }}</small></p>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="mb-1">
                                            <label for="time" class="form-label">{{ __('locale.labels.time') }}</label>
                                            <input type="text" id="time" class="form-control flatpickr-time text-start"
                                                   name="schedule_time" placeholder="HH:MM" />
                                            @error('schedule_time')
                                            <p><small class="text-danger">{{ $message }}</small></p>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="timezone"
                                                   class="form-label">{{__('locale.labels.timezone')}}</label>
                                            <select class="form-select select2" id="timezone" name="timezone">
                                                @foreach(Tool::allTimeZones() as $timezone)
                                                    <option value="{{$timezone['zone']}}" {{ Auth::user()->timezone == $timezone['zone'] ? 'selected': null }}> {{ $timezone['text'] }}</option>
                                                @endforeach
                                            </select>
                                            @error('timezone')
                                            <p><small class="text-danger">{{ $message }}</small></p>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="frequency_cycle"
                                                   class="form-label">{{__('locale.labels.frequency')}}</label>
                                            <select class="form-select" id="frequency_cycle" name="frequency_cycle">
                                                <option value="onetime" {{old('frequency_cycle')}}> {{__('locale.labels.one_time')}}</option>
                                                <option value="daily" {{old('frequency_cycle')}}> {{__('locale.labels.daily')}}</option>
                                                <option value="monthly" {{old('frequency_cycle')}}> {{__('locale.labels.monthly')}}</option>
                                                <option value="yearly" {{old('frequency_cycle')}}> {{__('locale.labels.yearly')}}</option>
                                                <option value="custom" {{old('frequency_cycle')}}> {{__('locale.labels.custom')}}</option>
                                            </select>
                                        </div>
                                        @error('frequency_cycle')
                                        <p><small class="text-danger">{{ $message }}</small></p>
                                        @enderror
                                    </div>

                                    <div class="col-sm-6 col-12 show-custom">
                                        <div class="mb-1">
                                            <label for="frequency_amount"
                                                   class="form-label">{{__('locale.plans.frequency_amount')}}</label>
                                            <input type="text"
                                                   id="frequency_amount"
                                                   class="form-control text-right @error('frequency_amount') is-invalid @enderror"
                                                   name="frequency_amount"
                                                   value="{{ old('frequency_amount') }}"
                                            >
                                            @error('frequency_amount')
                                            <p><small class="text-danger">{{ $message }}</small></p>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="col-sm-6 col-12 show-custom">
                                        <div class="mb-1">
                                            <label for="frequency_unit"
                                                   class="form-label">{{__('locale.plans.frequency_unit')}}</label>
                                            <select class="form-select" id="frequency_unit" name="frequency_unit">
                                                <option value="day" {{old('frequency_unit')}}> {{__('locale.labels.day')}}</option>
                                                <option value="week" {{old('frequency_unit')}}> {{__('locale.labels.week')}}</option>
                                                <option value="month" {{old('frequency_unit')}}> {{__('locale.labels.month')}}</option>
                                                <option value="year" {{old('frequency_unit')}}> {{__('locale.labels.year')}}</option>
                                            </select>
                                        </div>
                                        @error('frequency_unit')
                                        <p><small class="text-danger">{{ $message }}</small></p>
                                        @enderror
                                    </div>

                                    <div class="col-md-6 show-recurring">
                                        <div class="mb-1">
                                            <label for="recurring_date"
                                                   class="form-label"> {{ __('locale.labels.end_date') }}</label>
                                            <input type="text" id="recurring_date" name="recurring_date"
                                                   class="form-control schedule_date" placeholder="YYYY-MM-DD" />
                                            @error('recurring_date')
                                            <p><small class="text-danger">{{ $message }}</small></p>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="col-md-6 show-recurring">
                                        <div class="mb-1">
                                            <label for="recurring_time"
                                                   class="form-label">{{ __('locale.labels.end_time') }}</label>
                                            <input type="text" id="recurring_time"
                                                   class="form-control flatpickr-time text-start" name="recurring_time"
                                                   placeholder="HH:MM" />
                                            @error('recurring_time')
                                            <p><small class="text-danger">{{ $message }}</small></p>
                                            @enderror
                                        </div>
                                    </div>
                                </div>


                                <div class="row">
                                    <div class="col-12">
                                        <div class="mb-1">
                                            <div class="form-check form-check-inline">
                                                <input type="checkbox" id="advanced" name="advanced"
                                                       class="form-check-input advanced" value="true">
                                                <label class="form-check-label"
                                                       for="advanced">{{ __('locale.labels.advanced') }}</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="row advanced_div">
                                    <div class="col-12">
                                        <div class="mb-1">
                                            <div class="form-check form-check-inline">
                                                <input type="checkbox" id="send_copy" value="true" name="send_copy"
                                                       class="form-check-input">
                                                <label class="form-check-label"
                                                       for="send_copy">{{__('locale.campaigns.send_copy_via_email')}}</label>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="mb-1">
                                            <div class="form-check form-check-inline">
                                                <input type="checkbox" id="create_template" value="true"
                                                       name="create_template" class="form-check-input">
                                                <label class="form-check-label"
                                                       for="create_template">{{__('locale.campaigns.create_template_based_message')}}</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-12">
                                        <input type="hidden" value="viber" name="sms_type" id="sms_type">
                                        <input type="hidden" value="{{$plan_id}}" name="plan_id">
                                        <button type="submit" class="btn btn-primary mt-1 mb-1"><i
                                                    data-feather="send"></i> {{ __('locale.buttons.send') }}
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



    <!-- AI Message Preview Modal -->
    @include('customer.Campaigns._generateMessageModal')

@endsection

@section('vendor-script')
    <!-- vendor files -->
    <script src="{{ asset(mix('vendors/js/forms/select/select2.full.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/pickers/flatpickr/flatpickr.min.js')) }}"></script>
    <script src="{{ asset(mix('js/scripts/dom-rules.js')) }}"></script>
@endsection

@section('page-script')

    <script src="{{ asset(mix('js/scripts/sms-counter.js')) }}"></script>

    <script>
      $(document).ready(function() {

        $(".schedule_date").flatpickr({
          minDate: "today",
          dateFormat: "Y-m-d",
          defaultDate: "{{ date('Y-m-d') }}"
        });

        $(".flatpickr-time").flatpickr({
          enableTime: true,
          noCalendar: true,
          dateFormat: "H:i",
          defaultDate: "{{ \Carbon\Carbon::now()->setTimezone(config('app.timezone'))->format('H:i') }}"
        });

        $(".sender_id").on("click", function() {
          $("#sender_id").prop("disabled", !this.checked);
          $("#phone_number").prop("disabled", this.checked);
        });

        $(".phone_number").on("click", function() {
          $("#phone_number").prop("disabled", !this.checked);
          $("#sender_id").prop("disabled", this.checked);
        });


        let schedule = $(".schedule"),
          scheduleTime = $(".schedule_time");

        if (schedule.prop("checked") === true) {
          scheduleTime.show();
        } else {
          scheduleTime.hide();
        }

        $(".advanced_div").hide();

        schedule.change(function() {
          scheduleTime.fadeToggle();
        });

        $(".advanced").change(function() {
          $(".advanced_div").fadeToggle();
        });

        $.createDomRules({

          parentSelector: "body",
          scopeSelector: "form",
          showTargets: function(rule, $controller, condition, $targets) {
            $targets.fadeIn();
          },
          hideTargets: function(rule, $controller, condition, $targets) {
            $targets.fadeOut();
          },

          rules: [
            {
              controller: "#frequency_cycle",
              value: "custom",
              condition: "==",
              targets: ".show-custom"
            },
            {
              controller: "#frequency_cycle",
              value: "onetime",
              condition: "!=",
              targets: ".show-recurring"
            },
            {
              controller: ".message_type",
              value: "mms",
              condition: "==",
              targets: ".send-mms"
            }
          ]
        });


        $(".select2").each(function() {
          let $this = $(this);
          $this.wrap("<div class=\"position-relative\"></div>");
          $this.select2({
            // the following code is used to disable x-scrollbar when click in select input and
            // take 100% width in responsive also
            dropdownAutoWidth: true,
            width: "100%",
            dropdownParent: $this.parent()
          });
        });

        $("#sender_id_custom").select2({
          tags: true,  // Allows new values
          autofocus: true,
          maximumSelectionLength: 1,
          language: {
            maximumSelected: function() {
              return "{{ __('locale.labels.single_sender_id') }}";
            }
          }
        });


        let $remaining = $("#remaining"),
          $char_count = $("#charCount"),
          $encoding = $("#encoding"),
          $messages = $("#messages"),
          $get_msg = $("#message"),
          merge_state = $("#available_tag"),
          firstInvalid = $("form").find(".is-invalid").eq(0);

        if (firstInvalid.length) {
          $("body, html").stop(true, true).animate({
            "scrollTop": firstInvalid.offset().top - 200 + "px"
          }, 200);
        }

        function isArabic(text) {
          let pattern = /[\u0600-\u06FF\u0750-\u077F]/;
          return pattern.test(text);
        }

        function get_character() {
          if ($get_msg[0].value !== null) {

            let data = SmsCounter.count($get_msg[0].value.replace(/[\r\n]+/g, ""), true);

            if (data.encoding === "UTF16") {
              $("#sms_type").val("unicode").trigger("change");
              if (isArabic($(this).val())) {
                $get_msg.css("direction", "rtl");
              }
            } else {
              $("#sms_type").val("plain").trigger("change");
              $get_msg.css("direction", "ltr");
            }

            $char_count.text(data.length);
            $remaining.text(data.remaining + " / " + data.per_message);
            $messages.text(data.messages);
            $encoding.text(data.encoding);

          }
        }


        merge_state.on("change", function() {
          const caretPos = $get_msg[0].selectionStart;
          const textAreaTxt = $get_msg.val();
          let txtToAdd = this.value;
          if (txtToAdd) {
            txtToAdd = "{" + txtToAdd + "}";
          }

          $get_msg.val(textAreaTxt.substring(0, caretPos) + txtToAdd + textAreaTxt.substring(caretPos));
        });


        $("#sms_template").on("change", function() {

          let template_id = $(this).val();


          if (!template_id) {
            return;
          }

          $.ajax({
            url: "{{ url('templates/show-data')}}" + "/" + template_id,
            type: "POST",
            data: {
              _token: "{{csrf_token()}}"
            },
            cache: false,
            success: function(data) {
              if (data.status === "success") {
                const caretPos = $get_msg[0].selectionStart;
                const textAreaTxt = $get_msg.val();
                let txtToAdd = data.message;

                $get_msg.val(textAreaTxt.substring(0, caretPos) + txtToAdd + textAreaTxt.substring(caretPos)).val().length;

                get_character();

              } else {
                toastr["warning"](data.message, "{{ __('locale.labels.attention') }}", {
                  closeButton: true,
                  positionClass: "toast-top-right",
                  progressBar: true,
                  newestOnTop: true,
                  rtl: isRtl
                });
              }
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

        $get_msg.keyup(get_character);


        $("#contact_groups").on("change", function() {

          let contact_id = $(this).val();

          if (contact_id === 0) {
            return false;
          }

          $.ajax({
            url: "{{ url('tags/get-data') }}" + "/" + contact_id,
            type: "POST",
            data: {
              _token: "{{ csrf_token() }}"
            },
            cache: false,
            success: function(data) {
              if (data.status === "success") {
                merge_state.empty();
                $.each(data.contactFields, function(index, field) {
                  merge_state.append("<option value=\"" + field.tag +
                    "\">" + field.label + "</option>");
                });

                // Trigger select2 to update the UI
                merge_state.select2();

              } else {
                toastr["warning"](data.message,
                  "{{ __('locale.labels.attention') }}", {
                    closeButton: true,
                    positionClass: "toast-top-right",
                    progressBar: true,
                    newestOnTop: true,
                    rtl: isRtl
                  });
              }
            },
            error: function(reject) {
              if (reject.status === 422) {
                let errors = reject.responseJSON.errors;
                $.each(errors, function(key, value) {
                  toastr["warning"](value[0],
                    "{{ __('locale.labels.attention') }}", {
                      closeButton: true,
                      positionClass: "toast-top-right",
                      progressBar: true,
                      newestOnTop: true,
                      rtl: isRtl
                    });
                });
              } else {

              }
            }
          });
        });


        $("#generateAiMessage").on("click", function() {
          const goal = $("#aiGoal").val().trim();
          const tone = $("#aiTone").val();
          const audience = $("#aiAudience").val().trim();
          const loader = $("#aiLoader");
          const generateBtn = $("#generateAiMessage");

          if (!goal || !audience) {
            toastr["warning"]("{{ __('locale.ai.fill_out_fields') }}", "{{ __('locale.labels.attention') }}", {
              closeButton: true,
              positionClass: "toast-top-right",
              progressBar: true,
              newestOnTop: true,
              rtl: isRtl
            });
            return;
          }

          loader.removeClass("d-none");
          generateBtn.prop("disabled", true);

          $.ajax({
            url: "{{ route('customer.openai.generate') }}",
            type: "POST",
            data: {
              _token: "{{ csrf_token() }}",
              goal: goal,
              tone: tone,
              audience: audience
            },
            cache: false,
            success: function(data) {
              if (data.success && data.message) {
                $("#message").val(data.message);
                bootstrap.Modal.getInstance(document.getElementById("aiMessageModal")).hide();
              } else {
                toastr["warning"]("{{ __('locale.ai.error') }}", "{{ __('locale.labels.attention') }}", {
                  closeButton: true,
                  positionClass: "toast-top-right",
                  progressBar: true,
                  newestOnTop: true,
                  rtl: isRtl
                });
              }
            },
            error: function(reject) {
              if (reject.status === 422) {
                let errors = reject.responseJSON.errors;
                $.each(errors, function(key, value) {
                  toastr["warning"](value[0], "{{ __('locale.labels.attention') }}", {
                    closeButton: true,
                    positionClass: "toast-top-right",
                    progressBar: true,
                    newestOnTop: true,
                    rtl: isRtl
                  });
                });
              } else {
                toastr["warning"](reject.responseJSON?.message || "Unexpected error", "{{ __('locale.labels.attention') }}", {
                  closeButton: true,
                  positionClass: "toast-top-right",
                  progressBar: true,
                  newestOnTop: true,
                  rtl: isRtl
                });
              }
            },
            complete: function() {
              loader.addClass("d-none");
              generateBtn.prop("disabled", false);
            }
          });
        });


      });
    </script>
@endsection
