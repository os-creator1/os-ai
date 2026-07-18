@extends('layouts/contentLayoutMaster')

@section('title', __('locale.menu.Plugins'))

@section('page-style')
    <link rel="stylesheet" href="{{ asset(mix('css/base/pages/app-ecommerce.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/extensions/sweetalert2.min.css')) }}">
@endsection

@section('content')
    <!-- Wishlist Starts -->
    <section id="wishlist" class="grid-view">
        @if(count($plugins))
            @foreach($plugins as $plugin)
                <div class="card ecommerce-card">
                    <div class="item-img text-center">
                        @if($plugin->name == 'codeglen/usupport')
                            <img src="{{ asset('images/backgrounds/usupport.svg') }}"
                                 class="img-fluid p-5" alt="{{ $plugin->title }}" />
                        @elseif($plugin->name == 'codeglen/ulanding')
                            <img src="{{ asset('images/backgrounds/ulanding.svg') }}"
                                 class="img-fluid p-5" alt="{{ $plugin->title }}" />
                        @else
                            <img src="{{ asset('images/backgrounds/plugin.svg') }}"
                                 class="img-fluid p-5" alt="{{ $plugin->title }}" />
                        @endif


                    </div>
                    <div class="card-body">
                        <div class="item-wrapper d-flex justify-content-between align-items-center">
                    <span class="badge bg-{{ $plugin->status === 'active' ? 'success' : 'danger' }}">
                        {{ trans('locale.labels.' . $plugin->status) }}
                    </span>
                            <div class="item-cost">
                                <h6 class="item-price">v.{{ $plugin->version }}</h6>
                            </div>
                        </div>
                        <div class="item-name">
                            <a href="javascript:void(0);">
                                {{ $plugin->title }}
                            </a>
                        </div>
                        <p class="card-text item-description">{{ $plugin->description }}</p>
                    </div>
                    <div class="item-options text-center">


                        <a href="{{ route('admin.plugins.install') }}" class="btn btn-info btn-sm btn-wishlist">
                            <i data-feather="upload"></i> {{ __('locale.buttons.update') }}
                        </a>

                        @if($plugin->status === 'active')
                            <button type="button" class="btn btn-warning btn-sm btn-wishlist plugin-action"
                                    data-url="{{ route('admin.plugins.action', [$plugin->uid, 'disable']) }}"
                                    data-method="POST"
                                    data-title="{{ __('locale.plugins.disable_plugin') }}"
                                    data-text="{{ __('locale.plugins.disable_plugin_description') }}">
                                <i data-feather="x"></i> {{ __('locale.labels.disable') }}
                            </button>
                        @else
                            <button type="button" class="btn btn-primary btn-sm btn-wishlist plugin-action"
                                    data-url="{{ route('admin.plugins.action', [$plugin->uid, 'enable']) }}"
                                    data-method="POST"
                                    data-title="{{ __('locale.plugins.enable_plugin') }}"
                                    data-text="{{ __('locale.plugins.enable_plugin_description') }}">
                                <i data-feather="check"></i> {{ __('locale.labels.enable') }}
                            </button>
                        @endif

                        <button type="button" class="btn btn-danger btn-sm btn-wishlist plugin-action"
                                data-url="{{ route('admin.plugins.action', [$plugin->uid, 'uninstall']) }}"
                                data-method="POST"
                                data-title="{{ __('locale.plugins.uninstall_plugin') }}"
                                data-text="{{ __('locale.plugins.uninstall_plugin_description') }}">
                            <i data-feather="trash-2"></i> {{ __('locale.labels.uninstall') }}
                        </button>
                    </div>
                </div>
            @endforeach

        @else
            <div class="card ecommerce-card">
                <div class="item-img text-center">
                    <img src="{{ asset('images/backgrounds/usupport.svg') }}"
                         class="img-fluid p-5" alt="uSupport - Support Ticket Plugin for Ultimate SMS" />

                </div>
                <div class="card-body">
                    <div class="item-wrapper d-flex justify-content-between align-items-center">
                    <span class="badge bg-info">
                        {{ trans('locale.labels.pro') }}
                    </span>
                        <div class="item-cost">
                            <h6 class="item-price text-info">$29</h6>
                        </div>
                    </div>
                    <div class="item-name">
                        <a href="javascript:void(0);">
                            uSupport - Support Ticket Plugin for Ultimate SMS
                        </a>
                    </div>
                    <p class="card-text item-description">uSupport is a support ticket plugin for Ultimate SMS.</p>
                </div>
                <div class="item-options text-center">

                    <a href="{{ route('admin.plugins.install') }}" class="btn btn-primary btn-sm btn-wishlist">
                        <i data-feather="plus-square"></i> {{ __('locale.menu.Add Plugin') }}
                    </a>


                    <a class="btn btn-success btn-sm btn-wishlist"
                       href="https://codecanyon.net/item/usupport-support-ticket-plugin-for-ultimate-sms/59721091"
                       target="_blank">
                        <i data-feather="shopping-cart"></i> {{ __('locale.labels.purchase') }}
                    </a>
                </div>
            </div>
        @endif
    </section>
    <!-- Wishlist Ends -->
