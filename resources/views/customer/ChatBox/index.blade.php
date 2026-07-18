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
            border: 1px solid #ced4da; /* Match border of input fields */
            border-radius: 4px; /* Match border radius of input fields */
            font-family: inherit; /* Use the same font as input fields */
            font-size: 14px; /* Match font size of input fields */
        }

        /* Optional: Add focus styling to match input fields */
        textarea.message:focus {
            border-color: #80bdff; /* Match focus border color of input fields */
            outline: 0; /* Remove default outline */
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25); /* Match focus shadow of input fields */
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
                <i data-feather="message-square"></i>
            </div>
            <h4 class="sidebar-toggle start-chat-text d-block d-md-none">
                {{ __('locale.labels.new_conversion') }}
            </h4>
            <h4 class="sidebar-toggle start-chat-text d-none d-md-block">
                <a href="{{ route('customer.chatbox.new') }}"
                   class="text-dark">{{ __('locale.labels.new_conversion') }}</a>
            </h4>
        </div>
        <!--/ To load Conversation -->

        <!-- Active Chat -->
        <div class="active-chat d-none">
            <!-- Chat Header -->
            <div class="chat-navbar">
                <header class="chat-header">
                    <div class="d-flex align-items-center">
                        <div class="sidebar-toggle d-block d-lg-none me-1">
                            <i data-feather="menu" class="font-medium-5"></i>
                        </div>
                        <div class="avatar avatar-border user-profile-toggle m-0 me-1"></div>
                        <span class="add-to-pin"> </span>
                    </div>
                    <div class="d-flex align-items-center">

                        <span class="add-to-blacklist" data-bs-toggle="tooltip" data-bs-placement="top"
                              title="{{ __('locale.labels.block') }}"> <i data-feather="shield"
                                                                          class="cursor-pointer font-medium-2 mx-1 text-primary"></i> </span>

                        <span class="remove-btn" data-bs-toggle="tooltip" data-bs-placement="top"
                              title="{{ __('locale.buttons.delete') }}"><i data-feather="trash"
                                                                           class="cursor-pointer font-medium-2 text-danger"></i></span>

                    </div>
                </header>
            </div>
            <!--/ Chat Header -->

            <!-- User Chat messages -->
            <div class="user-chats">
                <div class="chats">
                    <div class="chat_history"></div>
                </div>
            </div>
            <!-- User Chat messages -->

            <!-- Submit Chat form -->
            <form class="chat-app-form" action="javascript:void(0);" onsubmit="enter_chat();" autocomplete="off">

                <div class="input-group input-group-merge me-1 form-send-message">
                    <textarea type="text" id="message" class="form-control message" placeholder="..." autocomplete="off"></textarea>

                    <span class="input-group-text">
                          <label for="media_image" class="attachment-icon form-label mb-0 position-relative">
                            <i id="mms-icon" data-feather="image" class="cursor-pointer text-secondary"></i>
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


                <button type="button" class="btn btn-primary send" onclick="enter_chat();">
                    <i data-feather="send" class="d-lg-none"></i>
                    <span class="d-none d-lg-block">{{ __('locale.buttons.send') }}</span>
                </button>
            </form>
            <!--/ Submit Chat form -->
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


      $(document).ready(function() {
        // Cache chat container elements
        const chatHistory = $(".chat_history");
        const chatContainer = $(".user-chats .chats");

        // Use event delegation to bind click events to dynamically loaded users
        $("#users-list").on("click", ".chat-users-list li, .chat-users-list-pinned li", function() {
            // Destroy + recreate textarea to prevent browser restoring value
let msg = $("#message");
msg.replaceWith(msg.clone().val(""));
$("#media_image").val("");

            // HARD RESET composer immediately on chat switch
$("#message").val("");
$("#media_image").val("");

          chatHistory.empty();  // Clear previous chat messages
          chatContainer.animate({ scrollTop: chatContainer[0].scrollHeight }, 0);  // Scroll to the bottom initially

          $(this).find(".notification_count").remove();

          const chat_id = $(this).data("id");  // Get the clicked chat ID

          $(".chat-users-list li, .chat-users-list-pinned li").removeClass("active");
          $(this).addClass("active");

          // Fetch messages via POST request
          $.post(
            `{{ url('/chat-box')}}/${chat_id}/messages`,
            { _token: "{{ csrf_token() }}" }
          )
            .done(function(response) {


              let details = `<input type="hidden" value="${chat_id}" name="chat_id" class="chat_id">`,
                addToPin = $(".add-to-pin");

              // Parse the response data
              const cwData = response.data;


              if (response.pinned === 1) {
                addToPin.attr("title", '{{ __('locale.labels.unpin') }}');

                addToPin.tooltip("dispose").tooltip();


                // Find the <i> element and change the data-feather attribute to 'delete'
                addToPin.find("svg").remove();  // Remove the old <i> element
                addToPin.append("<i data-feather=\"delete\" class=\"cursor-pointer font-medium-2 mx-1 text-danger\"></i>");

                // Re-initialize Feather icons to update
                feather.replace();
              } else {
                addToPin.attr("title", '{{ __('locale.labels.pin') }}');

                addToPin.tooltip("dispose").tooltip();

                // Find the <i> element and change the data-feather attribute to 'edit-2'
                addToPin.find("svg").remove();  // Remove the old <i> element
                addToPin.append("<i data-feather=\"edit-2\" class=\"cursor-pointer font-medium-2 mx-1 text-info\"></i>");

                // Re-initialize Feather icons to update
                feather.replace();
              }


              // Loop through messages and render them
              cwData.forEach((sms) => {
                let media_url = "";
                if (sms.media_url !== null) {
                  let fileType = isImageOrVideo(sms.media_url);
                  if (fileType === "video") {
                    media_url = `<p><video src="${sms.media_url}" controls>Your browser does not support the video tag.</video></p>`;
                  } else if (fileType === "audio") {
                    media_url = `<p><audio src="${sms.media_url}" controls>Your browser does not support the audio element.</audio></p>`;
                  } else {
                    media_url = `<p><img src="${sms.media_url}" alt="media" /></p>`;
                  }
                }

                let message = sms.message ? `<p>${sms.message}</p>` : "";

                const chatHtml = `
                <div class="chat ${sms.direction === "incoming" ? "chat-left" : ""}">
                    <div class="chat-avatar">
                        <span class="avatar box-shadow-1 cursor-pointer">
                            <img src="{{ asset('images/profile/profile.jpg') }}" alt="avatar" height="36" width="36" />
                        </span>
                    </div>
                    <div class="chat-body">
                        <div class="chat-content">
                            ${media_url}
                            ${message}
                            <p class="chat-time text-muted mt-1">${sms.created_at}</p>
                        </div>
                    </div>
                </div>`;

                details += chatHtml;
              });

              // Append the chat messages to the chat history
              chatHistory.append(details);
              chatContainer.animate({ scrollTop: chatContainer[0].scrollHeight }, 400);  // Scroll to bottom of chat after loading

              // Show the active chat area
              $(".start-chat-area").addClass("d-none");  // Hide the initial "Start chat" screen
              $(".active-chat").removeClass("d-none");   // Show the chat area
              $(".counter").hide();
              
              setTimeout(() => {
  $("#message").val("");
  $("#media_image").val("");
}, 50);



            })
            .fail(function(xhr, status, error) {
              console.error("Error loading messages:", error);
            });
        });
      });


      function isImageOrVideo(url) {
        const videoExtensions = ["mp4", "avi", "mkv", "webm"];
        const audioExtensions = ["mp3", "wav", "ogg"];
        const imageExtensions = ["jpg", "jpeg", "png", "gif"];

        const extension = url.split(".").pop().toLowerCase();

        if (videoExtensions.includes(extension)) {
          return "video";
        } else if (audioExtensions.includes(extension)) {
          return "audio";
        } else if (imageExtensions.includes(extension)) {
          return "image";
        }
        return "unknown";
      }


      function enter_chat() {
        let message = $(".message"),
          chatBoxId = $(".chat_id").val(),
          messageValue = message.val(),
          mediaImage = $("#media_image")[0].files[0]; // get the selected file

        $(".send").attr("disabled", true);

        // Use FormData to handle file uploads
        let formData = new FormData();
        formData.append("message", messageValue);
        formData.append("_token", "{{ csrf_token() }}");

        if (mediaImage) {
          formData.append("media_image", mediaImage);
        }

        $.ajax({
          url: "{{ url('/chat-box') }}" + "/" + chatBoxId + "/reply",
          type: "POST",
          data: formData,
          processData: false, // prevent jQuery from converting to query string
          contentType: false, // allow multipart/form-data
          success: function(response) {
            $(".send").attr("disabled", false);


            console.log(response);

            if (response.status === "success") {
              toastr["success"](response.message, "Success!!", {
                closeButton: true,
                positionClass: "toast-top-right",
                progressBar: true,
                newestOnTop: true,
                rtl: isRtl
              });

              let chatHistory = $(".chat_history");
              let html = "<div class=\"chat\">" +
                "<div class=\"chat-avatar\">" +
                "<span class=\"avatar box-shadow-1 cursor-pointer\">" +
                '<img src="{{ asset('images/profile/profile.jpg') }}" alt="avatar" height="36" width="36"/>' +
                "</span>" +
                "</div>" +
                "<div class=\"chat-body\">" +
                "<div class=\"chat-content\">" +
                "<p>" + messageValue + "</p>";

              // if media image exists, show it in chat
              if (response.media_url) {
                html += "<p><img src=\"" + response.media_url + "\" alt=\"media\" style=\"max-width:200px; max-height:200px;\" /></p>";
              }

              html += "</div></div></div>";
              chatHistory.append(html);
              message.val("");
              $("#media_image").val(""); // reset file input
              $(".user-chats").scrollTop(chatHistory.height());
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
              url: "{{ url('/chat-box')}}" + "/" + sms_id + "/delete",
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
              url: "{{ url('/chat-box')}}" + "/" + sms_id + "/block",
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
              url: "{{ url('/chat-box')}}" + "/" + sms_id + "/pin",
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
      let activeChatID = $(".chat-users-list li.active").attr("data-id");

      window.Echo = new Echo({
        broadcaster: "pusher",
        key: "{{ config('broadcasting.connections.pusher.key') }}",
        cluster: "{{ config('broadcasting.connections.pusher.options.cluster') }}",
        encrypted: true,
        authEndpoint: '{{config('app.url')}}/broadcasting/auth'
      });

      Pusher.logToConsole = false;

      Echo.private("chat").listen("MessageReceived", (e) => {
        // chatHistory.empty();
        chatContainer.animate({ scrollTop: chatContainer[0].scrollHeight }, 0);

        let chat_id = e.data.uid;
        let box_id = e.data.id;

        $.ajax({
          url: `{{ url('/chat-box')}}/${chat_id}/notification`,
          type: "POST",
          data: {
            _token: "{{csrf_token()}}"
          },
          success: function(response) {
            activeChatID = $(".chat-users-list li.active").attr("data-id");
            let details = `<input type="hidden" value="${chat_id}" name="chat_id" class="chat_id">`;
            const $contact = $(`.media-list li[data-box-id=${box_id}]`);
            const $counter = $(".counter", $contact).removeAttr("hidden");
            $(".notification_count", $contact).html(response.notification);

          const sms = response.data;
            let media_url = "";
            let message = "";

            if (sms.media_url !== null) {
              let fileType = isImageOrVideo(sms.media_url);
              if (fileType === "video") {
                media_url = `<p><video src="${sms.media_url}" controls>Your browser does not support the video tag. <video/></p>`;
              } else if (fileType === "audio") {
                media_url = `<p><audio src="${sms.media_url}" controls>Your browser does not support the audio element. </audio></p>`;
              } else {
                media_url = `<p><img src="${sms.media_url}" alt=""/></p>`;
              }
            }

            if (sms.message !== null) {
              message = `<p>${sms.message}</p>`;
            }

            if (sms.direction === "incoming") {
              details += `<div class="chat chat-left">
                        <div class="chat-avatar">
                          <a class="avatar m-0" href="#">
                            <img src="{{asset('images/profile/profile.jpg')}}" alt="avatar" height="40" width="40"/>
                          </a>
                        </div>
                        <div class="chat-body">
                          <div class="chat-content">
                            ${media_url}
                            ${message}
                            <p class="chat-time text-muted mt-1">${sms.created_at}</p>
                          </div>
                        </div>
                      </div>`;
            } else {
              details += `<div class="chat">
                        <div class="chat-avatar">
                          <a class="avatar m-0" href="#">
                            <img src="{{  route('user.avatar', Auth::user()->uid) }}" alt="avatar" height="40" width="40"/>
                          </a>
                        </div>
                        <div class="chat-body">
                          <div class="chat-content">
                          ${media_url}
                          ${message}
                          <p class="chat-time text-muted mt-1">${sms.created_at}</p>
                          </div>
                          </div>
                          </div>`;
            }

            if (chat_id === activeChatID) {
              chatHistory.append(details);
              chatContainer.animate({ scrollTop: chatContainer[0].scrollHeight }, 0);
            } else {
              $counter.html(response.notification);
              $counter.removeAttr("hidden");
            }
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
            url: "{{ url('/chat-box/load') }}" + `?page=${page}&filter=${filter}&search=${search}`,
            type: "GET",
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

