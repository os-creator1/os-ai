@extends('layouts/contentLayoutMaster')

@section('title', __('locale.menu.Update Application'))

@section('content')
    <!-- Basic Vertical form layout section start -->
    <section id="basic-vertical-layouts">
        <div class="row match-height">
            <div class="col-md-7 col-12">

                <div class="card">
                    <div class="card-header"></div>
                    <div class="card-content">
                        <div class="card-body">

                            @if ($errors->any())
                                <div class="alert alert-danger">
                                    <ul>
                                        @foreach ($errors->all() as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            @if(Session::get('update_required'))

                                <h4 class="text-danger mb-2">Before update, please take a backup you're all files and
                                    database.</h4>

                                <p> Your are currently running on Ultimate SMS <code
                                            class="fw-bold">{{ config('app.version') }}</code> To upgrade your
                                    application, please download the latest build from <a
                                            href="https://codecanyon.net/item/ultimate-sms-bulk-sms-application-for-marketing/20062631"
                                            target="_blank">codecanyon.net</a> , then you can find the upgrade package
                                    located at downloaded folder called <code>3.*.*_update.zip</code>, upload it using
                                    the form below.
                                </p>
                                <p>
                                    @if(function_exists('ini_get'))
                                        @if((int)ini_get('post_max_size') < 120)
                                            Your <code>post_max_size</code> value is: {{ ini_get('post_max_size') }}.
                                            Please increase your limit to minimum 120M.<br>

                                        @else
                                            Please make sure the upgrade package file size does not exceed the following
                                            upload limit settings. Otherwise, your update will not work:<br><br>
                                            <span class="fw-bold text-danger"><i data-feather="check-square"></i> post_max_size</span>
                                            <span class="fw-bold text-primary">256M</span><br>

                                        @endif

                                        @if((int)ini_get('upload_max_filesize') < 120)
                                            Your <code>upload_max_filesize</code> value
                                            is: {{ ini_get('upload_max_filesize') }}. Please increase your limit to
                                            minimum 120M.<br>
                                        @else
                                            <span class="fw-bold text-danger"><i data-feather="check-square"></i> upload_max_filesize</span>
                                            <span class="fw-bold text-primary">256M</span><br>

                                        @endif
                                </p>
                            @else
                                <p>Please make sure the upgrade package file size does not exceed the following upload
                                    limit settings. Otherwise, your update will not work:<br><br>
                                    <span class="fw-bold text-danger"><i data-feather="check-square"></i> post_max_size</span>
                                    <span class="fw-bold text-primary">256M</span><br>
                                    <span class="fw-bold text-danger"><i data-feather="check-square"></i> upload_max_filesize</span>
                                    <span class="fw-bold text-primary">256M</span>
                                </p>
                            @endif


                            <form id="fileUploadForm" class="form form-vertical"
                                  action="{{ route('admin.settings.update_application') }}" method="post"
                                  enctype="multipart/form-data">
                                @csrf
                                <div class="row">

                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="purchase_code"
                                                   class="required form-label">{{__('locale.permission.purchase_code')}}</label>
                                            <input type="text" id="purchase_code"
                                                   class="form-control @error('purchase_code') is-invalid @enderror"
                                                   value="{{ \App\Helpers\Helper::app_config('license') }}"
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
                                            <label for="update_file" class="form-label">Update File</label>
                                            <input type="file" name="update_file" class="form-control" id="update_file"
                                                   accept="zip,application/octet-stream,application/zip,application/x-zip,application/x-zip-compressed" />
                                            @error('update_file')
                                            <p><small class="text-danger"> {{ $message }}</small></p>
                                            @enderror
                                            @if(Session::get('version'))
                                                <input type="hidden" value="{{Session::get('version')}}" name="version">
                                                <p><small class="text-primary"> Please upload only
                                                        <code>{{Session::get('version')}}_update.zip</code> file</small>
                                                </p>
                                            @endif
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-12">


                                        @if (phpversion() < "8.2.0")
                                            <button class="btn btn-warning waves-effect" type="button" disabled="">
                                                <span class="spinner-border spinner-border-sm" role="status"
                                                      aria-hidden="true"></span>
                                                <span class="text-uppercase">Upgrade PHP Version to 8.2</span>
                                            </button>
                                        @else

                                            <button type="submit" class="btn btn-primary mr-1 mb-1">
                                                <i data-feather="upload"></i> {{__('locale.labels.upload')}}
                                            </button>

                                        @endif
                                    </div>
                                </div>

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

                            </form>
                            @else
                                <h3> Your are currently running on Ultimate SMS <code
                                            class="fw-bold">{{ config('app.version') }}</code></h3>
                                <h4 class="mt-1"><span class="text-primary"> CONGRATULATION!!!</span> You are using
                                    latest version</h4>
                                <hr>
                                <a href="{{ route('admin.settings.check_update') }}" class="btn btn-primary"><i
                                            data-feather="check-square"></i> Check for Updates</a>
                            @endif
                        </div>

                    </div>
                </div>
            </div>


        </div>
    </section>
    <!-- // Basic Vertical form layout section end -->

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
          let percentage = "0";
        },
        uploadProgress: function(event, position, total, percentComplete) {
          let percentage = percentComplete;
          progress_bar.show();
          $(".progress .progress-bar").css("width", percentage + "%", function() {
            return $(this).attr("aria-valuenow", percentage) + "%";
          });
        },
        complete: function(data) {
          if (data.status === 200) {
            toastr["success"]("File uploaded. Please don't reload the page until complete. it will take couple of minutes to complete", '{{__('locale.labels.success')}}!!', {
              closeButton: true,
              positionClass: "toast-top-right",
              progressBar: true,
              newestOnTop: true,
              rtl: isRtl
            });


            location.reload();

          } else {
            if (data.status !== 200) {
              toastr["warning"]("Please check your server post_max_size & upload_max_filesize settings value.", '{{__('locale.labels.attention')}}!!', {
                closeButton: true,
                positionClass: "toast-top-right",
                progressBar: true,
                newestOnTop: true
              });
            } else {
              toastr["warning"](data.responseJSON.message, '{{__('locale.labels.attention')}}!!', {
                closeButton: true,
                positionClass: "toast-top-right",
                progressBar: true,
                newestOnTop: true
              });
            }
          }
        }
      });
    </script>
@endsection
