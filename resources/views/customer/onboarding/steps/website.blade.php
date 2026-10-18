@php
    $business = $onboarding->business;
    $choice = old('website_choice', $websiteChoice);
    $options = [
        'build' => ['Build a new website with MotionGrove', 'We use your business, location and services to start. You create and review it in the Website section after setup. Nothing is published now.'],
        'existing' => ['I already have a website', 'Save its address. We do not connect, audit or edit it during setup.'],
        'later' => ['I’ll set up my website later', 'Finish setup now. Your home page will suggest it as a next step.'],
    ];
@endphp

<h5>How would you like to handle your website?</h5>

<form method="POST" action="{{ route('customer.onboarding.website.store') }}" data-role="website-form">
    @csrf

    @if ($managedWebsite)
        <x-alert variant="info">Your MotionGrove website is already set up. Nothing about it changes here.</x-alert>
        <input type="hidden" name="website_choice" value="build">
    @else
        <fieldset class="mb-2">
            <legend class="visually-hidden">Website</legend>
            @foreach ($options as $value => [$title, $hint])
                <div class="form-check mb-1">
                    <input class="form-check-input" type="radio" name="website_choice" id="website_choice_{{ $value }}" value="{{ $value }}" @checked($choice === $value) required>
                    <label class="form-check-label" for="website_choice_{{ $value }}"><strong>{{ $title }}</strong><br><span class="text-muted">{{ $hint }}</span></label>
                </div>
            @endforeach
        </fieldset>

        <div data-role="website-url">
            <x-input name="website_url" label="Your website address" type="text" value="{{ old('website_url', $business->website_url ?? '') }}" placeholder="example.com" maxlength="2048" />
        </div>
    @endif

    <x-button type="submit" variant="primary" class="mt-1">Continue</x-button>
</form>

<script>
    // Progressive enhancement: the address box matters only for "I already have a website".
    (function () {
        var form = document.querySelector('[data-role="website-form"]');
        var holder = form && form.querySelector('[data-role="website-url"]');
        if (!holder) { return; }
        function sync() {
            var checked = form.querySelector('input[name="website_choice"]:checked');
            holder.hidden = !checked || checked.value !== 'existing';
        }
        form.addEventListener('change', sync);
        sync();
    })();
</script>
