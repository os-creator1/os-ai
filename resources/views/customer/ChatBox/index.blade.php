@extends('layouts/contentLayoutMaster')

@section('title', __('locale.menu.Chat Box'))


@section('vendor-style')
    <!-- vendor css files -->
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
@endsection


@section('page-style')
    <!-- Page css files -->
    <link rel="stylesheet" href="{{ asset(mix('css/base/pages/app-chat.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('css/base/pages/app-chat-list.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/extensions/sweetalert2.min.css')) }}">

    <style>
        /* For screens smaller than 576px */
        @media (max-width: 576px) {
            /* Set the max-width of the image or video to 100% to make it responsive */
            img, video {
                max-width: 100%;
                height: auto;
            }
        }

        /* For screens between 576px and 768px */
        @media (min-width: 576px) and (max-width: 768px) {
            img, video {
                max-width: 100%;
                height: auto;
            }
        }

        /* For screens between 768px and 992px */
        @media (min-width: 768px) and (max-width: 992px) {
            img, video {
                max-width: 100%;
                height: auto;
            }
        }

        /* For screens between 992px and 1200px */
        @media (min-width: 992px) and (max-width: 1200px) {
            img, video {
                max-width: 100%;
                height: auto;
            }
        }

        /* For screens larger than 1200px */
        @media (min-width: 1200px) {
            img, video {
                max-width: 100%;
                height: auto;
            }
        }

        /* Style the textarea to look like an input field */
        textarea.message {
            resize: none; /* Disable resizing */
            overflow: hidden; /* Hide the scrollbar */
            white-space: nowrap; /* Prevent wrapping to the next line */
            height: 38px; /* Set a fixed height (same as most input fields) */
            line-height: 1.5; /* Adjust line height for vertical alignment */
            padding: 8px 12px; /* Match padding of input fields */
            border: 1px solid var(--color-input-border); /* Match border of input fields */
            border-radius: 4px; /* Match border radius of input fields */
            font-family: inherit; /* Use the same font as input fields */
            font-size: 14px; /* Match font size of input fields */
        }

        /* Optional: Add focus styling to match input fields */
        textarea.message:focus {
            border-color: var(--color-focus-border); /* Match focus border color of input fields */
            outline: 0; /* Remove default outline */
            box-shadow: 0 0 0 0.2rem var(--focus-ring-color); /* Match focus shadow of input fields */
        }

        /*
         * Conversations — three panes: the conversation list (content sidebar),
         * the person's activity timeline with the composer under it, and the
         * contact panel. Tokens only; the chat theme keeps drawing bubbles.
         */
        .chat-app-window .active-chat {
            position: relative;
        }

        .conversation-body {
            display: flex;
            height: calc(100% - 65px);
        }

        .conversation-main {
            display: flex;
            flex: 1 1 auto;
            flex-direction: column;
            min-width: 0;
        }

        .chat-app-window .conversation-main .user-chats {
            flex: 1 1 auto;
            height: auto;
            min-height: 0;
        }

        .conversation-main .chat-app-form {
            flex: 0 0 auto;
        }

        .conversation-header-text {
            min-width: 0;
        }

        .conversation-channel-chip {
            border: 1px solid var(--color-border-subtle);
            border-radius: var(--radius-full);
            color: var(--color-text-secondary);
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.04em;
            margin-right: var(--space-2);
            padding: var(--space-1) var(--space-2);
        }

        .timeline-divider {
            align-items: center;
            clear: both;
            color: var(--color-text-muted);
            display: flex;
            font-size: 0.75rem;
            gap: var(--space-3);
            margin: var(--space-4) 0 var(--space-3);
        }

        .timeline-divider::before,
        .timeline-divider::after {
            border-top: 1px solid var(--color-border-subtle);
            content: "";
            flex: 1 1 auto;
        }

        .timeline-message {
            clear: both;
        }

        .timeline-message-body {
            white-space: pre-wrap;
            word-break: break-word;
        }

        .chat-app-window .chats .timeline-message .timeline-message-meta {
            font-size: 0.75rem;
            margin-bottom: 0;
            opacity: 0.85;
        }

        .timeline-media img,
        .timeline-media video {
            border-radius: var(--radius-md);
            max-height: 240px;
            max-width: 240px;
        }

        .timeline-activity {
            align-items: center;
            background-color: var(--color-surface);
            border: 1px solid var(--color-border-subtle);
            border-radius: var(--radius-full);
            clear: both;
            color: var(--color-text-secondary);
            display: flex;
            flex-wrap: wrap;
            font-size: 0.8125rem;
            gap: var(--space-2);
            justify-content: center;
            margin: var(--space-2) auto var(--space-3);
            max-width: 85%;
            padding: var(--space-1) var(--space-3);
            width: fit-content;
        }

        .timeline-activity-warning {
            background-color: var(--color-status-warning-soft-bg);
            border-color: var(--color-status-warning-border);
            color: var(--color-status-warning-text);
        }

        .timeline-activity-detail,
        .timeline-activity-time {
            color: var(--color-text-muted);
        }

        .timeline-empty {
            margin-top: var(--space-8);
            text-align: center;
        }

        .conversation-context {
            background-color: var(--color-surface);
            border-left: 1px solid var(--color-border-subtle);
            flex: 0 0 300px;
            overflow-y: auto;
            padding: var(--space-5) var(--space-4);
            width: 300px;
        }

        .conversation-context-person {
            border-bottom: 1px solid var(--color-border-subtle);
            margin-bottom: var(--space-4);
            padding-bottom: var(--space-4);
            text-align: center;
        }

        .conversation-context-avatar {
            font-size: 1.1rem;
            height: 56px;
            width: 56px;
        }

        .conversation-context-facts {
            display: grid;
            font-size: 0.875rem;
            gap: var(--space-2) var(--space-3);
            grid-template-columns: auto 1fr;
            margin-bottom: var(--space-4);
        }

        .conversation-context-facts dt {
            color: var(--color-text-muted);
            font-weight: 400;
        }

        .conversation-context-facts dd {
            color: var(--color-text-primary);
            margin: 0;
            word-break: break-word;
        }

        .conversation-context-heading {
            color: var(--color-text-muted);
            font-size: 0.75rem;
            letter-spacing: 0.04em;
            margin-bottom: var(--space-2);
            text-transform: uppercase;
        }

        @media (max-width: 1199.98px) {
            .conversation-context {
                bottom: 0;
                box-shadow: var(--shadow-lg);
                display: none;
                position: absolute;
                right: 0;
                top: 65px;
                width: min(320px, 100%);
                z-index: 6;
            }

            .conversation-context.show {
                display: block;
            }
        }

    </style>

