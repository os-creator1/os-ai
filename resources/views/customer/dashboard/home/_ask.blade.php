{{--
    Home V2 §F — Ask MotionGrove.

    A request box over a CLOSED registry of supported intents. It is not a
    chatbot: typing resolves (server-side, deterministically) to a drawer, a
    module page, or the Advisor's confirm-first form, or to an honest "I can't
    do that from here". It never executes anything and never calls an AI
    provider on its own. Suggestions this actor cannot use are absent, never
    disabled; typing one still gets an explicit "not available" answer.
--}}
<section class="mb-2" aria-labelledby="home-ask-heading" data-band="ask" data-role="home-ask">
    <x-card>
        <h2 class="h5 text-section-heading mb-1" id="home-ask-heading">Ask MotionGrove</h2>

        <form class="home-ask-form" data-role="ask-form" data-ask-url="{{ $home['ask']['url'] }}" action="{{ $home['ask']['url'] }}" method="POST">
            @csrf
            <label for="home-ask-input" class="visually-hidden">What would you like MotionGrove to do?</label>
            <input type="text" id="home-ask-input" name="q" class="form-control" maxlength="200" autocomplete="off"
                   placeholder="Ask MotionGrove to do something..." data-role="ask-input" required>
            <x-button type="submit" data-role="ask-submit">Ask</x-button>
        </form>

        <ul class="list-unstyled d-flex flex-wrap gap-50 mt-1 mb-0" data-role="ask-suggestions" aria-label="Suggested requests">
            @foreach($home['ask']['suggestions'] as $suggestion)
                @if($suggestion['available'])
                    <li>
                        <button type="button" class="home-chip" data-role="ask-suggestion" data-suggestion="{{ $suggestion['key'] }}"
                                data-q="{{ $suggestion['label'] }}">{{ $suggestion['label'] }}</button>
                    </li>
                @endif
            @endforeach
        </ul>

        <div class="mt-1" data-role="ask-result" role="status" aria-live="polite" hidden></div>
    </x-card>
</section>
