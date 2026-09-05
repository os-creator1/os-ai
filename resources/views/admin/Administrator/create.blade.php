@extends('layouts/contentLayoutMaster')

@section('title', __('locale.administrator.create_administrator'))

@section('vendor-style')
    <!-- vendor css files -->
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
@endsection

@section('content')
    <!-- Basic Vertical form layout section start -->
    <section id="basic-vertical-layouts">
        <div class="row match-height">
            <div class="col-md-6 col-12">

                <x-card :title="__('locale.administrator.create_administrator')">
                            <form class="form form-vertical" action="{{ route('admin.administrators.store') }}" method="post" enctype="multipart/form-data">
                                @csrf
                                <div class="row">

                                    <div class="col-12">
                                        <x-input name="email" type="email" :label="__('locale.labels.email')" value="{{ old('email') }}" :placeholder="__('locale.labels.email')" :error="$errors->first('email')" required />
                                    </div>


                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label class="form-label required" for="password">{{ __('locale.labels.password') }}</label>
                                            <div class="input-group input-group-merge form-password-toggle">
                                                <input type="password" id="password" class="form-control @error('password') is-invalid @enderror" value="{{ old('password') }}" name="password" required/>
                                                <span class="input-group-text cursor-pointer"><x-ds-icon name="eye" /></span>
                                            </div>

                                            @error('password')
                                            <p><small class="text-danger">{{ $message }}</small></p>
                                            @enderror
                                        </div>

                                    </div>

                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label class="form-label required" for="password_confirmation">{{ __('locale.labels.password_confirmation') }}</label>
                                            <div class="input-group input-group-merge form-password-toggle">
                                                <input type="password" id="password_confirmation" class="form-control @error('password_confirmation') is-invalid @enderror"
                                                       value="{{ old('password_confirmation') }}"
                                                       name="password_confirmation" required/>
                                                <span class="input-group-text cursor-pointer"><x-ds-icon name="eye" /></span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <x-input name="first_name" :label="__('locale.labels.first_name')" value="{{ old('first_name') }}" :placeholder="__('locale.labels.first_name')" :error="$errors->first('first_name')" autocomplete="first_name" required />
                                    </div>

                                    <div class="col-12">
                                        <x-input name="last_name" :label="__('locale.labels.last_name')" value="{{ old('last_name') }}" :placeholder="__('locale.labels.last_name')" :error="$errors->first('last_name')" autocomplete="last_name" />
                                    </div>

                                    <div class="col-12">
                                        <x-input name="phone" type="number" :label="__('locale.labels.phone')" :placeholder="__('locale.labels.phone')" :error="$errors->first('phone')" required />
                                    </div>


                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="role" class="form-label required">{{__('locale.labels.roles')}}</label>
                                            <select class="select2 w-100" id="role" name="roles[]">
                                                @foreach($roles as $role)
                                                    <option value="{{ $role->id }}"> {{ $role->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        @error('roles')
                                        <p><small class="text-danger">{{ $message }}</small></p>
                                        @enderror
                                    </div>

                                    <div class="col-12">
                                        <x-select name="status" :label="__('locale.labels.status')" :options="[1 => __('locale.labels.active'), 0 => __('locale.labels.inactive')]" />
                                    </div>

                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="image" class="form-label">{{__('locale.labels.image')}}</label>
                                            <input type="file" name="image" class="form-control" id="image" accept="image/*"/>
                                            @error('image')
                                            <p><small class="text-danger">{{ $message }}</small></p>
                                            @enderror
                                            <p><small class="text-primary"> {{__('locale.customer.profile_image_size')}} </small></p>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="mb-1">
                                            <p class="mb-0">{{ __('locale.administrator.create_customer_account') }}?</p>
                                            <p><small class="text-primary">{{ __('locale.administrator.create_customer_account_associated_admin') }}</small></p>

                                            <div class="form-check form-switch form-switch-md form-check-primary">
                                                <input type="checkbox" class="form-check-input" id="customer" name="is_customer"/>
                                                <label class="form-check-label" for="customer">
                                                    <span class="switch-icon-left">{{ __('locale.labels.yes') }}</span>
                                                    <span class="switch-icon-right">{{ __('locale.labels.no') }}</span>
                                                </label>
                                            </div>
                                        </div>
                                    </div>


                                    <div class="col-12 mt-1">
                                        <input type="hidden" value="1" name="is_admin">
                                        <x-button type="submit" variant="primary" class="mr-1 mb-1" icon="save">{{__('locale.buttons.save')}}</x-button>
                                    </div>


                                </div>
                            </form>
                </x-card>
            </div>


        </div>
    </section>
    <!-- // Basic Vertical form layout section end -->


@endsection


@section('vendor-script')
    <!-- vendor files -->
    <script src="{{ asset(mix('vendors/js/forms/select/select2.full.min.js')) }}"></script>
@endsection


@section('page-script')

    <script>
        let firstInvalid = $('form').find('.is-invalid').eq(0);

        if (firstInvalid.length) {
            $('body, html').stop(true, true).animate({
                'scrollTop': firstInvalid.offset().top - 200 + 'px'
            }, 200);
        }

        // Basic Select2 select
        $(".select2").each(function () {
            let $this = $(this);
            $this.wrap('<div class="position-relative"></div>');
            $this.select2({
                // the following code is used to disable x-scrollbar when click in select input and
                // take 100% width in responsive also
                dropdownAutoWidth: true,
                width: '100%',
                dropdownParent: $this.parent()
            });
        });
    </script>
@endsection
