@section('vendor-style')
    <!-- vendor css files -->
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/file-uploaders/dropzone.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
@endsection
@section('page-style')
    <!-- Page css files -->
    <link rel="stylesheet" href="{{ asset(mix('css/base/plugins/forms/form-file-uploader.css')) }}">
@endsection

<div class="col-md-8 col-12">
    <x-card>
        <div class="import-file">

            <div class="mb-1 mt-2">
                <p class="text-uppercase">{{ __('locale.labels.sample_file') }}</p>
                <x-button :href="route('sample.file')" icon="file-text" class="fw-bold text-uppercase">
                    {{ __('locale.labels.download_sample_file') }}
                </x-button>

            </div>


            <p class="card-text">
                {!! __('locale.campaigns.import_file_description') !!}
                {!! __('locale.contacts.only_supported_file') !!}
                {!! __('locale.contacts.for_date_format') !!}
            </p>
            <form action="{{ \App\Library\CrmRouting::route('contact.import_file', $contact->uid) }}" class="dropzone dropzone-area"
                  id="import-contacts">
                @csrf
                <div class="dz-message">{{ __('locale.filezone.click_here_to_upload') }}</div>
            </form>
        </div>
    </x-card>
</div>

@section('vendor-script')
    <!-- vendor files -->
    <script src="{{ asset(mix('vendors/js/file-uploaders/dropzone.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/forms/select/select2.full.min.js')) }}"></script>
@endsection
@section('page-script')
    <!-- Page js files -->

    <script>

      Dropzone.autoDiscover = false;

      $(function() {
        "use strict";
        let importContacts = $("#import-contacts");

        importContacts.dropzone({
          paramName: "import_file", // The name that will be used to transfer the file
          maxFilesize: 500, // MB
          acceptedFiles: ".csv",
          maxFiles: 1,
          maxThumbnailFilesize: 1, // MB
          addRemoveLinks: true,
          dictRemoveFile: '{{ __('locale.labels.remove') }}',
          init: function() {
            this.on("success", function(file, response) {
              if (response.status === "success") {
                toastr["success"](response.message, '{{ __('locale.labels.success') }}!', {
                  closeButton: true,
                  positionClass: "toast-top-right",
                  progressBar: true,
                  newestOnTop: true,
                  rtl: isRtl
                });

                $.ajax({
                  url: response.mappingUrl,
                  type: "POST",
                  data: {
                    _token: "{{csrf_token()}}"
                  },
                  success: function(response) {
                    $(".import-file").html(response.html);
                  },
                  error: function(xhr, status, error) {
                    try {
                      const response = JSON.parse(xhr.responseText);

                      // Check if the response object has a 'message' property
                      if (response && response.message) {
                        toastr["error"](response.message, '{{ __('locale.labels.error') }}!', {
                          closeButton: true,
                          positionClass: "toast-top-right",
                          progressBar: true,
                          newestOnTop: true,
                          rtl: isRtl,
                          timeout: 12000
                        });
                      } else {
                        // Fallback to the original error message if the JSON structure is unexpected
                        toastr["error"](xhr.responseText, '{{ __('locale.labels.error') }}!', {
                          closeButton: true,
                          positionClass: "toast-top-right",
                          progressBar: true,
                          newestOnTop: true,
                          rtl: isRtl,
                          timeout: 12000
                        });
                      }
                    } catch (e) {
                      // Handle cases where the response is not valid JSON
                      toastr["error"](xhr.responseText, '{{ __('locale.labels.error') }}!', {
                        closeButton: true,
                        positionClass: "toast-top-right",
                        progressBar: true,
                        newestOnTop: true,
                        rtl: isRtl,
                        timeout: 12000
                      });
                    }

                  }
                });

              } else {
                toastr["error"](response.message, '{{ __('locale.labels.error') }}!', {
                  closeButton: true,
                  positionClass: "toast-top-right",
                  progressBar: true,
                  newestOnTop: true,
                  rtl: isRtl
                });
              }
            });
            this.on("error", function() {
              toastr["warning"]("{{__('locale.exceptions.something_went_wrong')}}", '{{ __('locale.labels.warning') }}!', {
                closeButton: true,
                positionClass: "toast-top-right",
                progressBar: true,
                newestOnTop: true,
                rtl: isRtl
              });
            });
          }
        });

      });

    </script>
@endsection
