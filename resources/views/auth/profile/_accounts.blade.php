{{--
    Your account → Account tab. Same routes, fields and validation as before
    (user.account.update PATCH, user.avatar upload, user.remove_avatar,
    user.account.delete); only the presentation changed. The delete flow keeps
    its original safeguards: the acknowledgement checkbox gates the button
    (enabled by the page script) and the SweetAlert confirmation still runs
    before the POST.
--}}
<div class="sr-card">
    <div class="sr-card__body d-flex align-items-center gap-3 flex-wrap">
        @php($accountPhoto = $user->imagePath())
        @if ($accountPhoto !== '' && is_file($accountPhoto))
            <img src="{{ route('user.avatar') }}" alt="{{ $user->displayName() }}"
                 class="uploadedAvatar rounded-circle flex-shrink-0" height="72" width="72" style="object-fit:cover" />
        @else
            <span class="sr-avatar sr-avatar--lg" aria-hidden="true">{{ $user->initials() }}</span>
        @endif
        <div>
            <div class="fw-bold">{{ $user->displayName() }}</div>
            <div class="sr-card__sub mb-2">{{ __('locale.customer.profile_image_size') }}</div>
            <div class="sr-btn-row">
                @include('auth.profile._update_avatar')
                <button type="button" id="remove-avatar" data-id="{{ $user->uid }}" class="sr-linkbtn">{{ __('locale.labels.remove') }}</button>
            </div>
        </div>
    </div>

    <form action="{{ route('user.account.update') }}" method="post">
        @method('PATCH')
        @csrf

        <div class="sr-section" style="border-top:1px solid var(--color-border)">
            <div class="sr-section__label">
                <h3>Personal details</h3>
                <p>Your name is shown to your team and in messages you send.</p>
            </div>
            <div class="sr-fields">
                <div class="sr-field">
                    <label for="first_name">{{ __('locale.labels.first_name') }} <span class="text-danger">*</span></label>
                    <input type="text" id="first_name" class="form-control @error('first_name') is-invalid @enderror" value="{{ old('first_name', $user->first_name) }}" name="first_name" required>
                    @error('first_name')<p class="sr-error">{{ $message }}</p>@enderror
                </div>
                <div class="sr-field">
                    <label for="last_name">{{ __('locale.labels.last_name') }}</label>
                    <input type="text" id="last_name" class="form-control @error('last_name') is-invalid @enderror" value="{{ old('last_name', $user->last_name) }}" name="last_name">
                    @error('last_name')<p class="sr-error">{{ $message }}</p>@enderror
                </div>
                <div class="sr-field sr-span-2">
                    <label for="email">{{ __('locale.labels.email') }} <span class="text-danger">*</span></label>
                    <input type="email" id="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email', $user->email) }}" name="email" required>
                    @error('email')<p class="sr-error">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        <div class="sr-section">
            <div class="sr-section__label">
                <h3>Preferences</h3>
                <p>How dates, times and the app are shown to you.</p>
            </div>
            <div class="sr-fields">
                <div class="sr-field">
                    <label for="timezone">{{ __('locale.labels.timezone') }} <span class="text-danger">*</span></label>
                    <select class="select2 form-select" id="timezone" name="timezone">
                        @foreach(\App\Library\Tool::allTimeZones() as $timezone)
                            <option value="{{ $timezone['zone'] }}" {{ old('timezone', $user->timezone) == $timezone['zone'] ? 'selected' : null }}>{{ $timezone['text'] }}</option>
                        @endforeach
                    </select>
                    @error('timezone')<p class="sr-error">{{ $message }}</p>@enderror
                </div>
                <div class="sr-field">
                    <label for="locale">{{ __('locale.labels.language') }} <span class="text-danger">*</span></label>
                    <select class="select2 form-select" id="locale" name="locale">
                        @foreach($languages as $language)
                            <option value="{{ $language->code }}" {{ old('locale', $user->locale) == $language->code ? 'selected' : null }}>{{ $language->name }}</option>
                        @endforeach
                    </select>
                    @error('locale')<p class="sr-error">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        <div class="sr-card__foot">
            <span class="sr-req"><span class="text-danger">*</span> Required</span>
            <button type="submit" class="btn btn-primary">{{ __('locale.buttons.save_changes') }}</button>
        </div>
    </form>
</div>

@if( ! Auth::user()->is_super_admin && config('account.can_delete'))
    <div class="sr-card">
        <div class="sr-card__body">
            <div class="d-flex align-items-start gap-3 mb-3">
                <span class="sr-tile"><x-ds-icon name="trash-2" size="18" /></span>
                <div>
                    <h3 class="sr-card__title">Delete account</h3>
                    <p class="sr-card__sub">Once you delete your account, there's no going back.</p>
                </div>
            </div>

            <form id="formAccountDeactivation" class="validate-form" onsubmit="return false">
                <div class="sr-confirm">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="accountActivation" id="accountActivation"
                               data-msg="{{ __('locale.auth.want_to_delete_your_account') }}" />
                        <label class="form-check-label" for="accountActivation">
                            I understand my account will be permanently deleted.
                        </label>
                    </div>
                    <button type="submit" class="btn btn-sr-danger-outline deactivate-account" disabled>Delete account</button>
                </div>
            </form>
        </div>
    </div>
@endif
