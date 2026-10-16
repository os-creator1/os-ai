{{--
    Home V2 §F — Ask MotionGrove.

    A compact request box with contextual suggestions. Typing a question (or choosing a suggestion) opens a
    conversation INSIDE this card — not a global widget, not a second assistant. The conversation is saved on the
    server (per customer, per Business), so minimising it or refreshing Home never loses it.

    No model is called when Home loads. A question is answered by the assistant (config/assistant.php bounds), which
    reads only what this actor may see through each module's own reader, and anything that would change data is
    PREPARED, shown in full, and done only after the customer presses Confirm. Suggestions this actor cannot use are
    absent, never disabled. Everything dynamic is rendered by public/js/home/ask-assistant.js with textContent.
--}}
<section class="mb-2 home-ask" aria-labelledby="home-ask-heading" data-band="ask" data-role="home-ask"
         data-assistant-show="{{ $home['ask']['assistant']['show'] }}"
         data-assistant-message="{{ $home['ask']['assistant']['message'] }}"
         data-assistant-approve="{{ $home['ask']['assistant']['approve'] }}"
         data-assistant-decline="{{ $home['ask']['assistant']['decline'] }}"
         data-assistant-delete="{{ $home['ask']['assistant']['delete'] }}">
    <x-card class="home-ask-card">
        <h2 class="visually-hidden" id="home-ask-heading">Ask MotionGrove</h2>

        <form class="home-ask-form" data-role="ask-form" data-ask-url="{{ $home['ask']['url'] }}" action="{{ $home['ask']['url'] }}" method="POST">
            @csrf
            <span class="home-ask-icon" aria-hidden="true">✦</span>
            <label for="home-ask-input" class="visually-hidden">What would you like to ask or have MotionGrove do?</label>
            <input type="text" id="home-ask-input" name="q" class="home-ask-input" maxlength="{{ (int) config('assistant.question_max_chars') }}" autocomplete="off"
                   placeholder="Ask MotionGrove anything about your business..." data-role="ask-input" required>
            <button type="button" class="btn btn-link btn-sm p-0 home-ask-resume" data-role="ask-resume" hidden>Continue your conversation</button>
            <x-button type="submit" data-role="ask-submit">Ask</x-button>
        </form>

        @php $chips = array_slice(array_values(array_filter($home['ask']['suggestions'], fn ($s) => $s['available'])), 0, 4); @endphp
        <ul class="list-unstyled d-flex flex-wrap gap-50 mt-1 mb-0" data-role="ask-suggestions" aria-label="Suggested questions">
            @foreach($chips as $suggestion)
                <li>
                    <button type="button" class="home-chip" data-role="ask-suggestion" data-suggestion="{{ $suggestion['key'] }}"
                            data-q="{{ $suggestion['prompt'] }}">{{ $suggestion['label'] }}</button>
                </li>
            @endforeach
        </ul>
        {{-- The conversation. Hidden until the customer asks; "Back to dashboard" only hides it. --}}
        <div class="home-ask-panel mt-1" data-role="ask-conversation" role="region" aria-label="Your conversation with MotionGrove" hidden>
            <div class="home-ask-panel-head d-flex align-items-center justify-content-between gap-50">
                <strong>Conversation</strong>
                <div class="d-flex gap-50">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-role="ask-new">New conversation</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-role="ask-delete" hidden>Delete conversation</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-role="ask-minimize">Back to dashboard</button>
                </div>
            </div>
            <div class="home-ask-banner" data-role="ask-banner" role="status" hidden></div>
            <div class="home-ask-thread" data-role="ask-thread" role="log" aria-live="polite" aria-relevant="additions"></div>
            <p class="home-ask-working text-muted mb-0" data-role="ask-working" role="status" hidden>MotionGrove is working on your question…</p>
        </div>
    </x-card>
</section>
