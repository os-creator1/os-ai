@php use App\Library\Tool; @endphp
@extends('layouts/contentLayoutMaster')

@section('title', __('locale.sub_accounts.add_new'))

@section('vendor-style')
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
@endsection

@section('content')
    <section id="basic-vertical-layouts">
        <div class="row match-height">
            <div class="col-md-6 col-12">

                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">{{ __('locale.sub_accounts.add_new') }}</h4>
                    </div>
                    <div class="card-content">
                        <div class="card-body">
                            <form class="form form-vertical" action="{{ route('customer.sub_accounts.store') }}"
                                  method="post">
                                @csrf
                                <div class="row">

                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="email"
                                                   class="required form-label">{{__('locale.labels.email')}}</label>
                                            <input type="email" id="email"
                                                   class="form-control @error('email') is-invalid @enderror"
                                                   value="{{ old('email') }}" name="email" required>
                                            @error('email')
                                            <div class="invalid-feedback">
                                                {{ $message }}
                                            </div>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="first_name"
                                                   class="required form-label">{{__('locale.labels.first_name')}}</label>
                                            <input type="text" id="first_name"
                                                   class="form-control @error('first_name') is-invalid @enderror"
                                                   value="{{ old('first_name') }}" name="first_name" required>
                                            @error('first_name')
                                            <div class="invalid-feedback">
                                                {{ $message }}
                                            </div>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="last_name"
                                                   class="form-label">{{__('locale.labels.last_name')}}</label>
                                            <input type="text" id="last_name"
                                                   class="form-control @error('last_name') is-invalid @enderror"
                                                   value="{{ old('last_name') }}" name="last_name">
                                            @error('last_name')
                                            <div class="invalid-feedback">
                                                {{ $message }}
                                            </div>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="mb-1">
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="checkbox" id="add_password"
                                                       name="add_password" value="1">
                                                <label class="form-check-label"
                                                       for="add_password">{{ __('locale.sub_accounts.add_password') }}</label>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="password-section d-none">
                                        <div class="col-12">
                                            <div class="mb-1">
                                                <label class="form-label required"
                                                       for="password">{{ __('locale.labels.password') }}</label>
                                                <div class="input-group input-group-merge form-password-toggle">
                                                    <input type="password" id="password"
                                                           class="form-control @error('password') is-invalid @enderror"
                                                           name="password" />
                                                    <span class="input-group-text cursor-pointer"><i
                                                                data-feather="eye"></i></span>
                                                </div>
                                                @error('password')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                                @enderror
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <div class="mb-1">
                                                <label class="form-label required"
                                                       for="password_confirmation">{{ __('locale.labels.password_confirmation') }}</label>
                                                <div class="input-group input-group-merge form-password-toggle">
                                                    <input type="password" id="password_confirmation"
                                                           class="form-control @error('password_confirmation') is-invalid @enderror"
                                                           name="password_confirmation" />
                                                    <span class="input-group-text cursor-pointer"><i
                                                                data-feather="eye"></i></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>


                                    <div class="divider divider-danger">
                                        <div class="divider-text">{{ __('locale.labels.permissions') }}</div>
                                    </div>


                                    <div class="col-12">
                                        @if ($errors->has('permissions.*'))
                                            <p><small class="text-danger">{{ $errors->first() }}</small></p>
                                        @endif

                                        <div class="mt-2"></div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="selectAll" />
                                            <label class="form-check-label text-uppercase"
                                                   for="selectAll">{{ __('locale.labels.select_all') }}</label>
                                        </div>

                                        @foreach($permissions as $category)
                                            <div class="divider divider-start divider-info mt-4">
                                                <div class="divider-text text-uppercase fw-bold text-primary">{{ __('locale.menu.'.$category['title']) }}</div>
                                            </div>

                                            <div class="d-flex justify-content-start flex-wrap">
                                                @foreach($category['permissions'] as $permission)
                                                    {{-- Conditionally check if the permission exists in the customer's permissions --}}
                                                    @if(is_array($existing_permission) && in_array($permission['name'], $existing_permission))
                                                        <div class="form-check me-3 me-lg-5 mt-1">
                                                            <input type="checkbox"
                                                                   value="{{ $permission['name'] }}"
                                                                   name="permissions[]"
                                                                   @if($permission['name'] == 'access_backend') checked disabled @endif
                                                                   class="form-check-input"
                                                                   id="{{ $permission['name'] }}">
                                                            <label class="form-check-label text-uppercase"
                                                                   for="{{ $permission['name'] }}">
                                                                {{ __('locale.permission.'.$permission['display_name']) }}
                                                            </label>
                                                        </div>
                                                    @endif
                                                @endforeach
                                            </div>
                                        @endforeach
                                    </div>

                                    <div class="col-12 mt-2">
                                        <button type="submit" class="btn btn-sm btn-primary mr-1 mb-1">
                                            <input type="hidden" value="access_backend" name="permissions[access_backend]">
                                            <i data-feather="send"></i> {{__('locale.sub_accounts.send_invitation')}}
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

@section('vendor-script')
    <script src="{{ asset(mix('vendors/js/forms/select/select2.full.min.js')) }}"></script>
@endsection

@section('page-script')
    <script>
      let firstInvalid = $("form").find(".is-invalid").eq(0),
        addPassword = $("#add_password");
      const selectAll = document.querySelector("#selectAll"),
        checkboxList = document.querySelectorAll("[type=\"checkbox\"]");

      selectAll.addEventListener("change", t => {
        checkboxList.forEach(e => {
          // Check if the checkbox is not the #add_password checkbox
          if (e.id !== "add_password") {
            e.checked = t.target.checked;
          }
        });
      });

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
          dropdownAutoWidth: true,
          width: "100%",
          dropdownParent: $this.parent()
        });
      });

      $(document).ready(function() {
        addPassword.on("change", function() {
          if ($(this).is(":checked")) {
            $(".password-section").removeClass("d-none");
            $("#password").attr("required", true);
            $("#password_confirmation").attr("required", true);
          } else {
            $(".password-section").addClass("d-none");
            $("#password").removeAttr("required");
            $("#password_confirmation").removeAttr("required");
          }
        });

        // Trigger once on load in case of validation fail
        if (addPassword.is(":checked")) {
          $(".password-section").removeClass("d-none");
        }
      });
    </script>
@endsection
