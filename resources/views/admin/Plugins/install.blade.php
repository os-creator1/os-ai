@extends('layouts/contentLayoutMaster')

@section('title', __('locale.menu.Add Plugin'))

@section('content')
    <section id="basic-vertical-layouts">
        <div class="row match-height">
            <div class="col-md-6 col-12">

                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">{{ __('locale.menu.Add Plugin') }}</h4>
                    </div>
                    <div class="card-content">
                        <div class="card-body">

                            <p class="card-text">
                                {{__('locale.plugins.install_description')}}
                                To get your purchase code please check this <a
                                        href="https://help.market.envato.com/hc/en-us/articles/202822600-Where-Is-My-Purchase-Code-"
                                        target="_blank">Where Is My Purchase Code?</a></p>

                            <form class="form form-vertical" id="fileUploadForm"
                                  action="{{ route('admin.plugins.install') }}"
                                  method="post" enctype="multipart/form-data">
                                @csrf
                                <div class="row">

                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="purchase_code"
                                                   class="required form-label">{{__('locale.permission.purchase_code')}}</label>
                                            <input type="text" id="purchase_code"
                                                   class="form-control @error('purchase_code') is-invalid @enderror"
                                                   name="purchase_code" required>
                                            @error('purchase_code')
                                            <div class="invalid-feedback">
                                                {{ $message }}
                                            </div>
                                            @enderror
                                        </div>

                                    </div>


                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="file"
                                                   class="form-label required">{{__('locale.labels.choose_file')}}</label>
                                            <input type="file" name="file" class="form-control" id="file" required
                                                   placeholder="{{ __('locale.labels.choose_file') }}"
                                                   accept="application/zip,application/octet-stream,application/zip,application/x-zip,application/x-zip-compressed" />
                                            @error('file')
                                            <p><small class="text-danger">{{ $message }}</small></p>
                                            @enderror
                                        </div>
                                    </div>

                                    {{-- Add the progress bar HTML here --}}
                                    <div class="row" id="progress_bar">
                                        <div class="col-12">
                                            <div class="progress progress-bar-primary">
                                                <div class="progress-bar progress-bar-striped progress-bar-animated"
                                                     role="progressbar"
                                                     aria-valuenow="0"
                                                     aria-valuemin="0"
                                                     aria-valuemax="100"
                                                     style="width: 0"
                                                ></div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-12 mt-1">
                                        <button type="submit" class="btn btn-primary mr-1 mb-1"><i data-feather="upload"
                                                                                                   class="align-middle me-sm-25 me-0"></i> {{__('locale.labels.upload')}}
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
@endsection

@section('page-script')
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.form/4.3.0/jquery.form.min.js"></script>
    <script>
      let firstInvalid = $("form").find(".is-invalid").eq(0);

      if (firstInvalid.length) {
        $("body, html").stop(true, true).animate({
          "scrollTop": firstInvalid.offset().top - 200 + "px"
        }, 200);
      }

      let progress_bar = $("#progress_bar");
      progress_bar.hide();

      $("#fileUploadForm").ajaxForm({
        beforeSend: function() {
          // Reset progress bar on new upload
          let percentage = 0;
          progress_bar.show();
          $(".progress .progress-bar").css("width", percentage + "%");

          // Disable the upload button
          $("button[type=\"submit\"]").prop("disabled", true).text("{{ __('locale.buttons.processing') }}");
        },
        uploadProgress: function(event, position, total, percentComplete) {
          let percentage = percentComplete;
          $(".progress .progress-bar").css("width", percentage + "%").attr("aria-valuenow", percentage);
        },
        complete: function(data) {
          progress_bar.hide(); // Hide the progress bar on completion

          // Re-enable the upload button and restore its text
          $("button[type=\"submit\"]").prop("disabled", false).text("{{ __('locale.labels.upload') }}");

          if (data.responseJSON.status === "success") {
            toastr["success"](data.responseJSON.message, '{{__('locale.labels.success')}}!!', {
              closeButton: true,
              positionClass: "toast-top-right",
              progressBar: true,
              newestOnTop: true,
              rtl: isRtl
            });

            // Redirect to the new URL provided by the server
            if (data.responseJSON.redirect_url) {
              setTimeout(() => {
                window.location.href = data.responseJSON.redirect_url;
              }, 1000);
            } else {
              setTimeout(() => {
                location.reload();
              }, 1000);
            }

          } else {
            let errorMessage = "{{__('locale.exceptions.something_went_wrong')}}";
            if (data.responseJSON && data.responseJSON.message) {
              errorMessage = data.responseJSON.message;
            }

            toastr["warning"](errorMessage, '{{__('locale.labels.attention')}}!!', {
              closeButton: true,
              positionClass: "toast-top-right",
              progressBar: true,
              newestOnTop: true
            });
          }
        }
      });
    </script>
@endsection
