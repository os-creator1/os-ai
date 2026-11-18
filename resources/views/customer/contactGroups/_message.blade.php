<div class="mgx-gd-split">
    <section class="mgx-card mgx-gd-card mgx-gd-form">
        <form class="mgx-gd-stack" action="{{ \App\Library\CrmRouting::route('contacts.message', $contact->uid) }}" method="post">
            @csrf
            <div class="mgx-fl">
                <label for="message_form">{{ __('locale.labels.message_form') }}</label>
                <div class="mgx-sel">
                    <select class="mgx-in" name="message_form" required id="message_form">
                        <option value="signup_sms">{{ __('locale.contacts.signup_sms') }}</option>
                        <option value="welcome_sms">{{ __('locale.contacts.welcome_message') }}</option>
                        <option value="unsubscribe_sms">{{ __('locale.contacts.unsubscribe_sms') }}</option>
                    </select>
                    <svg class="mgx-ic" viewBox="0 0 24 24"><path d="M7 10l5 5 5-5"/></svg>
                </div>
                @error('message_form')
                <p class="mgx-err is-on">{{ $message }}</p>
                @enderror
            </div>

            <div class="mgx-fl">
                <label for="text_message">{{ __('locale.labels.message') }}</label>
                <div class="mgx-compose">
                    <textarea class="mgx-compose-text" name="message" rows="4" id="text_message" required>{{ $contact->signup_sms }}</textarea>
                    <div class="mgx-compose-bar">
                        <span class="mgx-mut">Insert:</span>
                        @foreach ($contact->getFields as $field)
                            <button type="button" class="mgx-tok is-btn" data-insert-tag="{{ $field->tag }}">+ {{ $field->label }}</button>
                        @endforeach
                        <span class="mgx-mut mgx-compose-count" data-role="message-count" aria-live="polite"></span>
                    </div>
                </div>
                @error('message')
                <p class="mgx-err is-on">{{ $message }}</p>
                @enderror
            </div>

            <div class="mgx-gd-actions">
                <button type="submit" class="mgx-btn is-primary">{{ __('locale.buttons.save') }} message</button>
            </div>
        </form>
    </section>

    <aside class="mgx-card mgx-gd-card mgx-gd-links">
        <b>Sign-up links</b>
        <span class="mgx-mut">Share these so people can join or leave this group.</span>
        @foreach ([['Join link', route('contacts.subscribe_url', $contact->uid), 'join-link'], ['Leave link', route('contacts.unsubscribe_url', $contact->uid), 'leave-link']] as [$linkLabel, $linkUrl, $linkId])
            <div class="mgx-fl">
                <label for="{{ $linkId }}">{{ $linkLabel }}</label>
                <div class="mgx-copyrow">
                    <input class="mgx-in" id="{{ $linkId }}" type="text" value="{{ $linkUrl }}" readonly>
                    <button type="button" class="mgx-btn is-secondary" data-copy-target="#{{ $linkId }}"><svg class="mgx-ic" viewBox="0 0 24 24"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></svg><span data-copy-label>{{ __('locale.buttons.copy') }}</span></button>
                </div>
            </div>
        @endforeach
    </aside>
</div>