@endsection



@section('vendor-script')
    {{-- vendor files --}}
    <script src="{{ asset(mix('vendors/js/extensions/sweetalert2.all.min.js')) }}"></script>
@endsection


@section('page-script')

    {{-- 1. Define the showResponseMessage function globally --}}
    <script>

      // show response message
      function showResponseMessage(data) {
        if (data.status === "success") {
          toastr["success"](data.message, '{{__('locale.labels.success')}}!!', {
            closeButton: true,
            positionClass: "toast-top-right",
            progressBar: true,
            newestOnTop: true,
            rtl: isRtl
          });
        } else if (data.status === "error") {
          toastr["error"](data.message, '{{ __('locale.labels.opps') }}!', {
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
      }
    </script>

    {{-- 2. Your existing logic for plugin actions --}}
    <script>
      $(document).ready(function() {
        // Check for Laravel session messages on page load (after form submission redirects)
          @if (session()->has('message'))
          showResponseMessage({
            status: '{{ session()->get('status') }}',
            message: '{{ session()->get('message') }}'
          });
          @endif

          $(".plugin-action").on("click", function() {
            let button = $(this);
            let url = button.data("url");
            let title = button.data("title");
            let text = button.data("text");
            let method = button.data("method");

            // If uninstall, show two options
            if (url.includes("uninstall")) {
              Swal.fire({
                title: title,
                text: text,
                icon: "warning",
                showCancelButton: true,
                showDenyButton: true,
                confirmButtonText: "{{ __('locale.labels.uninstall') }}", // Remove Data
                denyButtonText: "{{ __('locale.plugins.keep_data') }}",     // Keep Data
                cancelButtonText: "{{ __('locale.buttons.cancel') }}",
                customClass: {
                  confirmButton: "btn btn-danger",
                  denyButton: "btn btn-warning ms-1",
                  cancelButton: "btn btn-outline-secondary ms-1"
                },
                buttonsStyling: false
              }).then((result) => {
                if (result.isConfirmed || result.isDenied) {
                  let form = $("<form>", {
                    method: method,
                    action: url
                  });

                  let token = $("<input>", {
                    type: "hidden",
                    name: "_token",
                    value: '{{ csrf_token() }}'
                  });

                  // Pass extra flag to backend
                  let option = $("<input>", {
                    type: "hidden",
                    name: "uninstall_option",
                    value: result.isConfirmed ? "remove" : "keep"
                  });

                  form.append(token, option);
                  $("body").append(form);
                  form.submit(); // Submits the form, triggers server-side action
                }
              });
            } else {
              // Default behavior for enable/disable
              Swal.fire({
                title: title,
                text: text,
                icon: "warning",
                showCancelButton: true,
                confirmButtonText: "{{ __('locale.labels.yes') }}, {{ __('locale.buttons.proceed') }}",
                cancelButtonText: "{{ __('locale.buttons.cancel') }}",
                customClass: {
                  confirmButton: "btn btn-primary",
                  cancelButton: "btn btn-outline-secondary ms-1"
                },
                buttonsStyling: false
              }).then((result) => {
                if (result.value) {
                  let form = $("<form>", {
                    method: method,
                    action: url
                  });

                  let token = $("<input>", {
                    type: "hidden",
                    name: "_token",
                    value: '{{ csrf_token() }}'
                  });

                  form.append(token);
                  $("body").append(form);
                  form.submit(); // Submits the form, triggers server-side action
                }
              });
            }
          });
      });
    </script>

@endsection
