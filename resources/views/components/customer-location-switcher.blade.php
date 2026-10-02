{{--
    Blueprint §7 — the Location switcher. Rendered only when the actor can
    reach MORE THAN ONE active Location of the current Business; hidden
    entirely otherwise. Every choice is a CSRF POST that the server
    re-authorizes (SwitchLocationAction); this file decides nothing.
--}}
@php
    $locationContext = app()->bound(\App\Library\Navigation\CustomerContext::class) ? app(\App\Library\Navigation\CustomerContext::class) : null;
    $locationUser = auth()->user();
    $locationBusiness = null;
    $locationOptions = collect();
    $locationSelected = null;

    if ($locationContext && $locationUser && $locationContext->isBusinessFrame() && ! $locationContext->isViewingAsClient() && $locationContext->selectedBusiness) {
        $locationBusiness = \App\Models\Business::query()->find($locationContext->selectedBusiness->id);
        if ($locationBusiness) {
            $locator = app(\App\Library\Navigation\CurrentLocation::class);
            $locationOptions = $locator->options($locationBusiness, (int) $locationUser->id);
            $locationSelected = $locator->selectedFor($locationBusiness, (int) $locationUser->id);
        }
    }
@endphp
@if($locationBusiness && $locationOptions->count() > 1)
    <form method="POST" action="{{ route('customer.context.location.switch') }}" class="d-flex align-items-center ms-50" data-role="location-switcher">
        @csrf
        <input type="hidden" name="workspace" value="{{ $locationContext->selectedWorkspace->uid }}">
        <input type="hidden" name="business" value="{{ $locationBusiness->uid }}">
        <label for="customer-location-switcher" class="visually-hidden">{{ __('Location') }}</label>
        <select id="customer-location-switcher" name="location" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">{{ __('All locations') }}</option>
            @foreach($locationOptions as $option)
                <option value="{{ $option->uid }}" @selected($locationSelected && $locationSelected->id === $option->id)>{{ $option->name ?: __('Unnamed location') }}</option>
            @endforeach
        </select>
        <noscript><button type="submit" class="btn btn-sm btn-outline-secondary ms-50">{{ __('Go') }}</button></noscript>
    </form>
@endif
