@extends('layouts/contentLayoutMaster')

@section('title', __('locale.customer.import_customers'))

@section('vendor-style')
    <!-- vendor css files -->
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/file-uploaders/dropzone.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
@endsection
@section('page-style')
    <!-- Page css files -->
    <link rel="stylesheet" href="{{ asset(mix('css/base/plugins/forms/form-file-uploader.css')) }}">
@endsection


@section('content')
    <!-- Basic Vertical form layout section start -->
    <section id="basic-vertical-layouts">
        <div class="row match-height">
            <div class="col-12">
                <div class="card">
                    <div class="card-body import-file">

                        <div class="mb-1 mt-2">
                            <p class="text-uppercase">{{ __('locale.labels.sample_file') }}</p>
                            <a href="{{route('admin.customers.download_customer_sample_file')}}" class="btn btn-primary fw-bold text-uppercase">
                                <i data-feather="file-text"></i> {{ __('locale.labels.download_sample_file') }}
                            </a>

                        </div>


                        <p class="card-text">
                            {!! __('locale.customer.import_file_description') !!}
                        </p>
                        <form action="{{ route('admin.customers.import') }}" class="dropzone dropzone-area"
                              id="import-customers">
                            @csrf
                            <div class="dz-message">{{ __('locale.filezone.click_here_to_upload') }}</div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- // Basic Vertical form layout section end -->

@endsection


@section('vendor-script')
    <!-- vendor files -->
    <script src="{{ asset(mix('vendors/js/file-uploaders/dropzone.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/forms/select/select2.full.min.js')) }}"></script>
@endsection

@section('page-script')

    <script>
      let firstInvalid = $("form").find(".is-invalid").eq(0);

      if (firstInvalid.length) {
        $("body, html").stop(true, true).animate({
          "scrollTop": firstInvalid.offset().top - 200 + "px"
        }, 200);
      }

      // Basic Select2 select
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



      Dropzone.autoDiscover = false;

      $(function () {
        'use strict';
        let importContacts = $('#import-customers');

        importContacts.dropzone({
          paramName: 'import_file', // The name that will be used to transfer the file
          maxFilesize: 500, // MB
          acceptedFiles: ".csv",
          maxFiles: 1,
          maxThumbnailFilesize: 1, // MB
          addRemoveLinks: true,
          dictRemoveFile: '{{ __('locale.labels.remove') }}',
          init: function () {
            this.on("success", function (file, response) {
              if (response.status === 'success') {
                toastr['success'](response.message, '{{ __('locale.labels.success') }}!', {
                  closeButton: true,
                  positionClass: 'toast-top-right',
                  progressBar: true,
                  newestOnTop: true,
                  rtl: isRtl
                });

                $.ajax({
                  url: response.mappingUrl,
                  type: 'POST',
                  data: {
                    _token: "{{csrf_token()}}",
                  },
                  success: function(response) {
                    $('.import-file').html(response.html);
                  },
                  error: function(xhr, status, error) {
                    // Handle errors here
                    console.error('Ajax request failed');
                    console.error(xhr.responseText);
                  }
                });

              } else {
                toastr['error'](response.message, '{{ __('locale.labels.error') }}!', {
                  closeButton: true,
                  positionClass: 'toast-top-right',
                  progressBar: true,
                  newestOnTop: true,
                  rtl: isRtl
                });
              }
            });
            this.on("error", function () {
              toastr['warning']("{{__('locale.exceptions.something_went_wrong')}}", '{{ __('locale.labels.warning') }}!', {
                closeButton: true,
                positionClass: 'toast-top-right',
                progressBar: true,
                newestOnTop: true,
                rtl: isRtl
              });
            });
          },
        });

      });
    </script>
@endsection

`
