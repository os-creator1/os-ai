{{-- Where a form is offered: one row per Location the actor may reach. The same
     markup the classic editor had (data-role contract preserved). Each toggle posts
     to the Location-scoped route, which runs the full gate chain again; the form
     submit flushes any pending autosave first (fb-nav-form). --}}
@if (count($locations) === 0)
    <p class="mb-0" data-role="forms-no-locations">You don't have access to any locations for this business.</p>
@else
    <ul class="list-group" data-role="forms-locations">
        @foreach ($locations as $location)
            @php
                $deployment = $deployments->get($location->id);
                $on = $deployment !== null && $deployment->is_enabled;
            @endphp
            <li class="list-group-item" data-location="{{ $location->uid }}">
                <div class="d-flex justify-content-between align-items-center">
                    <span>
                        {{ $location->name ?: 'Unnamed location' }}
                        @if ($location->isArchived())
                            <span class="badge badge-light-secondary">Archived</span>
                        @endif
                    </span>
                    <form method="POST" action="{{ route('customer.workspaces.businesses.forms.locations.set', array_merge($formScope, [$location->uid])) }}" class="fb-nav-form">
                        @csrf
                        <input type="hidden" name="enabled" value="{{ $on ? 0 : 1 }}">
                        <button type="submit" class="btn btn-sm {{ $on ? 'btn-outline-danger' : 'btn-outline-primary' }}" data-role="forms-location-toggle">
                            {{ $on ? 'Stop offering here' : 'Offer here' }}
                        </button>
                    </form>
                </div>
                @if ($on)
                    <div class="mt-1">
                        <small class="text-muted">Link:</small>
                        <a href="{{ route('public.forms.show', [$deployment->uid]) }}" data-role="forms-public-link">{{ route('public.forms.show', [$deployment->uid]) }}</a>
                    </div>
                    @php
                        $embedUrl = route('public.forms.show', [$deployment->uid]);
                        $embedCode = '<iframe src="' . $embedUrl . '" title="' . e($form->name ?? 'Form') . '" width="100%" height="720" style="border:0" loading="lazy"></iframe>';
                    @endphp
                    <div class="mt-1" data-role="forms-embed">
                        <small class="text-muted">Embed on any web page:</small>
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control" readonly value="{{ $embedCode }}" data-role="forms-embed-code" onclick="this.select()" aria-label="Embed code for {{ $location->name ?: 'this location' }}">
                            <div class="input-group-append">
                                <button type="button" class="btn btn-outline-secondary" data-role="forms-embed-copy"
                                    onclick="var i=this.closest('[data-role=forms-embed]').querySelector('input');i.select();navigator.clipboard&&navigator.clipboard.writeText(i.value);this.textContent='Copied';">Copy</button>
                            </div>
                        </div>
                    </div>
                @endif
            </li>
        @endforeach
    </ul>
@endif
