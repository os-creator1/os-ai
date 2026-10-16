{{--
    Your account → Security tab: change password. Same route and validation
    (ChangePasswordRequest: current password must match, new password
    confirmed, different from the current one, Password::default()). The
    requirement line below is read from the live Password::default() rule so it
    can never drift from what the server enforces.
--}}
@php
    $passwordRule = \Illuminate\Validation\Rules\Password::default();
    $minLength = 8;
    try {
        $minProp = new \ReflectionProperty($passwordRule, 'min');
        $minLength = (int) $minProp->getValue($passwordRule);
    } catch (\Throwable $e) {
        // keep the documented fallback
    }
@endphp

<div class="sr-card">
    <form action="{{ route('user.account.change.password') }}" method="post">
        @csrf
        <div class="sr-section">
            <div class="sr-section__label">
                <h3>Change password</h3>
                <p>Enter your current password, then choose a new one.</p>
            </div>
            <div class="sr-fields sr-fields--one">
                <div class="sr-field">
                    <label for="current_password">{{ __('locale.labels.current_password') }} <span class="text-danger">*</span></label>
                    <div class="input-group input-group-merge form-password-toggle">
                        <input type="password" id="current_password" autocomplete="current-password"
                               class="form-control @error('current_password') is-invalid @enderror" name="current_password">
                        <span class="input-group-text cursor-pointer"><x-ds-icon name="eye" /></span>
                    </div>
                    @error('current_password')<p class="sr-error">{{ $message }}</p>@enderror
                </div>

                <div class="sr-field">
                    <label for="password">{{ __('locale.labels.new_password') }} <span class="text-danger">*</span></label>
                    <div class="input-group input-group-merge form-password-toggle">
                        <input type="password" id="password" autocomplete="new-password"
                               class="form-control @error('password') is-invalid @enderror" name="password">
                        <span class="input-group-text cursor-pointer"><x-ds-icon name="eye" /></span>
                    </div>
                    @error('password')
                        <p class="sr-error">{{ $message }}</p>
                    @else
                        <p class="sr-hint">At least {{ $minLength }} characters, and different from your current password.</p>
                    @enderror
                </div>

                <div class="sr-field">
                    <label for="password_confirmation">{{ __('locale.labels.new_password_confirmation') }} <span class="text-danger">*</span></label>
                    <div class="input-group input-group-merge form-password-toggle">
                        <input type="password" id="password_confirmation" autocomplete="new-password"
                               class="form-control @error('password_confirmation') is-invalid @enderror" name="password_confirmation">
                        <span class="input-group-text cursor-pointer"><x-ds-icon name="eye" /></span>
                    </div>
                    @error('password_confirmation')<p class="sr-error">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        <div class="sr-card__foot">
            <span class="sr-req"><span class="text-danger">*</span> Required</span>
            <button type="submit" class="btn btn-primary">Update password</button>
        </div>
    </form>
</div>
