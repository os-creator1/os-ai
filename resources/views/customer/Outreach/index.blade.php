@extends('layouts/contentLayoutMaster')

@section('title', __('locale.menu.Outreach'))

@section('vendor-style')
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/pickers/flatpickr/flatpickr.min.css')) }}">
@endsection

@section('page-style')
    <link rel="stylesheet" href="{{ asset(mix('css/base/plugins/ui/iphone.css')) }}">
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
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h4 class="mb-0">{{ __('locale.menu.Outreach') }}</h4>
            <x-button variant="outline" size="sm" :href="route('customer.outreach.campaigns')" icon="list">
                {{ __('locale.menu.Campaigns') }}
            </x-button>
        </div>
    </div>

    <ul class="nav nav-pills mb-2" id="outreach-channel-tabs" role="tablist">
        @if($canSms)
            <li class="nav-item">
                <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#outreach-channel-sms" type="button" role="tab">SMS</button>
            </li>
        @endif
        @if($canMms)
            <li class="nav-item">
                <button class="nav-link {{ $canSms ? '' : 'active' }}" data-bs-toggle="pill" data-bs-target="#outreach-channel-mms" type="button" role="tab">MMS</button>
            </li>
        @endif
    </ul>

    <div class="tab-content">
        @if($canSms)
            <div class="tab-pane fade show active" id="outreach-channel-sms" role="tabpanel">
                <ul class="nav nav-tabs mb-2">
                    @if($canSmsQuickSend)
                        <li class="nav-item">
                            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#sms-quick" type="button">{{ __('locale.menu.Quick Send') }}</button>
                        </li>
                    @endif
                    @if($canSmsCampaignBuilder)
                        <li class="nav-item">
                            <button class="nav-link {{ $canSmsQuickSend ? '' : 'active' }}" data-bs-toggle="tab" data-bs-target="#sms-campaign" type="button">{{ __('locale.menu.Campaign Builder') }}</button>
                        </li>
                    @endif
                </ul>
                <div class="tab-content">
                    @if($canSmsQuickSend)
                        <div class="tab-pane fade show active" id="sms-quick">
                            @include('customer.Outreach._smsQuickSend')
                        </div>
                    @endif
                    @if($canSmsCampaignBuilder)
                        <div class="tab-pane fade {{ $canSmsQuickSend ? '' : 'show active' }}" id="sms-campaign">
                            @include('customer.Outreach._smsCampaign')
                        </div>
                    @endif
                </div>
            </div>
        @endif

        @if($canMms)
            <div class="tab-pane fade {{ $canSms ? '' : 'show active' }}" id="outreach-channel-mms" role="tabpanel">
                <ul class="nav nav-tabs mb-2">
                    @if($canMmsQuickSend)
                        <li class="nav-item">
                            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#mms-quick" type="button">{{ __('locale.menu.Quick Send') }}</button>
                        </li>
                    @endif
                    @if($canMmsCampaignBuilder)
                        <li class="nav-item">
                            <button class="nav-link {{ $canMmsQuickSend ? '' : 'active' }}" data-bs-toggle="tab" data-bs-target="#mms-campaign" type="button">{{ __('locale.menu.Campaign Builder') }}</button>
                        </li>
                    @endif
                </ul>
                <div class="tab-content">
                    @if($canMmsQuickSend)
                        <div class="tab-pane fade show active" id="mms-quick">
                            @include('customer.Outreach._mmsQuickSend')
                        </div>
                    @endif
                    @if($canMmsCampaignBuilder)
                        <div class="tab-pane fade {{ $canMmsQuickSend ? '' : 'show active' }}" id="mms-campaign">
                            @include('customer.Outreach._mmsCampaign')
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>

    @include('customer.Campaigns._generateMessageModal')
    @include('customer.Campaigns._mobilePreviewModal')
    @include('customer.Campaigns._messagePreviewModal')
@endsection

@section('vendor-script')
    <script src="{{ asset(mix('vendors/js/forms/select/select2.full.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/pickers/flatpickr/flatpickr.min.js')) }}"></script>
    <script src="{{ asset(mix('js/scripts/dom-rules.js')) }}"></script>
