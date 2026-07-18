<p>Pick one or two outcomes you care about most right now.</p>

<form method="POST" action="{{ route('customer.onboarding.goals.store') }}">
    @csrf

    @php($selected = old('primary_goals', $onboarding->primary_goals ?? []))

    @foreach (\App\Enums\Business\BusinessGoal::cases() as $goal)
        <div class="form-check mb-1">
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
    @endforeach

    <button type="submit" class="btn btn-primary mt-2">Continue</button>
</form>
