<p>Choose every outcome that matters to you. We use your goals to tailor the recommendations and the setup suggestions you see next, and nothing in MotionGrove is hidden because of them.</p>

<form method="POST" action="{{ route('customer.onboarding.goals.store') }}">
    @csrf

    @php($selected = old('primary_goals', $onboarding->primary_goals ?? []))
    @php($priority = old('priority_goal', $selected[0] ?? null))

    @foreach (\App\Enums\Business\BusinessGoal::cases() as $goal)
        <div class="d-flex flex-wrap align-items-center justify-content-between mb-1">
            <div class="form-check">
                <input
                    class="form-check-input"
                    type="checkbox"
                    name="primary_goals[]"
                    id="goal-{{ $goal->value }}"
                    value="{{ $goal->value }}"
                    @checked(in_array($goal->value, $selected, true))
                >
                <label class="form-check-label" for="goal-{{ $goal->value }}">
                    {{ ucwords(str_replace('_', ' ', $goal->value)) }}
                </label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="priority_goal" id="priority-{{ $goal->value }}" value="{{ $goal->value }}" @checked($priority === $goal->value)>
                <label class="form-check-label text-caption" for="priority-{{ $goal->value }}">Start here</label>
            </div>
        </div>
    @endforeach

    <p class="text-caption">"Start here" is optional: it only decides which of your goals we look at first.</p>

    <x-button type="submit" variant="primary" class="mt-1">Continue</x-button>
</form>
