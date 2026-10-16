@extends('layouts/contentLayoutMaster')

@section('title', 'Forms')

@section('page-style')
    @include('customer.business.website._manage-styles')
    <style>
        .mg-form-card { display: grid; grid-template-columns: minmax(0, 1fr) 17rem; }
        .mg-form-main { padding: 1.25rem; }
        .mg-form-side { padding: 1.25rem; border-left: 1px solid var(--color-border, #E5E1DA); background: var(--color-surface-secondary, #FBFAF7); border-radius: 0 .875rem .875rem 0; }
        .mg-form-title { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
        .mg-stat { font-size: 2rem; font-weight: 700; line-height: 1.1; }
        .mg-kicker { font-size: .6875rem; letter-spacing: .04em; text-transform: uppercase; color: var(--color-text-muted, #6F6D67); }
        .mg-chips { display: flex; flex-wrap: wrap; gap: .4rem; margin: .4rem 0 1rem; }
        .mg-form-note { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; padding: .85rem 1.25rem; border-top: 1px solid var(--color-border, #E5E1DA); border-radius: 0 0 .875rem .875rem; background: var(--color-warning-soft-bg, #FCF4E6); }
        .mg-form-note.is-ok { background: var(--color-success-soft-bg, #EAF5EE); }
        .mg-pick { position: relative; }
        .mg-pick-menu { position: absolute; right: 0; top: calc(100% + .25rem); z-index: 6; min-width: 15rem; max-height: 16rem; overflow: auto; padding: .35rem; background: var(--color-surface, #fff); border: 1px solid var(--color-border, #E5E1DA); border-radius: .6rem; box-shadow: 0 8px 24px rgba(0,0,0,.12); }
        .mg-pick-menu a { display: block; padding: .45rem .6rem; border-radius: .4rem; color: inherit; text-decoration: none; font-size: .875rem; }
        .mg-pick-menu a:hover, .mg-pick-menu a:focus-visible { background: var(--color-surface-secondary, #F4F1EC); }

        @media (max-width: 767.98px) {
            .mg-form-card { grid-template-columns: minmax(0, 1fr); }
            .mg-form-side { border-left: 0; border-top: 1px solid var(--color-border, #E5E1DA); border-radius: 0; }
        }
    </style>
@endsection

@section('content')
    @include('customer.business.website._manage-header', ['active' => 'forms'])

    @foreach ($forms as $form)
        @php
            $usedOn = $pages->filter(fn ($p) => collect($p->sections ?? [])->contains(fn ($s) => ($s['type'] ?? null) === 'form' && ($s['data']['form_uid'] ?? null) === $form->uid));
            $newPageUrl = route('customer.workspaces.businesses.website.pages.create', [$workspaceUid, $businessUid, 'add_form' => $form->uid]);
        @endphp
        <div class="mg-card" data-testid="form-card">
            <div class="mg-form-card">
                <div class="mg-form-main">
                    <div class="d-flex gap-3">
                        <span class="mg-icon-tile"><x-ds-icon name="file-text" size="20" /></span>
                        <div style="min-width:0">
                            <div class="mg-form-title">
                                <h2 class="mg-card-title mb-0">{{ $form->name }}</h2>
                                @if ($form->is_active)
                                    <x-badge variant="success">Active</x-badge>
                                @else
                                    <x-badge variant="neutral">Not accepting submissions</x-badge>
                                @endif
                            </div>
                            <p class="mg-muted mb-0">
                                @if ($form->location)
                                    Inquiries are filed under <strong>{{ $form->location->name }}</strong>
                                @else
                                    <x-badge variant="warning">No location</x-badge> Choose a location before this form can accept inquiries.
                                @endif
                            </p>
                        </div>
                    </div>
                    <div class="mg-kicker mt-3">Asks for</div>
                    <div class="mg-chips">
                        @foreach (collect($form->fields)->pluck('label') as $label)
                            <span class="mg-chip">{{ $label }}</span>
                        @endforeach
                    </div>
                    <x-button variant="secondary" href="{{ route('customer.workspaces.businesses.website.forms.submissions', [$workspaceUid, $businessUid, $form->uid]) }}">View inquiries</x-button>
                    <x-button variant="secondary" icon="pencil" href="{{ route('customer.workspaces.businesses.website.forms.edit', [$workspaceUid, $businessUid, $form->uid]) }}">Edit form</x-button>
                </div>
                <div class="mg-form-side">
                    <div class="mg-kicker">Inquiries received</div>
                    <div class="mg-stat">{{ $form->submissions_count }}</div>
                    <p class="mg-muted mb-0">
                        @if ($usedOn->isEmpty())
                            The form isn't on your website yet, so visitors can't send one.
                        @else
                            {{ $form->submissions_count === 1 ? 'inquiry' : 'inquiries' }} so far.
                        @endif
                    </p>
                </div>
            </div>
            @if ($usedOn->isEmpty())
                <div class="mg-form-note" data-testid="form-not-on-site">
                    <div>
                        <strong>Not on your website yet</strong>
                        <div class="mg-muted">Add a Form section to a page and this form appears there. Nothing changes until you save the page.</div>
                    </div>
                    <details class="mg-pick">
                        <summary class="btn btn-primary d-inline-flex align-items-center gap-1" style="list-style:none">Add to a page <x-ds-icon name="chevron-down" size="14" /></summary>
                        <div class="mg-pick-menu">
                            @foreach ($pages as $p)
                                <a href="{{ route('customer.workspaces.businesses.website.pages.edit', [$workspaceUid, $businessUid, $p->uid, 'add_form' => $form->uid]) }}">{{ $p->title }}</a>
                            @endforeach
                            <a href="{{ $newPageUrl }}"><strong>+ New page</strong></a>
                        </div>
                    </details>
                </div>
            @else
                <div class="mg-form-note is-ok" data-testid="form-on-site">
                    <div>
                        <strong>On your website</strong>
                        <div class="mg-muted">Shown on {{ $usedOn->pluck('title')->implode(', ') }}. Visitors see it once the site is published.</div>
                    </div>
                </div>
            @endif
        </div>
    @endforeach

    @if ($isPhotoBooth && $locations->isNotEmpty())
        <div class="mg-card mg-card-pad">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div class="d-flex gap-3 align-items-center">
                    <span class="mg-icon-tile"><x-ds-icon name="map-pin" size="20" /></span>
                    <div>
                        <h2 class="mg-card-title mb-0">{{ $forms->isEmpty() ? 'Add a quote request form' : 'Another location?' }}</h2>
                        <p class="mg-muted mb-0">Each location gets its own quote form, so its inquiries and leads stay with that location. It asks for a name, phone number, email, event date, event type and a message.</p>
                    </div>
                </div>
                <form method="POST" action="{{ route('customer.workspaces.businesses.website.forms.store', [$workspaceUid, $businessUid]) }}" class="d-flex flex-wrap gap-2 align-items-start">
                    @csrf
                    @if ($locations->count() > 1)
                        <div>
                            <label for="location_uid" class="visually-hidden">Location</label>
                            <select name="location_uid" id="location_uid" class="form-select" required>
                                <option value="">Choose a location</option>
                                @foreach ($locations as $location)
                                    <option value="{{ $location->uid }}">{{ $location->name }}</option>
                                @endforeach
                            </select>
                            @error('location_uid')<div class="text-danger mg-muted">{{ $message }}</div>@enderror
                        </div>
                    @endif
                    <x-button type="submit" variant="secondary" icon="plus">Create quote request form</x-button>
                </form>
            </div>
        </div>
    @elseif (! $isPhotoBooth && $forms->isEmpty())
        <x-empty-state icon="inbox" title="No forms available yet" description="This slice ships one preset — a Photo Booth quote request — for Photo Booth businesses. A form for your business type isn't available yet." />
    @endif
@endsection