@endsection

@section('content-sidebar')
    @include('customer.ChatBox._sidebar')
@endsection


@section('content')
    <div class="body-content-overlay"></div>
    <!-- Main chat area -->
    <section class="chat-app-window">
        <!-- To load Conversation -->
        <div class="start-chat-area">
            <div class="mb-1 start-chat-icon">
                <x-ds-icon name="message-square" />
            </div>
            <h4 class="sidebar-toggle start-chat-text d-block d-md-none">
                {{ __('locale.labels.new_conversion') }}
            </h4>
            <h4 class="sidebar-toggle start-chat-text d-none d-md-block">
                <a href="{{ route('customer.workspaces.businesses.conversations.new', [$workspaceUid, $businessUid]) }}"
                   class="text-dark">{{ __('locale.labels.new_conversion') }}</a>
            </h4>
        </div>
        <!--/ To load Conversation -->

        <!-- Active Chat -->
        <div class="active-chat d-none">
            <!-- Chat Header -->
            <div class="chat-navbar">
                <header class="chat-header">
                    <div class="d-flex align-items-center conversation-header-text">
                        <div class="sidebar-toggle d-block d-lg-none me-1">
                            <x-ds-icon name="menu" class="font-medium-5" />
                        </div>
                        <h6 class="mb-0 text-truncate" data-role="conversation-title"></h6>
                        <span class="add-to-pin"> </span>
                    </div>
                    <div class="d-flex align-items-center">

                        <x-tooltip text="{{ __('locale.labels.block') }}" placement="top" class="add-to-blacklist">
                            <x-ds-icon name="shield" class="cursor-pointer font-medium-2 mx-1 text-primary" />
                        </x-tooltip>

                        <x-tooltip text="{{ __('locale.buttons.delete') }}" placement="top" class="remove-btn">
                            <x-ds-icon name="trash" class="cursor-pointer font-medium-2 text-danger" />
                        </x-tooltip>

                        {{-- Below xl the contact panel slides over the timeline; this opens it. --}}
                        <span class="d-xl-none">
                            <x-button variant="ghost" size="sm" class="conversation-context-toggle ms-1" icon="panel-right"
                                      aria-label="Contact details" aria-controls="conversation-context" aria-expanded="false" />
                        </span>

                    </div>
                </header>
            </div>
            <!--/ Chat Header -->

            <div class="conversation-body">
                <div class="conversation-main">
                    <!-- The person's activity timeline: messages and what happened around them -->
                    <div class="user-chats">
                        <div class="chats">
                            <div class="chat_history" data-role="conversation-timeline"></div>
                        </div>
                    </div>
                    <!--/ The person's activity timeline -->

                    <!-- Submit Chat form -->
                    <form class="chat-app-form" action="javascript:void(0);" onsubmit="enter_chat();" autocomplete="off">
                        <input type="hidden" value="" name="chat_id" class="chat_id">

                        {{-- Replies go out as SMS — the only channel this conversation has today. --}}
                        <span class="conversation-channel-chip" data-role="composer-channel">SMS</span>

                        <div class="input-group input-group-merge me-1 form-send-message">
                            <textarea type="text" id="message" class="form-control message" placeholder="..." autocomplete="off"></textarea>

                            <span class="input-group-text">
                                  <label for="media_image" class="attachment-icon form-label mb-0 position-relative">
                                    <x-ds-icon name="image" id="mms-icon" class="cursor-pointer text-secondary" />
                                    <input type="file" id="media_image" name="media_image" accept="image/*,video/*" hidden />
                                  </label>
                                </span>

                        </div>


                        <div class=" me-1">
                            <select class="form-select select2" id="sms_template" data-placeholder="Select Template">
                                <option value="0">Select Template</option>
                                @foreach($templates as $template)
                                    <option value="{{$template->id}}">{{ $template->name }}</option>
                                @endforeach
                            </select>
                        </div>


                        <x-button variant="primary" class="send" onclick="enter_chat();">
                            <x-ds-icon name="send" class="d-lg-none" />
                            <span class="d-none d-lg-block">{{ __('locale.buttons.send') }}</span>
                        </x-button>
                    </form>
                    <!--/ Submit Chat form -->
                </div>

                <!-- Contact panel -->
                <aside id="conversation-context" class="conversation-context" data-role="conversation-context" aria-label="Contact details"></aside>
                <!--/ Contact panel -->
            </div>
        </div>
        <!--/ Active Chat -->
    </section>
    <!--/ Main chat area -->
