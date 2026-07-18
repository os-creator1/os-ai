<div class="col-12">
    <div class="form-body">
        <form class="form form-vertical" action="{{ route('admin.settings.email') }}" method="post">
            @csrf
            <div class="row">

                <div class="col-md-6 col-12">
                    <div class="mb-1">
                        <label for="driver" class="form-label required">{{ __('locale.settings.method_for_sending') }}</label>
                        <select class="form-select gateway" name="driver" id="driver">
                            <option value="sendmail" @if(env('MAIL_MAILER') == 'sendmail') selected @endif>Sendmail</option>
                            <option value="smtp" @if(env('MAIL_MAILER') == 'smtp') selected @endif> SMTP</option>
                        </select>
                        @error('driver')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>
                </div>

                <div class="col-md-6"></div>


                <div class="col-md-4 col-12 show-smtp">
                    <div class="mb-1">
                        <label for="host" class="form-label required"> {{ __('locale.labels.host_name') }}</label>
                        <input type="text" class="form-control" name="host" value="{{ env('MAIL_HOST') }}">

                        @error('host')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror

                    </div>
                </div>


                <div class="col-md-4 col-12 show-smtp">
                    <div class="mb-1">
                        <label for="port" class="form-label required"> {{ __('locale.labels.port') }}</label>
                        <input type="text" class="form-control" name="port" value="{{ env('MAIL_PORT') }}">

                        @error('port')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror

                    </div>
                </div>

                <div class="col-md-4 col-12 show-smtp">
                    <div class="mb-1">
                        <label for="encryption" class="form-label required">{{__('locale.labels.encryption')}}</label>
                        <select name="encryption" class="form-select" id="encryption">
                            <option value="tls" @if(env('MAIL_ENCRYPTION') == 'tls')  selected @endif>TLS</option>
                            <option value="ssl" @if(env('MAIL_ENCRYPTION') == 'ssl')selected @endif>SSL</option>
                        </select>

                        @error('port')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>
                </div>


                <div class="col-md-6 col-12 show-smtp">
                    <div class="mb-1">
                        <label for="username" class="form-label required"> {{ __('locale.labels.username') }}</label>
                        <input type="text" class="form-control" name="username" value="{{ env('MAIL_USERNAME') }}">

                        @error('username')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror

                    </div>
                </div>


                <div class="col-md-6 col-12 show-smtp">
                    <div class="mb-1">
                        <label for="password" class="form-label required"> {{ __('locale.labels.password') }}</label>
                        <input type="text" class="form-control" name="password" value="{{ env('MAIL_PASSWORD') }}">

                        @error('password')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror

                    </div>
                </div>

                <div class="col-md-6 col-12">
                    <div class="mb-1">
                        <label for="from_email" class="form-label required">{{ __('locale.settings.from_email') }}</label>
                        <input type="email" id="from_email" name="from_email" class="form-control" value="{{config('mail.from.address')}}" required>
                        @error('from_email')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>
                </div>


                <div class="col-md-6 col-12">
                    <div class="mb-1">
                        <label for="from_name" class="form-label required">{{ __('locale.settings.from_name') }}</label>
                        <input type="text" id="from_name" name="from_name" class="form-control" value="{{ config('mail.from.name') }}" required>
                        @error('from_name')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>
                </div>

                <div class="col-12 mt-2">
                    <button type="submit" class="btn btn-primary mb-1">
                        <i data-feather="save"></i> {{__('locale.buttons.save')}}
                    </button>
                </div>

            </div>
        </form>
    </div>
</div>
