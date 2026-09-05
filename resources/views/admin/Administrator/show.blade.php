@extends('layouts/contentLayoutMaster')

@section('title', __('locale.administrator.update_administrator'))

@section('vendor-style')
    <!-- vendor css files -->
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
@endsection

@section('content')
    <!-- Basic Vertical form layout section start -->
    <section id="basic-vertical-layouts">
        <div class="row match-height">
            <div class="col-md-6 col-12">

                <x-card :title="__('locale.administrator.update_administrator')">
                            <form class="form form-vertical" action="{{ route('admin.administrators.update', $administrator->uid)  }}" method="post" enctype="multipart/form-data">
                                @method('PATCH')
                                @csrf
                                <div class="row">

                                    <div class="col-12">
                                        <x-input name="email" type="email" :label="__('locale.labels.email')" value="{{ $administrator->email }}" :error="$errors->first('email')" required />
                                    </div>


                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label class="form-label" for="password">{{ __('locale.labels.password') }}</label>
                                            <div class="input-group input-group-merge form-password-toggle">
                                                <input type="password" id="password" class="form-control @error('password') is-invalid @enderror" value="{{ old('password') }}" name="password">
                                                <span class="input-group-text cursor-pointer"><x-ds-icon name="eye" /></span>
                                            </div>

                                            @if($errors->has('password'))
                                                <p><small class="text-danger">{{ $errors->first('password') }}</small></p>
                                            @else
                                                <p><small class="text-primary"> {{__('locale.customer.leave_blank_password')}} </small></p>
                                            @endif
                                        </div>

                                    </div>

                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label class="form-label" for="password_confirmation">{{ __('locale.labels.password_confirmation') }}</label>
                                            <div class="input-group input-group-merge form-password-toggle">

                                                <input type="password" id="password_confirmation"
                                                       class="form-control @error('password_confirmation') is-invalid @enderror"
                                                       value="{{ old('password_confirmation') }}"
                                                       name="password_confirmation"
                                                >

                                                <span class="input-group-text cursor-pointer"><x-ds-icon name="eye" /></span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <x-input name="first_name" :label="__('locale.labels.first_name')" value="{{ $administrator->first_name }}" :error="$errors->first('first_name')" required />
                                    </div>

                                    <div class="col-12">
                                        <x-input name="last_name" :label="__('locale.labels.last_name')" value="{{ $administrator->last_name }}" :error="$errors->first('last_name')" />
                                    </div>

                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="role" class="form-label required">{{__('locale.labels.roles')}}</label>
                                            <select class="select2 w-100" id="role" name="roles[]">
                                                @foreach($roles as $role)
                                                    <option value="{{ $role->id }}" @if($get_roles == $role->id) selected @endif> {{ $role->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        @error('roles')
                                        <p><small class="text-danger">{{ $message }}</small></p>
                                        @enderror
                                    </div>


                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="timezone" class="form-label required">{{__('locale.labels.timezone')}}</label>
                                            <select class="select2 w-100" id="timezone" name="timezone">
                                                @foreach(\App\Library\Tool::allTimeZones() as $timezone)
                                                    <option value="{{$timezone['zone']}}" {{ $administrator->timezone == $timezone['zone'] ? 'selected': null }}> {{ $timezone['text'] }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        @error('timezone')
                                        <p><small class="text-danger">{{ $message }}</small></p>
                                        @enderror
                                    </div>


                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="locale" class="form-label required">{{__('locale.labels.language')}}</label>
                                            <select class="select2 w-100" id="locale" name="locale">
                                                @foreach($languages as $language)
                                                    <option value="{{ $language->code }}" {{ $administrator->locale == $language->code ? 'selected': null }}> {{ $language->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        @error('locale')
                                        <p><small class="text-danger">{{ $message }}</small></p>
                                        @enderror
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

                                    <div class="col-12 mt-1">
                                        <x-button type="submit" variant="primary" class="mr-1 mb-1" icon="save">{{__('locale.buttons.update')}}</x-button>
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
        let showHideInput = $('.show_hide_password input');
        let showHideIcon = $('.show_hide_password i');

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
