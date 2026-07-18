@php use App\Library\Tool; @endphp
@extends('layouts/contentLayoutMaster')

@section('title', __('locale.sub_accounts.update_sub_account'))

@section('vendor-style')
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
@endsection

@section('content')
    <section id="basic-vertical-layouts">
        <div class="row match-height">
            <div class="col-md-6 col-12">

                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">{{ __('locale.sub_accounts.update_sub_account') }}</h4>
                    </div>
                    <div class="card-content">
                        <div class="card-body">
                            <form class="form form-vertical"
                                  action="{{ route('customer.sub_accounts.update', $subAccount->uid) }}"
                                  method="post" enctype="multipart/form-data">
                                @csrf

                                <div class="row">

                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="email"
                                                   class="required form-label">{{__('locale.labels.email')}}</label>
                                            <input type="email" id="email" name="email"
                                                   class="form-control @error('email') is-invalid @enderror"
                                                   value="{{ old('email', $subAccount->email) }}" required>
                                            @error('email')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="first_name"
                                                   class="required form-label">{{__('locale.labels.first_name')}}</label>
                                            <input type="text" id="first_name" name="first_name"
                                                   class="form-control @error('first_name') is-invalid @enderror"
                                                   value="{{ old('first_name', $subAccount->first_name) }}" required>
                                            @error('first_name')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="last_name"
                                                   class="form-label">{{__('locale.labels.last_name')}}</label>
                                            <input type="text" id="last_name" name="last_name"
                                                   class="form-control @error('last_name') is-invalid @enderror"
                                                   value="{{ old('last_name', $subAccount->last_name) }}">
                                            @error('last_name')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="image" class="form-label">{{__('locale.labels.image')}}</label>
                                            <input type="file" name="image" class="form-control" id="image"
                                                   accept="image/*" />
                                            @error('image')
                                            <div class="text-danger">{{ $message }}</div>
                                            @enderror

                                            @if ($subAccount->image)
                                                <img src="{{ route('customer.sub_accounts.avatar', $subAccount->uid) }}"
                                                     alt="Profile"
                                                     class="mt-1" width="120" />
                                            @endif

                                            <p>
                                                <small class="text-primary">{{__('locale.customer.profile_image_size')}}</small>
                                            </p>
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
                                                    {{-- Only show permissions that the main customer can assign --}}
                                                    <div class="form-check me-3 me-lg-5 mt-1">
                                                        <input type="checkbox"
                                                               value="{{ $permission['name'] }}"
                                                               name="permissions[]"
                                                               class="form-check-input"
                                                               id="{{ $permission['name'] }}"
                                                               @if($permission['name'] == 'access_backend') disabled checked @endif
                                                               {{-- Check the box if the sub-account has this permission --}}
                                                               @if(is_array($existing_permission) && in_array($permission['name'], $existing_permission)) checked @endif>
                                                        <label class="form-check-label text-uppercase"
                                                               for="{{ $permission['name'] }}">
                                                            {{ __('locale.permission.'.$permission['display_name']) }}
                                                        </label>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endforeach
                                    </div>
                                    <div class="col-12 mt-2">
                                        <button type="submit" class="btn btn-primary mr-1 mb-1">
                                            <input type="hidden" value="access_backend" name="permissions[access_backend]">
                                            <i data-feather="save"></i> {{__('locale.buttons.update')}}
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
      let firstInvalid = $("form").find(".is-invalid").eq(0);

      const selectAll = document.querySelector("#selectAll"),
        checkboxList = document.querySelectorAll("[type=\"checkbox\"]");
      selectAll.addEventListener("change", t => {
        checkboxList.forEach(e => {
          e.checked = t.target.checked;
        });
      });

      if (firstInvalid.length) {
        $("html, body").animate({
          scrollTop: firstInvalid.offset().top - 200
        }, 200);
      }

      $(".select2").each(function() {
        let $this = $(this);
        $this.wrap("<div class=\"position-relative\"></div>");
        $this.select2({
          dropdownAutoWidth: true,
          width: "100%",
          dropdownParent: $this.parent()
        });
      });
    </script>
@endsection
