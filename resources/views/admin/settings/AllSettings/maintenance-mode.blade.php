@extends('layouts/contentLayoutMaster')

@section('title', __('locale.menu.Maintenance Mode'))


@section('content')

    <!-- Basic Vertical form layout section start -->
    <section id="basic-vertical-layouts">

        <!-- custom options with icons -->
        <div class="row match-height">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">{{ __('locale.settings.set_maintenance_mode') }}</h4>
                    </div>
                    <div class="card-body">

                        @if(session('notify'))
                            <div class="alert alert-primary">
                                <div class="alert-body">
                                    <span class="align-middle"> {!! session('notify') !!} </span>
                                </div>
                            </div>
                        @endif

                        <form method="POST" action="{{ route('admin.settings.maintenance-mode') }}">
                            @csrf
                            <div class="row custom-options-checkable g-1">
                                <div class="col-md-6">
                                    <input
                                            class="custom-option-item-check"
                                            type="radio"
                                            name="status"
                                            id="enable"
                                            value="on" {{ $isDown ? 'checked' : '' }}
                                    />
                                    <label class="custom-option-item text-center p-1" for="enable">
                                        <i data-feather="stop-circle" class="font-large-1 mb-75"></i>
                                        <span class="custom-option-item-title h4 d-block">{{ __('locale.labels.enable') }} {{ __('locale.menu.Maintenance Mode') }}</span>
                                        <small>{{ __('locale.settings.enable_maintenance_mode') }}</small>
                                    </label>
                                </div>

                                <div class="col-md-6">
                                    <input
                                            class="custom-option-item-check"
                                            type="radio"
                                            name="status"
                                            id="disable"
                                            value="off" {{ !$isDown ? 'checked' : '' }}
                                    />
                                    <label class="custom-option-item text-center text-center p-1"
                                           for="disable">
                                        <i data-feather="play" class="font-large-1 mb-75"></i>
                                        <span class="custom-option-item-title h4 d-block">{{ __('locale.labels.disable') }} {{ __('locale.menu.Maintenance Mode') }}</span>
                                        <small>{{ __('locale.settings.disable_maintenance_mode') }}</small>
                                    </label>
                                </div>

                                <div class="col-12">
                                    <div class="mb-1">
                                        <label for="secret"
                                               class="form-label required">{{ __('locale.labels.secret_key') }}</label>
                                        <input type="text" id="secret"
                                               class="form-control @error('secret') is-invalid @enderror"
                                               value="{{ old('secret') }}" name="secret"
                                               placeholder="{{ config('app.maintenance_secret_path') }}">
                                        @error('secret')
                                        <p><small class="text-danger">{{ $message }}</small></p>
                                        @enderror
                                        <p>
                                            <small class="text-primary">{{ __('locale.settings.maintenance_secret', ['url' => config('app.url')]) }}</small>
                                        </p>
                                    </div>
                                </div>


                                <div class="col-12 mt-2">
                                    <button type="submit" class="btn btn-primary mr-1 mb-1">
                                        <i data-feather="save"></i> {{__('locale.buttons.save')}}
                                    </button>
                                </div>

                            </div>
                        </form>
                    </div>
                </div>
            </div>

        </div>
        <!-- / custom options with icons -->

    </section>
    <!-- // Basic Vertical form layout section end -->

@endsection



@section('page-script')
    <script>
      $(document).ready(function() {
        function toggleSecretField() {
          if ($("#enable").is(":checked")) {
            $("#secret").closest(".col-12").show();
          } else {
            $("#secret").closest(".col-12").hide();
          }
        }

        // Initial check on page load
        toggleSecretField();

        // Listen for changes
        $("input[name=\"status\"]").on("change", function() {
          toggleSecretField();
        });
      });
    </script>
@endsection
