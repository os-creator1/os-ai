{{-- Where a form is offered: one card per Location the actor may reach (design:
     FormSettings.dc.html "Where it's offered"). Each toggle posts to the
     Location-scoped route, which runs the full gate chain again; the form submit
     flushes any pending autosave first (fb-nav-form). data-role contract kept. --}}
@if (count($locations) === 0)
    <p class="mb-0" data-role="forms-no-locations">You don't have access to any locations for this business.</p>
@else
    <div style="display:flex;flex-direction:column;gap:12px" data-role="forms-locations">
        @foreach ($locations as $location)
            @php
                $deployment = $deployments->get($location->id);
                $on = $deployment !== null && $deployment->is_enabled;
                $url = $on ? route('public.forms.show', [$deployment->uid]) : null;
                $embedCode = $url ? '<iframe src="'.$url.'" title="'.e($form->name ?? 'Form').'" width="100%" height="720" style="border:0" loading="lazy"></iframe>' : null;
            @endphp
            <div class="mgf-loc" data-location="{{ $location->uid }}">
                <div class="mgf-loc-head">
                    <b>{{ $location->name ?: 'Unnamed location' }}@if ($location->isArchived()) <span class="mgf-pill">Archived</span>@endif</b>
                    <span class="mgf-offered {{ $on ? '' : 'is-off' }}">{{ $on ? 'Offered' : 'Not offered' }}</span>
                    <form method="POST" action="{{ route('customer.workspaces.businesses.forms.locations.set', array_merge($formScope, [$location->uid])) }}" class="fb-nav-form">
                        @csrf
                        <input type="hidden" name="enabled" value="{{ $on ? 0 : 1 }}">
                        <button type="submit" class="mgf-btn is-sm is-outline" data-role="forms-location-toggle">{{ $on ? 'Stop offering here' : 'Offer here' }}</button>
                    </form>
                </div>
                @if ($on)
                    <div class="mgf-loc-body" data-role="forms-embed">
                        <div class="mgf-link-box">
                            <span><a href="{{ $url }}" data-role="forms-public-link" target="_blank" rel="noopener" style="color:inherit">{{ $url }}</a></span>
                            <button type="button" class="mgf-btn is-sm is-outline" data-copy="{{ $url }}">@include('customer.business.forms._icon', ['n' => 'copy', 'style' => 'width:14px;height:14px']) Copy link</button>
                        </div>
                        <button type="button" class="mgf-btn is-outline" data-copy="{{ $embedCode }}" data-role="forms-embed-copy">@include('customer.business.forms._icon', ['n' => 'code']) Copy embed code</button>
                        <input type="text" class="sr-only" readonly value="{{ $embedCode }}" data-role="forms-embed-code" aria-label="Embed code for {{ $location->name ?: 'this location' }}" tabindex="-1">
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endif