@endsection

@section('page-script')
    <script src="{{ asset(mix('js/scripts/sms-counter.js')) }}"></script>
    <script>
      $(document).ready(function () {

        let $activeForm = null;

        function isArabic(text) {
          return /[؀-ۿݐ-ݿ]/.test(text);
        }

        $('.select2').each(function () {
          let $this = $(this),
            placeholder = "{{ __('locale.labels.select_one') }}",
            allowClear = false;
          $this.wrap('<div class="position-relative"></div>');
          if ($this.prop('multiple')) {
            placeholder = "{{ __('locale.labels.select_one_or_more') }}";
            allowClear = true;
          }
          $this.select2({
            dropdownAutoWidth: true,
            width: '100%',
            dropdownParent: $this.parent(),
            placeholder: placeholder,
            allowClear: allowClear
          });
        });

        $('.input_sender_id').select2({
          tags: true,
          autofocus: true,
          maximumSelectionLength: 1,
          language: {
            maximumSelected: function () {
              return "{{ __('locale.labels.single_sender_id') }}";
            }
          }
        });

        $('.outreach-form [data-role="schedule-date"]').flatpickr({
          minDate: 'today',
          dateFormat: 'Y-m-d',
          defaultDate: "{{ date('Y-m-d') }}"
        });

        $('.outreach-form [data-role="schedule-flatpickr-time"]').flatpickr({
          enableTime: true,
          noCalendar: true,
          dateFormat: 'H:i',
          defaultDate: "{{ \Carbon\Carbon::now()->setTimezone(config('app.timezone'))->format('H:i') }}"
        });

        $('[data-role="advanced-fields"]').hide();

        $.createDomRules({
          parentSelector: 'body',
          scopeSelector: '.outreach-form',
          showTargets: function (rule, $controller, condition, $targets) {
            $targets.fadeIn();
          },
          hideTargets: function (rule, $controller, condition, $targets) {
            $targets.fadeOut();
          },
          rules: [
            { controller: '[data-role="frequency-cycle"]', value: 'custom', condition: '==', targets: '[data-role="show-custom"]' },
            { controller: '[data-role="frequency-cycle"]', value: 'onetime', condition: '!=', targets: '[data-role="show-recurring"]' }
          ]
        });

        $('.outreach-form').each(function () {
          const $form = $(this);

          const $senderRadio = $form.find('[data-role="originator-sender-id"]');
          const $phoneRadio = $form.find('[data-role="originator-phone-number"]');
          const $senderSelect = $form.find('[data-role="sender-id"], [data-role="sender-id-custom"]');
          const $phoneSelect = $form.find('[data-role="phone-number"]');

          $senderRadio.on('click', function () {
            $senderSelect.prop('disabled', !this.checked);
            $phoneSelect.prop('disabled', this.checked);
          });
          $phoneRadio.on('click', function () {
            $phoneSelect.prop('disabled', !this.checked);
            $senderSelect.prop('disabled', this.checked);
          });

          const $scheduleToggle = $form.find('[data-role="schedule-toggle"]');
          const $scheduleTime = $form.find('[data-role="schedule-time"]');
          if ($scheduleToggle.length) {
            $scheduleTime.toggle($scheduleToggle.prop('checked') === true);
            $scheduleToggle.on('change', function () {
              $scheduleTime.fadeToggle();
            });
          }

          $form.find('[data-role="advanced-toggle"]').on('change', function () {
            $form.find('[data-role="advanced-fields"]').fadeToggle();
          });

          const $msg = $form.find('[data-role="message"]');
          const $remaining = $form.find('[data-role="remaining"]');
          const $charCount = $form.find('[data-role="char-count"]');
          const $encoding = $form.find('[data-role="encoding"]');
          const $messagesCount = $form.find('[data-role="messages-count"]');
          const $smsType = $form.find('[data-role="sms-type"]');

          function getCharacter() {
            if ($msg.length === 0 || $msg[0].value === null) {
              return;
            }
            let msg = $msg[0].value.replace(/[\r\n]+/g, '');
            let data = SmsCounter.count(msg, true);

            if ($smsType.length) {
              if (data.encoding === 'UTF16') {
                $smsType.val('unicode').trigger('change');
                if (isArabic(msg)) {
                  $msg.css('direction', 'rtl');
                }
              } else {
                $smsType.val('plain').trigger('change');
                $msg.css('direction', 'ltr');
              }
            }

            $charCount.text(data.length);
            $remaining.text(data.remaining + ' / ' + data.per_message);
            $messagesCount.text(data.messages);
            $encoding.text(data.encoding);
          }

          getCharacter();
          $msg.on('change keyup paste', getCharacter);

          const $recipients = $form.find('[data-role="recipients"]');
          const $recipientCount = $form.find('[data-role="recipient-count"]');

          function getDelimiter() {
            return $form.find('input[name="delimiter"]:checked').val();
          }

          function getRecipientsCount() {
            let value = $recipients.length ? $recipients[0].value.trim() : '';
            let total = 0;
            if (value) {
              let delimiter = getDelimiter();
              if (delimiter === ';') {
                total = value.split(';').length;
              } else if (delimiter === ',') {
                total = value.split(',').length;
              } else if (delimiter === 'new_line') {
                total = value.split('\n').length;
              } else {
                total = 0;
              }
            }
            $recipientCount.text(total);
            return total;
          }

          if ($recipients.length) {
            getRecipientsCount();
            $recipients.on('change keyup paste', getRecipientsCount);
            $form.find('input[name="delimiter"]').on('change', getRecipientsCount);
          }

          const $mergeState = $form.find('[data-role="available-tag"]');
          $mergeState.on('change', function () {
            const caretPos = $msg[0].selectionStart;
            const textAreaTxt = $msg.val();
            let txtToAdd = this.value;
            if (txtToAdd) {
              txtToAdd = '{' + txtToAdd + '}';
            }
            $msg.val(textAreaTxt.substring(0, caretPos) + txtToAdd + textAreaTxt.substring(caretPos));
          });

          $form.find('[data-role="contact-groups"]').on('change', function () {
            const contactId = $(this).val();
            if (!contactId) {
              return false;
            }

            $.ajax({
              url: "{{ url('tags/get-data') }}" + '/' + contactId,
              type: 'POST',
              data: { _token: "{{ csrf_token() }}" },
              cache: false,
              success: function (data) {
                if (data.status === 'success') {
                  $mergeState.empty();
                  $.each(data.contactFields, function (index, field) {
                    $mergeState.append('<option value="' + field.tag + '">' + field.label + '</option>');
                  });
                  $mergeState.select2();
                }
              }
            });
          });

          $form.find('[data-role="sms-template"]').on('change', function () {
            const templateId = $(this).val();
            if (!templateId) {
              return;
            }

            $.ajax({
              url: "{{ url('templates/show-data') }}" + '/' + templateId,
              type: 'POST',
              data: { _token: "{{ csrf_token() }}" },
              cache: false,
              success: function (data) {
                if (data.status === 'success') {
                  const caretPos = $msg[0].selectionStart;
                  const textAreaTxt = $msg.val();
                  $form.find('[data-role="dlt-template-id"]').val(data.dlt_template_id);
                  $msg.val(textAreaTxt.substring(0, caretPos) + data.message + textAreaTxt.substring(caretPos));
                  getCharacter();
                } else {
                  toastr['warning'](data.message, "{{ __('locale.labels.attention') }}", { closeButton: true, positionClass: 'toast-top-right', progressBar: true, newestOnTop: true, rtl: isRtl });
                }
              }
            });
          });

          $form.find('[data-role="ai-generate-trigger"]').on('click', function () {
            $activeForm = $form;
          });

          $form.find('[data-role="phone-preview-trigger"]').on('click', function () {
            $activeForm = $form;
            const msg = $msg.val();
            $('#senderid').html($form.find('[data-role="sender-id"]').val());
            $('#messageto').html(msg);
            $('#phonePreview').modal('show');
          });

          $form.find('[data-role="send-preview-trigger"]').on('click', function () {
            $activeForm = $form;

            let msgData = SmsCounter.count($msg.val().replace(/[\r\n]+/g, ''), true);
            $('#msgLength').html(msgData.length);
            $('#msgCost').html(msgData.messages);
            $('#msg').html($msg.val());

            const $contactGroups = $form.find('[data-role="contact-groups"]');
            const $name = $form.find('input[name="name"]');

            if ($contactGroups.length) {
              const groupIds = $contactGroups.val();
              const nameVal = $name.length ? $name.val() : 'ok';

              if (!groupIds || groupIds.length < 1 || !nameVal || nameVal.length < 1 || $msg.val().length < 1) {
                toastr['warning']("{{ __('locale.auth.insert_required_fields') }}", "{{ __('locale.labels.attention') }}", { closeButton: true, positionClass: 'toast-top-right', progressBar: true, newestOnTop: true, rtl: isRtl });
                return;
              }

              const $msgRecipients = $('#msgRecepients');
              $msgRecipients.html($msgRecipients.data('loading-text'));

              $.ajax({
                url: "{{ route('customer.contacts.count_contact') }}",
                type: 'POST',
                data: { _token: "{{ csrf_token() }}", contact_group_ids: groupIds },
                cache: false,
                success: function (data) {
                  $msgRecipients.html(Number(data));
                }
              });
            } else {
              if ($recipients.length && (getRecipientsCount() < 1 || $msg.val().length < 1)) {
                toastr['warning']("{{ __('locale.auth.insert_required_fields') }}", "{{ __('locale.labels.attention') }}", { closeButton: true, positionClass: 'toast-top-right', progressBar: true, newestOnTop: true, rtl: isRtl });
                return;
              }
              $('#msgRecepients').html(getRecipientsCount());
            }

            $('#messagePreview').modal('show');
          });
        });

        $('#generateAiMessage').on('click', function () {
          if (!$activeForm) {
            return;
          }
          const goal = $('#aiGoal').val().trim();
          const tone = $('#aiTone').val();
          const audience = $('#aiAudience').val().trim();
          const loader = $activeForm.find('.outreach-ai-loader');
          const generateBtn = $(this);

          if (!goal || !audience) {
            toastr['warning']("{{ __('locale.ai.fill_out_fields') }}", "{{ __('locale.labels.attention') }}", { closeButton: true, positionClass: 'toast-top-right', progressBar: true, newestOnTop: true, rtl: isRtl });
            return;
          }

          loader.removeClass('d-none').addClass('d-flex');
          generateBtn.prop('disabled', true);

          $.ajax({
            url: "{{ route('customer.openai.generate') }}",
            type: 'POST',
            data: { _token: "{{ csrf_token() }}", goal: goal, tone: tone, audience: audience },
            cache: false,
            success: function (data) {
              if (data.success && data.message) {
                $activeForm.find('[data-role="message"]').val(data.message).trigger('change');
                bootstrap.Modal.getInstance(document.getElementById('aiMessageModal')).hide();
              } else {
                toastr['warning']("{{ __('locale.ai.error') }}", "{{ __('locale.labels.attention') }}", { closeButton: true, positionClass: 'toast-top-right', progressBar: true, newestOnTop: true, rtl: isRtl });
              }
            },
            complete: function () {
              loader.removeClass('d-flex').addClass('d-none');
              generateBtn.prop('disabled', false);
            }
          });
        });

        $('#finalSend').on('click', function (e) {
          e.preventDefault();
          if (!$activeForm) {
            return;
          }
          $('#finalSend').attr('disabled', true);
          $activeForm[0].submit();
        });

        setInterval(function () {
          let date = new Date();
          let hours = date.getHours() < 10 ? '0' + date.getHours() : date.getHours();
          let minutes = date.getMinutes() < 10 ? '0' + date.getMinutes() : date.getMinutes();
          let seconds = date.getSeconds() < 10 ? '0' + date.getSeconds() : date.getSeconds();
          $('.top-section-time').html(hours + ':' + minutes + ':' + seconds);
        }, 500);
      });
    </script>
@endsection
