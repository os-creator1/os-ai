@extends('layouts/contentLayoutMaster')

@section('title', __('locale.menu.AI Settings'))


@section('content')
    <!-- Basic Vertical form layout section start -->
    <section id="basic-vertical-layouts">


        <div class="row">
            <div class="col-md-6 col-12">
                <div class="alert alert-danger text-uppercase" role="alert">
                    <div class="alert-body d-flex align-items-center">
                        <i data-feather="info" class="me-50"></i>
                        <span>Note: Access to the OpenAI API requires a paid plan. See the <a href="https://openai.com/api/pricing/" target="_blank">pricing</a>. This is separate from your ChatGPT account status. </span>
                    </div>
                </div>
            </div>
        </div>


        <div class="row match-height">
            <div class="col-md-6 col-12">

                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title"> {{__('locale.menu.AI Settings')}} </h4>
                    </div>
                    <div class="card-content">
                        <div class="card-body">
                            <p>{!! __('locale.description.ai_settings') !!}</p>
                            <form class="form form-vertical" action="{{ route('admin.settings.ai-settings') }}"
                                  method="post">
                                @csrf
                                <div class="row">


                                    <div class="d-flex">
                                        <div class="form-check form-check-primary">

                                            <input type="hidden" name="openai[enabled]" value="no" />
                                            <input type="checkbox" id="openaiEnabled" class="form-check-input"
                                                   name="openai[enabled]"
                                                   {{ config('services.openai.active') ? 'checked' : '' }}
                                                   value="yes" />

                                            <label class="form-check-label text-primary bold fw-bolder"
                                                   for="openaiEnabled">{{ __('locale.labels.enable') }}</label>
                                        </div>
                                    </div>

                                    <div class="openai-settings mt-2"
                                         style="display: {{ config('services.openai.active') ? 'block' : 'none' }};">

                                        <div class="col-12">
                                            <div class="mb-1">
                                                <label for="api_key"
                                                       class="required form-label">{{__('locale.labels.api_key')}}</label>
                                                <input type="text" id="api_key"
                                                       class="form-control @error('api_key') is-invalid @enderror"
                                                       value="{{ old('api_key', config('services.openai.api_key')) }}"
                                                       name="api_key" required>
                                                @error('api_key')
                                                <div class="invalid-feedback">
                                                    {{ $message }}
                                                </div>
                                                @enderror
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <div class="mb-1">
                                                <label for="model"
                                                       class="required form-label">{{__('locale.labels.model')}}  {{ __('locale.labels.name') }}</label>
                                                <input type="text" id="model"
                                                       class="form-control @error('model') is-invalid @enderror"
                                                       value="{{ old('model', config('services.openai.model')) }}"
                                                       name="model" required>
                                                @error('model')
                                                <div class="invalid-feedback">
                                                    {{ $message }}
                                                </div>
                                                @enderror
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <div class="mb-1">
                                                <label for="organization"
                                                       class="form-label">{{__('locale.labels.organization')}}  {{ __('locale.labels.id') }}</label>
                                                <input type="text" id="organization"
                                                       class="form-control @error('organization') is-invalid @enderror"
                                                       value="{{ old('organization', config('services.openai.organization')) }}"
                                                       name="organization">
                                                @error('organization')
                                                <div class="invalid-feedback">
                                                    {{ $message }}
                                                </div>
                                                @enderror
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <div class="mb-1">
                                                <label for="project"
                                                       class="form-label">{{__('locale.labels.project')}}  {{ __('locale.labels.id') }}</label>
                                                <input type="text" id="project"
                                                       class="form-control @error('project') is-invalid @enderror"
                                                       value="{{ old('project', config('services.openai.project')) }}"
                                                       name="project">
                                                @error('project')
                                                <div class="invalid-feedback">
                                                    {{ $message }}
                                                </div>
                                                @enderror
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <div class="mb-1">
                                                <label for="role"
                                                       class="form-label">{{__('locale.labels.role')}}</label>
                                                <input type="text" id="role"
                                                       class="form-control @error('role') is-invalid @enderror"
                                                       value="{{ old('role', config('services.openai.role')) }}"
                                                       name="role">
                                                @error('role')
                                                <div class="invalid-feedback">
                                                    {{ $message }}
                                                </div>
                                                @enderror
                                            </div>
                                        </div>


                                        <div class="col-12 mt-2">
                                            <button type="submit" class="btn btn-primary mr-1 mb-1">
                                                <i data-feather="save"></i> {{__('locale.buttons.update')}}
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

@section('page-script')

    <script>
      let firstInvalid = $("form").find(".is-invalid").eq(0),
        openaiEnabled = $("#openaiEnabled");

      if (firstInvalid.length) {
        $("body, html").stop(true, true).animate({
          "scrollTop": firstInvalid.offset().top - 200 + "px"
        }, 200);
      }

      // Show/hide OpenAI settings on initial load
      toggleOpenAISettings();

      // On checkbox change
      openaiEnabled.on("change", function() {
        toggleOpenAISettings();
        updateOpenAIStatus(openaiEnabled.is(":checked"));
      });

      function toggleOpenAISettings() {
        if (openaiEnabled.is(":checked")) {
          $(".openai-settings").show();
        } else {
          $(".openai-settings").hide();
        }
      }

      // AJAX function to update OpenAI enabled status
      function updateOpenAIStatus(enabled) {
        $.ajax({
          url: "{{ route('admin.settings.ai-settings.toggle') }}",
          type: "POST",
          data: {
            _token: "{{ csrf_token() }}",
            openai_enabled: !!enabled
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
            } else if (response.status === "error") {
              toastr["error"](response.message, '{{ __('locale.labels.opps') }}!', {
                closeButton: true,
                positionClass: "toast-top-right",
                progressBar: true,
                newestOnTop: true,
                rtl: isRtl
              });
            } else {
              toastr["warning"]("{{__('locale.exceptions.something_went_wrong')}}", '{{ __('locale.labels.warning') }}!', {
                closeButton: true,
                positionClass: "toast-top-right",
                progressBar: true,
                newestOnTop: true,
                rtl: isRtl
              });
            }
          },
          error: function(xhr) {
            toastr["error"](xhr.responseText, '{{ __('locale.labels.opps') }}!', {
              closeButton: true,
              positionClass: "toast-top-right",
              progressBar: true,
              newestOnTop: true,
              rtl: isRtl
            });
          }
        });
      }
    </script>

@endsection

