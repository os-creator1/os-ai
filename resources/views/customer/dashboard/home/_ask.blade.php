{{--
    Home V2 §F — Ask MotionGrove.

    ONE element, two states. Collapsed it is the compact request box with contextual suggestions. Opened (typing a
    question, choosing a suggestion, "Continue your conversation") the SAME card grows into the full conversation:
    the rest of Home recedes (command-center.blade.php's `is-chat-open`), the header appears, the thread fills the card and
    this form settles at the bottom as the composer. Not a global widget, not a second assistant: the conversation is
    saved on the server (per customer, per Business), so going back to Home or refreshing never loses it.

    No model is called when Home loads, when the card opens, scrolls or closes. A question is answered by the assistant
    (config/assistant.php bounds), which reads only what this actor may see through each module's own reader, and
    anything that would change data is PREPARED, shown in full, and done only after the customer presses Confirm.
    Suggestions this actor cannot use are absent, never disabled. Everything dynamic is rendered by
    public/js/home/ask-assistant.js with textContent.
--}}
<section class="mb-2 home-ask" aria-labelledby="home-ask-heading" data-band="ask" data-role="home-ask"
         data-assistant-show="{{ $home['ask']['assistant']['show'] }}"
         data-assistant-conversations="{{ $home['ask']['assistant']['conversations'] }}"
         data-assistant-message="{{ $home['ask']['assistant']['message'] }}"
         data-assistant-approve="{{ $home['ask']['assistant']['approve'] }}"
         data-assistant-decline="{{ $home['ask']['assistant']['decline'] }}"
         data-assistant-delete="{{ $home['ask']['assistant']['delete'] }}">
    <x-card class="home-ask-card">
        <div class="home-ask-head" data-role="ask-head">
            <button type="button" class="btn btn-outline-secondary btn-sm home-ask-back" data-role="ask-minimize">
                <span aria-hidden="true">←</span> Back to Home
            </button>
            <span class="home-ask-esc text-caption text-muted">or press <kbd>Esc</kbd></span>
            <h2 class="home-ask-heading" id="home-ask-heading" data-role="ask-title">Ask MotionGrove</h2>
            <div class="home-ask-head-actions">
                <button type="button" class="btn btn-sm home-ask-iconbtn" data-role="ask-history" aria-haspopup="dialog" aria-expanded="false" aria-label="Chat history" title="Chat history">
                    <svg viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3.5 10a6.5 6.5 0 1 0 2-4.7M3 3.5V6h2.5M10 6.5V10l2.5 1.5"/></svg>
                </button>
                <button type="button" class="btn btn-sm home-ask-newchat" data-role="ask-new"><span aria-hidden="true">+</span> New chat</button>
                <button type="button" class="btn btn-link btn-sm text-muted" data-role="ask-delete" hidden>Delete conversation</button>
            </div>
        </div>

        {{-- The conversation. Hidden until the card opens; "Back to Home" only closes it. --}}
        <div class="home-ask-panel" data-role="ask-conversation" role="region" aria-label="Your conversation with MotionGrove" hidden>
            <div class="home-ask-banner" data-role="ask-banner" role="status" hidden></div>
            <div class="home-ask-thread" data-role="ask-thread" role="log" aria-live="polite" aria-relevant="additions"></div>
            <p class="home-ask-working text-muted mb-0" data-role="ask-working" role="status" hidden>MotionGrove is working on your question…</p>

            {{-- Chat history: THIS customer's conversations with MotionGrove in this Business (not the customer inbox). Read-only to open. --}}
            <div class="home-ask-history" data-role="ask-history-panel" role="dialog" aria-label="Chat history" hidden>
                <div class="home-ask-history-head">
                    <strong>Chat history</strong>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-role="ask-history-close">Close</button>
                </div>
                <p class="text-caption text-muted mb-0 px-1" data-role="ask-history-status" role="status" hidden></p>
                <ul class="list-unstyled mb-0 home-ask-history-list" data-role="ask-history-list"></ul>
                <div class="text-center p-1"><button type="button" class="btn btn-sm btn-outline-secondary" data-role="ask-history-more" hidden>Show older conversations</button></div>
            </div>
        </div>

        <form class="home-ask-form" data-role="ask-form" data-ask-url="{{ $home['ask']['url'] }}" action="{{ $home['ask']['url'] }}" method="POST">
            @csrf
            <span class="home-ask-icon" aria-hidden="true">✦</span>
            <label for="home-ask-input" class="visually-hidden">What would you like to ask or have MotionGrove do?</label>
            <textarea id="home-ask-input" name="q" rows="1" class="home-ask-input" maxlength="{{ (int) config('assistant.question_max_chars') }}" autocomplete="off"
                      placeholder="Ask MotionGrove anything about your business..." data-role="ask-input" data-placeholder-open="Ask a follow-up..." required></textarea>
            <button type="button" class="btn btn-link btn-sm p-0 home-ask-resume" data-role="ask-resume" hidden>Continue your conversation</button>
            <button type="submit" class="btn btn-primary home-ask-submit" data-role="ask-submit" aria-label="Ask"><span class="home-ask-submit-label">Ask</span><span class="home-ask-submit-icon" aria-hidden="true"></span></button>
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

        <div class="home-ask-foot text-caption text-muted" data-role="ask-foot">
            <details data-role="ask-uses-ai">
                <summary>How Ask MotionGrove uses AI</summary>
                <p class="mb-0 mt-50">{{ $home['ask']['disclosure'] }}</p>
            </details>
        </div>
    </x-card>
</section>
