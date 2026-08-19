<div class="col-md-6 col-12">
    <div class="form-body">
        <form class="form form-vertical" action="{{ route('admin.settings.general') }}" method="post"
              enctype="multipart/form-data">
            @csrf
            <div class="row">

                <div class="col-12">
                    <div class="mb-1">
                        <label for="app_name"
                               class="form-label required">{{ __('locale.settings.application_name') }}</label>
                        <input type="text" id="app_name" name="app_name" class="form-control"
                               value="{{ config('app.name') }}" required>
                        @error('app_name')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>
                </div>

                <div class="col-12">
                    <div class="mb-1">
                        <label for="app_title"
                               class="form-label required">{{ __('locale.settings.application_title') }}</label>
                        <input type="text" id="app_title" name="app_title" class="form-control"
                               value="{{ config('app.title') }}" required>
                        @error('app_title')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>
                </div>

                <div class="col-12">
                    <div class="mb-1">
                        <label for="app_keyword"
                               class="form-label">{{ __('locale.settings.application_keyword') }}</label>
                        <input type="text" id="app_keyword" name="app_keyword" class="form-control"
                               value="{{ config('app.keyword') }}">
                        @error('app_keyword')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>
                </div>

                <div class="col-12">
                    <div class="mb-1">
                        <label for="company_address"
                               class="form-label required">{{ __('locale.labels.address') }}</label>
                        <textarea id="company_address" name="company_address" rows="6" class="form-control"
                                  required>{!! \App\Helpers\Helper::app_config('company_address') !!}</textarea>
                        @error('company_address')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>
                </div>


                <div class="col-12">
                    <div class="mb-1">
                        <label for="footer_company_name" class="form-label">Footer company name</label>
                        <input type="text" id="footer_company_name" name="footer_company_name" class="form-control"
                               value="{{ config('app.footer_company_name') }}">
                        <p><small class="text-primary">Optional. Falls back to the application name when not set.</small></p>
                        @error('footer_company_name')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>
                </div>

                <div class="col-12">
                    <div class="mb-1">
                        <label for="footer_copyright_text" class="form-label">Footer copyright wording</label>
                        <input type="text" id="footer_copyright_text" name="footer_copyright_text" class="form-control"
                               value="{{ config('app.footer_copyright_text') }}" placeholder="e.g. All rights reserved">
                        <p><small class="text-primary">Optional short suffix shown after the copyright line. The copyright year is always current and computed automatically — it is not an input here.</small></p>
                        <p><small class="text-muted">Preview: <x-branding-footer /></small></p>
                        @error('footer_copyright_text')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>
                </div>

                <div class="col-12">
                    <div class="mb-1">
                        <label for="app_logo" class="form-label">{{ __('locale.settings.logo') }}</label>
                        <div class="mb-1"><x-branding-logo variant="full" background="light" /></div>
                        <input type="file" name="app_logo" class="form-control" id="app_logo" accept="image/png,image/jpeg,image/webp" />
                        <p><small class="text-primary"> {{__('locale.settings.logo_size')}} </small></p>

                        @error('app_logo')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>
                </div>

                <div class="col-12">
                    <div class="mb-1">
                        <label for="logo_compact" class="form-label">Compact / sidebar logo</label>
                        <div class="mb-1"><x-branding-logo variant="compact" background="light" /></div>
                        <input type="file" name="logo_compact" class="form-control" id="logo_compact" accept="image/png,image/jpeg,image/webp" />
                        <p><small class="text-primary">Optional. Used for the collapsed sidebar and mobile header. Falls back to the full logo when not set.</small></p>
                        @error('logo_compact')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>
                </div>

                <div class="col-12">
                    <div class="mb-1">
                        <label for="logo_dark" class="form-label">Dark-background logo</label>
                        <div class="mb-1"><x-branding-logo variant="full" background="dark" /></div>
                        <input type="file" name="logo_dark" class="form-control" id="logo_dark" accept="image/png,image/jpeg,image/webp" />
                        <p><small class="text-primary">Optional. Used wherever the logo is placed on a dark background. Falls back to the full, light-background logo when not set.</small></p>
                        @error('logo_dark')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>
                </div>

                <div class="col-12">
                    <div class="mb-1">
                        <label for="app_favicon" class="form-label">{{ __('locale.settings.favicon') }}</label>
                        <div class="mb-1"><x-branding-favicon /></div>
                        <input type="file" name="app_favicon" class="form-control" id="app_favicon" accept="image/png,image/jpeg,image/webp,image/x-icon" />
                        <p><small class="text-primary"> {{__('locale.settings.favicon_size')}} </small></p>
                        @error('app_favicon')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>
                </div>

                <div class="col-12">
                    <div class="mb-1">
                        <label for="auth_illustration" class="form-label">Authentication-page illustration</label>
                        <input type="file" name="auth_illustration" class="form-control" id="auth_illustration" accept="image/png,image/jpeg,image/webp" />
                        <p><small class="text-primary">Optional. Falls back to the bundled illustration when not set.</small></p>
                        @error('auth_illustration')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>
                </div>

                <div class="col-12">
                    <div class="mb-1">
                        <label for="installer_illustration" class="form-label">Installer illustration</label>
                        <div class="mb-1"><x-branding-illustration surface="installer" /></div>
                        <input type="file" name="installer_illustration" class="form-control" id="installer_illustration" accept="image/png,image/jpeg,image/webp" />
                        <p><small class="text-primary">Optional. Falls back to the bundled illustration when not set.</small></p>
                        @error('installer_illustration')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>
                </div>


                <div class="col-12">
                    <div class="mb-1">
                        <label for="country" class="form-label required">{{__('locale.labels.country')}}</label>
                        <select class="form-select select2" id="country" name="country">
                            @foreach(Helper::countries() as $country)
                                <option value="{{$country['name']}}" {{ config('app.country') == $country['name'] ? 'selected': null  }}> {{$country['name']}} </option>
                            @endforeach
                        </select>
                    </div>
                    @error('country')
                    <p><small class="text-danger">{{ $message }}</small></p>
                    @enderror
                </div>

                <div class="col-12">
                    <div class="mb-1">
                        <label for="timezone" class="form-label required">{{__('locale.labels.timezone')}}</label>
                        <select class="form-select select2" id="timezone" name="timezone">
                            @foreach(\App\Helpers\Helper::timezoneList() as $value => $label)
                                <option value="{{$value}}"
                                        @if(config('app.timezone')==$value) selected @endif>{{$label}}</option>
                            @endforeach
                        </select>
                    </div>
                    @error('timezone')
                    <p><small class="text-danger">{{ $message }}</small></p>
                    @enderror
                </div>


                <div class="col-12">
                    <div class="mb-1">
                        <label for="date_format" class="form-label required">{{__('locale.labels.date_format')}}</label>
                        <select class="form-select select2" id="date_format" name="date_format">
                            <option value="d/m/Y" @if(config('app.date_format') == 'd/m/Y') selected @endif>15/05/2016
                            </option>
                            <option value="d.m.Y" @if(config('app.date_format') == 'd.m.Y') selected @endif>15.05.2016
                            </option>
                            <option value="d-m-Y" @if(config('app.date_format') == 'd-m-Y') selected @endif>15-05-2016
                            </option>
                            <option value="m/d/Y" @if(config('app.date_format') == 'm/d/Y') selected @endif>05/15/2016
                            </option>
                            <option value="Y/m/d" @if(config('app.date_format') == 'Y/m/d') selected @endif>2016/05/15
                            </option>
                            <option value="Y-m-d" @if(config('app.date_format') == 'Y-m-d') selected @endif>2016-05-15
                            </option>
                            <option value="M d Y" @if(config('app.date_format') == 'M d Y') selected @endif>May 15
                                2016
                            </option>
                            <option value="d M Y" @if(config('app.date_format') == 'd M Y') selected @endif>15 May
                                2016
                            </option>
                            <option value="jS M y" @if(config('app.date_format') == 'jS M y') selected @endif>15th May
                                16
                            </option>
                        </select>
                    </div>
                    @error('date_format')
                    <p><small class="text-danger">{{ $message }}</small></p>
                    @enderror
                </div>


                <div class="mb-1">
                    <label class="form-label required">{{ __('locale.settings.time_format') }}</label>
                    <select class="form-select" name="time_format">
                        <option value="g:i A" {{ config('app.time_format') == 'g:i A' ? 'selected' : '' }}>12-hour
                        </option>
                        <option value="H:i" {{ config('app.time_format') == 'H:i' ? 'selected' : '' }}>24-hour</option>
                    </select>
                </div>


                <div class="col-12">
                    <div class="mb-1">
                        <label for="language"
                               class="form-label required">{{__('locale.labels.default')}} {{__('locale.labels.language')}}</label>
                        <select class="form-select select2" id="language" name="language">
                            @foreach($language as $lang)
                                <option value="{{$lang->code}}"
                                        @if($lang->code == config('app.locale')) selected @endif>{{$lang->name}}</option>
                            @endforeach
                        </select>
                    </div>
                    @error('language')
                    <p><small class="text-danger">{{ $message }}</small></p>
                    @enderror
                </div>

                <div class="col-12">
                    <div class="mb-1">
                        <label for="custom_script">{{ __('locale.settings.custom_script') }}</label>
                        <textarea id="custom_script" name="custom_script"
                                  class="form-control">{!! Helper::app_config('custom_script') !!}</textarea>
                        @error('custom_script')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>
                </div>

                {{--Version 3.5--}}


                {{--                <div class="col-12">--}}
                {{--                    <div class="mb-1">--}}
                {{--                        <label for="terms_of_use" class="form-label">{{ __('locale.labels.terms_of_use') }}</label>--}}
                {{--                        <input type="url" id="terms_of_use" name="terms_of_use" class="form-control"--}}
                {{--                               value="{{ config('app.terms_of_use') }}">--}}
                {{--                        @error('terms_of_use')--}}
                {{--                        <p><small class="text-danger">{{ $message }}</small></p>--}}
                {{--                        @enderror--}}
                {{--                    </div>--}}
                {{--                </div>--}}


                {{--                <div class="col-12">--}}
                {{--                    <div class="mb-1">--}}
                {{--                        <label for="privacy_policy" class="form-label">{{ __('locale.labels.privacy_policy') }}</label>--}}
                {{--                        <input type="url" id="privacy_policy" name="privacy_policy" class="form-control"--}}
                {{--                               value="{{ config('app.privacy_policy') }}">--}}
                {{--                        @error('privacy_policy')--}}
                {{--                        <p><small class="text-danger">{{ $message }}</small></p>--}}
                {{--                        @enderror--}}
                {{--                    </div>--}}
                {{--                </div>--}}


                <div class="col-12 mt-2">
                    <button type="submit" class="btn btn-primary mb-1">
                        <i data-feather="save"></i> {{__('locale.buttons.save')}}
                    </button>
                </div>

            </div>
        </form>
    </div>
</div>