@endsection

@section('vendor-script')
    <!-- vendor files -->
    <script src="{{ asset(mix('vendors/js/forms/select/select2.full.min.js')) }}"></script>
@endsection


@section('page-script')
    <!-- Page js files -->
    <script src="{{ asset(mix('js/scripts/pages/chat.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/extensions/sweetalert2.all.min.js')) }}"></script>
    @if(config('broadcasting.connections.pusher.app_id'))
        <script src="{{ asset(mix('js/scripts/echo.js')) }}"></script>
    @endif

    <script>
      // Customer Experience Redesign Slice 2B — every endpoint is rendered by
      // the server from the Business-scoped route family, so the browser never
      // assembles a /chat-box/* path of its own. `__UID__` is replaced with
      // the conversation's public uid; no numeric id is ever used.
      const conversationRoutes = {
        timeline: "{{ route('customer.workspaces.businesses.conversations.timeline', [$workspaceUid, $businessUid, '__UID__']) }}",
        notification: "{{ route('customer.workspaces.businesses.conversations.notification', [$workspaceUid, $businessUid, '__UID__']) }}",
        reply: "{{ route('customer.workspaces.businesses.conversations.reply', [$workspaceUid, $businessUid, '__UID__']) }}",
        delete: "{{ route('customer.workspaces.businesses.conversations.delete', [$workspaceUid, $businessUid, '__UID__']) }}",
        block: "{{ route('customer.workspaces.businesses.conversations.block', [$workspaceUid, $businessUid, '__UID__']) }}",
        pin: "{{ route('customer.workspaces.businesses.conversations.pin', [$workspaceUid, $businessUid, '__UID__']) }}",
        load: "{{ route('customer.workspaces.businesses.conversations.load', [$workspaceUid, $businessUid]) }}",
      };
      const conversationUrl = (name, uid) => conversationRoutes[name].replace('__UID__', encodeURIComponent(uid));

      // autoscroll to bottom of Chat area
      let chatContainer = $(".user-chats"),
        details,
        chatHistory = $(".chat_history");

      // Basic Select2 select
      $(".select2").each(function() {
        let $this = $(this);
        $this.wrap("<div class=\"position-relative\"></div>");
        $this.select2({
          // the following code is used to disable x-scrollbar when click in select input and
          // take 100% width in responsive also
          dropdownAutoWidth: true,
          width: "100%",
          dropdownParent: $this.parent(),
          placeholder: $this.data("placeholder")
        });
      });


      $("#sms_template").on("change", function() {

        let template_id = $(this).val(),
          $get_msg = $("#message");

        if (template_id === "0") {
          return false;
        }

        $.ajax({
          // B1's Business-scoped template fetch: a template belonging to
          // another Business is simply not found there.
          url: "{{ route('customer.workspaces.businesses.outreach.templates.show_data', [$workspaceUid, $businessUid, '__ID__']) }}".replace('__ID__', encodeURIComponent(template_id)),
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


      /**
       * Load the person's activity timeline and contact panel for one
       * conversation. Both arrive rendered and escaped by the server; nothing
       * here builds markup out of a message. A response for a conversation the
       * viewer has since left is ignored.
       */
      function loadTimeline(chat_id) {
        return $.post(
          conversationUrl('timeline', chat_id),
          { _token: "{{ csrf_token() }}" }
        )
          .done(function(response) {
            if ($(".chat_id").val() !== String(chat_id)) {
              return;
            }

            let addToPin = $(".add-to-pin");

            if (response.pinned === 1) {
              addToPin.attr("title", '{{ __('locale.labels.unpin') }}');

              addToPin.tooltip("dispose").tooltip();


              // Swap the icon to 'delete'. A template literal, not a quoted
              // string: the rendered SVG spans several lines, and a line break
              // inside '...' is a syntax error that stops this whole script
              // (search, load more, sending) from running.
              addToPin.find("svg").remove();  // Remove the old icon element
              addToPin.append(`<x-ds-icon name="delete" class="cursor-pointer font-medium-2 mx-1 text-danger" />`);

              // Re-initialize Feather icons to update
              feather.replace();
            } else {
              addToPin.attr("title", '{{ __('locale.labels.pin') }}');

              addToPin.tooltip("dispose").tooltip();

              // Swap the icon to 'edit-2'
              addToPin.find("svg").remove();  // Remove the old icon element
              addToPin.append(`<x-ds-icon name="edit-2" class="cursor-pointer font-medium-2 mx-1 text-info" />`);

              // Re-initialize Feather icons to update
              feather.replace();
            }

            $("[data-role=conversation-title]").text(response.title);
            $(".chat_history").html(response.timeline);
            $("#conversation-context").html(response.context);

            // Show the active chat area
            $(".start-chat-area").addClass("d-none");  // Hide the initial "Start chat" screen
            $(".active-chat").removeClass("d-none");   // Show the chat area
            $(".counter").hide();

            const $chats = $(".user-chats");
            $chats.animate({ scrollTop: $chats[0].scrollHeight }, 400);  // Newest activity at the bottom
          })
          .fail(function(xhr, status, error) {
            console.error("Error loading conversation:", error);
          });
      }


      $(document).ready(function() {
        // Use event delegation to bind click events to dynamically loaded users
        $("#users-list").on("click", ".chat-users-list li, .chat-users-list-pinned li", function() {
          // Destroy + recreate textarea to prevent browser restoring value
          let msg = $("#message");
          msg.replaceWith(msg.clone().val(""));
          $("#media_image").val("");

          // HARD RESET composer immediately on chat switch
          $("#message").val("");
          $("#media_image").val("");

          $(".chat_history").empty();  // Clear the previous person's timeline
          $("#conversation-context").empty();

          $(this).find(".notification_count").remove();

          const chat_id = $(this).data("id");  // Get the clicked chat ID

          $(".chat-users-list li, .chat-users-list-pinned li").removeClass("active");
          $(this).addClass("active");

          $(".chat_id").val(chat_id);

          loadTimeline(chat_id).done(function() {
            setTimeout(() => {
              $("#message").val("");
              $("#media_image").val("");
            }, 50);
          });
        });

        // The contact panel slides over the timeline on narrower screens.
        $(".conversation-context-toggle").on("click", function() {
          const $panel = $("#conversation-context");
          const open = !$panel.hasClass("show");

          $panel.toggleClass("show", open);
          $(this).attr("aria-expanded", open ? "true" : "false");
        });
      });


      function safeMessageParagraph(value) {
        return $("<p></p>").text(value);
      }


      // RFC-005 Milestone 5 §6.1 — a chatBoxId-keyed map, never a single
      // flat/session-global token: two different open conversations
      // never share or collide on one token, and a fresh compose always
      // gets a fresh token unless the server explicitly asked this exact
      // conversation to 'retain' its existing one.
      var pendingConversationTokens = pendingConversationTokens || {};

      function m5UuidV4() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
          var r = Math.random() * 16 | 0, v = c === 'x' ? r : (r & 0x3 | 0x8);
          return v.toString(16);
        });
      }

      function enter_chat() {
        let message = $(".message"),
          chatBoxId = $(".chat_id").val(),
          messageValue = message.val(),
          mediaImage = $("#media_image")[0].files[0]; // get the selected file

        $(".send").attr("disabled", true);

        if (!pendingConversationTokens[chatBoxId]) {
          pendingConversationTokens[chatBoxId] = m5UuidV4();
        }

        // Use FormData to handle file uploads
        let formData = new FormData();
        formData.append("message", messageValue);
        formData.append("_token", "{{ csrf_token() }}");
        formData.append("idempotency_token", pendingConversationTokens[chatBoxId]);

        if (mediaImage) {
          formData.append("media_image", mediaImage);
        }

        $.ajax({
          url: conversationUrl('reply', chatBoxId),
          type: "POST",
          data: formData,
          processData: false, // prevent jQuery from converting to query string
          contentType: false, // allow multipart/form-data
          success: function(response) {
            $(".send").attr("disabled", false);


            console.log(response);

            // RFC-005 Milestone 5 §6.1 — explicit m5_token_action drives
            // clear/retain, applied uniformly across every response
            // branch below (including the former always-retain error
            // branch). Absent the field entirely (a fully legacy send),
            // default to 'clear' — nothing to retry against.
            if ((response.m5_token_action || 'clear') === 'clear') {
              delete pendingConversationTokens[chatBoxId];
            }

            if (response.status === "processing") {
              toastr["info"](response.message, "{{ __('locale.labels.attention') }}", {
                closeButton: true,
                positionClass: "toast-top-right",
                progressBar: true,
                newestOnTop: true,
                rtl: isRtl
              });
            } else if (response.status === "success") {
              toastr["success"](response.message);

              // Shown straight away, as it always was; the persisted message
              // takes its place the next time this timeline loads.
              let chatHistory = $(".chat_history");
              const $chat = $(`<div class="chat timeline-message" data-role="timeline-message" data-direction="outbound">
                <div class="chat-body">
                  <div class="chat-content"></div>
                </div>
              </div>`);

              const $content = $chat.find(".chat-content");

              $content.append(safeMessageParagraph(messageValue));

              // if media image exists, show it in chat
              if (response.media_url) {
                const $img = $("<img>")
                  .attr("src", response.media_url)
                  .attr("alt", "media")
                  .attr("style", "max-width:200px; max-height:200px;");
                $content.append($("<p></p>").append($img));
              }

              chatHistory.append($chat);
              message.val("");
              $("#media_image").val(""); // reset file input
              $(".user-chats").scrollTop($(".user-chats > .chats").height());
            } else {
              toastr["warning"](response.message, "{{ __('locale.labels.attention') }}", {
                closeButton: true,
                positionClass: "toast-top-right",
                progressBar: true,
                newestOnTop: true,
                rtl: isRtl
              });
            }
          },
          error: function(reject) {
            $(".send").attr("disabled", false);

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
      }


      $(".remove-btn").on("click", function(event) {
        event.preventDefault();
        let sms_id = $(".chat_id").val();

        Swal.fire({
          title: "{{ __('locale.labels.are_you_sure') }}",
          text: "{{ __('locale.labels.able_to_revert') }}",
          icon: "warning",
          showCancelButton: true,
          confirmButtonText: "{{ __('locale.labels.delete_it') }}",
          customClass: {
            confirmButton: "btn btn-primary",
            cancelButton: "btn btn-outline-danger ms-1"
          },
          buttonsStyling: false
        }).then(function(result) {
          if (result.value) {
            $.ajax({
              url: conversationUrl('delete', sms_id),
              type: "POST",
              data: {
                _token: "{{csrf_token()}}"
              },
              success: function(response) {

                if (response.status === "success") {
                  toastr["success"](response.message, '{{__('locale.labels.success')}}!!', {
                    closeButton: true,
                    positionClass: "toast-top-right",
                    progressBar: true,
                    newestOnTop: true,
                    rtl: isRtl
                  });

                  setTimeout(function() {
                    window.location.reload(); // then reload the page.(3)
                  }, 3000);

                } else {
                  toastr["warning"](response.message, '{{ __('locale.labels.warning') }}!', {
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
          }
        });

      });

      $(".add-to-blacklist").on("click", function(event) {
        event.preventDefault();
        let sms_id = $(".chat_id").val();

        Swal.fire({
          title: "{{ __('locale.labels.are_you_sure') }}",
          text: "{{ __('locale.labels.remove_blacklist') }}",
          icon: "warning",
          showCancelButton: true,
          confirmButtonText: "{{ __('locale.labels.block') }}",
          customClass: {
            confirmButton: "btn btn-primary",
            cancelButton: "btn btn-outline-danger ms-1"
          },
          buttonsStyling: false
        }).then(function(result) {
          if (result.value) {
            $.ajax({
              url: conversationUrl('block', sms_id),
              type: "POST",
              data: {
                _token: "{{csrf_token()}}"
              },
              success: function(response) {

                if (response.status === "success") {
                  toastr["success"](response.message, '{{__('locale.labels.success')}}!!', {
                    closeButton: true,
                    positionClass: "toast-top-right",
                    progressBar: true,
                    newestOnTop: true,
                    rtl: isRtl
                  });

                  setTimeout(function() {
                    window.location.reload(); // then reload the page.(3)
                  }, 3000);

                } else {
                  toastr["warning"](response.message, '{{ __('locale.labels.warning') }}!', {
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
          }
        });

      });

      $(".add-to-pin").on("click", function(event) {
        event.preventDefault();
        let sms_id = $(".chat_id").val();

        Swal.fire({
          title: "{{ __('locale.labels.are_you_sure') }}",
          icon: "question",
          showCancelButton: true,
          confirmButtonText: "{{ __('locale.labels.yes') }}",
          customClass: {
            confirmButton: "btn btn-primary",
            cancelButton: "btn btn-outline-danger ms-1"
          },
          buttonsStyling: false
        }).then(function(result) {
          if (result.value) {
            $.ajax({
              url: conversationUrl('pin', sms_id),
              type: "POST",
              data: {
                _token: "{{csrf_token()}}"
              },
              success: function(response) {

                if (response.status === "success") {
                  toastr["success"](response.message, '{{__('locale.labels.success')}}!!', {
                    closeButton: true,
                    positionClass: "toast-top-right",
                    progressBar: true,
                    newestOnTop: true,
                    rtl: isRtl
                  });

                  setTimeout(function() {
                    window.location.reload(); // then reload the page.(3)
                  }, 1000);

                } else {
                  toastr["warning"](response.message, '{{ __('locale.labels.warning') }}!', {
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
          }
        });

      });

      @if(config('broadcasting.connections.pusher.app_id'))
      window.Echo = new Echo({
        broadcaster: "pusher",
        key: "{{ config('broadcasting.connections.pusher.key') }}",
        cluster: "{{ config('broadcasting.connections.pusher.options.cluster') }}",
        encrypted: true,
        authEndpoint: '{{config('app.url')}}/broadcasting/auth'
      });

      Pusher.logToConsole = false;

      // Slice 2B: only this Business's own channel — never the old global
      // "chat" channel every customer shared. routes/channels.php admits a
      // listener only when it could open this Business's inbox.
      Echo.private(@json(\App\Events\MessageReceived::channelFor($businessUid))).listen("MessageReceived", (e) => {
        let chat_id = e.data.uid;
        let box_id = e.data.id;

        // The open conversation reloads its timeline, so the new message
        // arrives in the same server-rendered form as the rest of it. Any
        // other conversation only gets its unread count.
        if ($(".chat_id").val() === String(chat_id)) {
          loadTimeline(chat_id);

          return;
        }

        $.ajax({
          url: conversationUrl('notification', chat_id),
          type: "POST",
          data: {
            _token: "{{csrf_token()}}"
          },
          success: function(response) {
            const $contact = $(`.media-list li[data-box-id=${box_id}]`);
            const $counter = $(".counter", $contact).removeAttr("hidden");
            $(".notification_count", $contact).html(response.notification);
            $counter.html(response.notification);
            $counter.removeAttr("hidden");
          }
        });
      });
      @endif


      $(document).ready(function() {
        let page = 1;
        let filter = "recents";  // Default filter
        let search = "";     // Default search value

        // Function to load chat users
        function loadChatUsers(page, filter, search, append = false) {
          $.ajax({
            // POST, matching the route's locked verb: the list is only ever
            // read by this page's own AJAX, so it has no GET alias.
            url: conversationRoutes.load,
            type: "POST",
            data: {
              _token: "{{ csrf_token() }}",
              page: page,
              filter: filter,
              search: search
            },
            beforeSend: function() {
              $("#loader").show();  // Show the loader before the request
            },
            success: function(response) {
              $("#loader").hide();  // Hide the loader after data is loaded
              if (append) {
                $(".chat-users-list").append(response); // Append new data
              } else {
                $(".chat-users-list").html(response);   // Replace data
              }

              feather.replace();

            },
            error: function() {
              $("#loader").hide();  // Hide loader in case of error
              toastr["warning"]('{{ __('locale.exceptions.something_went_wrong') }}', "{{ __('locale.labels.attention') }}", {
                closeButton: true,
                positionClass: "toast-top-right",
                progressBar: true,
                newestOnTop: true,
                rtl: isRtl
              });
            }
          });
        }

        // Initial load
        loadChatUsers(page, filter, search);

// Add debounce function to delay the search request
        function debounce(func, delay) {
          let timeout;
          return function(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), delay);
          };
        }

// Filter data by tab
        $(".tab-button").on("click", function() {
          // Change the color of the clicked button to primary and reset others
          $(".tab-button").removeClass("btn-primary").addClass("btn-outline-primary");
          $(this).removeClass("btn-outline-primary").addClass("btn-primary");

          // Get filter value and reset page
          page = 1;
          filter = $(this).data("filter");
          loadChatUsers(page, filter, search);
        });

// Load more data when "Load More" button is clicked
        $("#load-more").on("click", function() {
          page += 1;  // Increment page number
          loadChatUsers(page, filter, search, true);  // Append new data
        });

// Search functionality with debounce
        $("#chat-search").on("keyup", debounce(function() {
          search = $(this).val();  // Get search value
          page = 1;  // Reset page to 1
          loadChatUsers(page, filter, search);
        }, 500));  // Delay of 500ms

      });


      $("#users-list").on("scroll", function(e) {
        e.preventDefault();
        let div = $(this).get(0);
        if (div.scrollTop + div.clientHeight >= div.scrollHeight) {
          // do the lazy loading here
          $("#load-more").trigger("click");
        }
      });


    </script>
@endsection
