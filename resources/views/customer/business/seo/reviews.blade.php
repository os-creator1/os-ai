{{--
    Contract 18, Sub-slice 18F — Reviews (workflow tracking only).

    A manual review link per Location and a ledger of "we asked this person".
    This page SENDS NOTHING: to contact someone it links, read-only, to the
    existing Conversations and Automations screens. It shows no review
    content — nothing is fetched from Google — and it has no
    click-tracking, redirect or short link: the review link is shown
    exactly as pasted (https only) and opens the destination directly with
    rel="noopener noreferrer nofollow".

    Every value is rendered with escaped Blade output only. A Contact's name
    or number is rendered only when the reader supplied it, which it does
    only for a user who can already see Contacts.

    Layout: header -> plain-fact summary tiles (derived only from the
    sections already read; nothing new is queried) -> one card per Location.
    Link and request forms open in-page with native <details>, so the page
    works without JavaScript. The only script is the copy-link helper in
    _reviews-copy; styling lives in _reviews-style. Both are kept out of this
    file because this file is scanned by SeoReviewsBoundaryTest.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'SEO — Reviews')

@section('page-style')
    @include('customer.business.seo._reviews-style')
@endsection

@section('page-script')
    @include('customer.business.seo._reviews-copy')
@endsection

@php
    use App\Library\Seo\SeoLinkSafety;

    $rel = SeoLinkSafety::EXTERNAL_REL;

    // The link figures are over ACTIVE Locations only: an archived Location is read-only, has nothing to fix
    // and is not a gap (Growth judges the same set). They are listed below without an action.
    $activeSections = collect($sections)->filter(fn ($section) => $section->writable);
    $locationCount = $activeSections->count();
    $archivedCount = count($sections) - $locationCount;
    $withLink = $activeSections->filter(fn ($section) => $section->effectiveLink !== null)->count();
    $missingLink = $activeSections->filter(fn ($section) => $section->effectiveLink === null)->count();
    $totalRequests = collect($sections)->sum(fn ($section) => $section->requestCount);
    $awaitingOutcome = collect($sections)->sum(fn ($section) => $section->awaitingCount);
    $lastRequestAt = collect($sections)->flatMap(fn ($section) => collect($section->requests)->pluck('requested_at'))->filter()->max();
    $linkPercent = $locationCount > 0 ? (int) round($withLink / $locationCount * 100) : 0;
    $firstFixable = collect($sections)->first(fn ($section) => $section->effectiveLink === null && $section->writable);

    $channelIcon = ['sms' => 'message-square', 'email' => 'mail', 'in_person' => 'users'];
    $statusVariant = ['requested' => 'warning', 'reviewed' => 'success', 'declined' => 'neutral'];
    $visibleRequests = 5;
@endphp

@section('content')
    <div class="rv-header mb-2">
        <div>
            <h1 class="h3 mb-25">Reviews</h1>
            <p class="text-caption mb-0">Keep each Location's review link in one place and note who you have asked.</p>
        </div>
        <div class="rv-header-actions">
            <x-badge variant="neutral" data-role="business-name"><x-ds-icon name="building-2" size="14" /> {{ $business->name }}</x-badge>
            @if($canManage && $firstFixable !== null)
                <x-button variant="primary" size="sm" icon="link" :href="'#location-' . $firstFixable->location->uid" data-role="jump-to-missing">
                    Add {{ $missingLink === 1 ? 'the missing link' : 'missing links' }}
                </x-button>
            @endif
        </div>
    </div>

    <x-flash-alert class="mb-2" />

    <div class="rv-note mb-2" data-role="reviews-note">
        <x-ds-icon name="info" size="18" />
        <p class="mb-0">
            Keep the link where customers can leave a review, and note who you have asked.
            This page does not send anything. To contact someone, use Conversations or an Automation.
        </p>
    </div>

    @if(count($sections) > 0)
        <div class="row rv-summary" data-section="review-summary">
            <div class="col-6 col-lg-3 mb-2">
                <x-card class="h-100" data-role="summary-with-link">
                    <p class="rv-stat-label"><x-ds-icon name="circle-check" size="16" /> With a review link</p>
                    <p class="rv-stat-value">{{ $withLink }}</p>
                    <p class="text-caption mb-0">{{ $locationCount }} {{ $locationCount === 1 ? 'active Location' : 'active Locations' }} in total @if($archivedCount > 0) · {{ $archivedCount }} archived, not counted @endif</p>
                    <div class="rv-meter" role="img" aria-label="{{ $linkPercent }} percent of Locations have a review link"><span style="width: {{ $linkPercent }}%"></span></div>
                </x-card>
            </div>
            <div class="col-6 col-lg-3 mb-2">
                <x-card class="h-100" data-role="summary-missing-link">
                    <p class="rv-stat-label"><x-ds-icon name="link-2-off" size="16" /> Missing a link</p>
                    <p class="rv-stat-value">{{ $missingLink }}</p>
                    <p class="text-caption mb-0">{{ $missingLink === 0 ? 'Every Location is ready.' : ($missingLink === 1 ? 'Location still needs a link.' : 'Locations still need a link.') }}</p>
                </x-card>
            </div>
            <div class="col-6 col-lg-3 mb-2">
                <x-card class="h-100" data-role="summary-requests">
                    <p class="rv-stat-label"><x-ds-icon name="clipboard-list" size="16" /> Requests recorded</p>
                    <p class="rv-stat-value">{{ $totalRequests }}</p>
                    <p class="text-caption mb-0">{{ $awaitingOutcome }} awaiting an outcome</p>
                </x-card>
            </div>
            <div class="col-6 col-lg-3 mb-2">
                <x-card class="h-100" data-role="summary-last-request">
                    <p class="rv-stat-label"><x-ds-icon name="clock" size="16" /> Last request</p>
                    @if($lastRequestAt !== null)
                        <p class="rv-stat-value rv-stat-value-text">{{ $lastRequestAt->diffForHumans() }}</p>
                        <p class="text-caption mb-0">{{ $lastRequestAt->format('Y-m-d') }}</p>
                    @else
                        <p class="rv-stat-value rv-stat-value-text">None yet</p>
                        <p class="text-caption mb-0">Record one after you ask a customer.</p>
                    @endif
                </x-card>
            </div>
        </div>
    @endif

    @forelse($sections as $section)
        @php
            $location = $section->location;
            $requestRows = collect($section->requests);
            $canWrite = $canManage && $section->writable;
        @endphp
        <x-card :padded="false" class="rv-location mb-2" id="location-{{ $location->uid }}" data-section="review-location" data-location="{{ $location->uid }}">
            <div class="rv-location-head">
                <div class="rv-location-title">
                    <span class="rv-location-icon"><x-ds-icon name="map-pin" size="18" /></span>
                    <div>
                        <h2 class="text-section-heading mb-0">{{ $location->name }}</h2>
                        <p class="text-caption mb-0" data-role="request-count">Requests recorded: {{ $section->requestCount }}</p>
                    </div>
                </div>
                <div class="rv-location-badges">
                    @unless($section->writable)
                        <x-badge variant="neutral" data-role="location-archived">Archived — read only</x-badge>
                    @endunless
                    @if($section->effectiveLink !== null)
                        <x-badge variant="success" data-role="review-link-status"><x-ds-icon name="circle-check" size="14" /> Review link ready</x-badge>
                    @else
                        <x-badge variant="warning" data-role="review-link-status"><x-ds-icon name="link-2-off" size="14" /> Needs a review link</x-badge>
                    @endif
                </div>
            </div>

            <div class="rv-section" data-role="review-link">
                @if($section->effectiveLink !== null)
                    <p class="rv-section-label">
                        Review link
                        <span class="text-caption" data-role="review-link-source">{{ $section->linkSource === 'manual' ? 'Added by you' : 'From your linked Google Business Profile' }}</span>
                    </p>
                    <div class="rv-link-row">
                        <input type="text" class="form-control rv-link-field" value="{{ $section->effectiveLink }}" readonly aria-label="Review link for {{ $location->name }}" data-role="review-link-field">
                        <div class="rv-link-actions">
                            <x-button variant="secondary" size="sm" icon="copy" data-copy-link="{{ $section->effectiveLink }}" data-role="copy-link"><span data-copy-label>Copy link</span></x-button>
                            <a class="btn btn-primary btn-sm d-inline-flex align-items-center gap-1 transition-fast" href="{{ $section->effectiveLink }}" target="_blank" rel="{{ $rel }}" data-role="review-link-url" data-source="{{ $section->linkSource }}"><x-ds-icon name="external-link" size="16" /> Open link</a>
                        </div>
                    </div>
                @else
                    <div class="rv-empty" data-role="review-link-empty">
                        <span class="rv-empty-icon"><x-ds-icon name="link-2-off" size="22" /></span>
                        <div class="rv-empty-body">
                            <p class="text-label mb-25" data-role="review-link-none">No review link yet.</p>
                            <p class="text-caption mb-0">
                                @if($canWrite)
                                    Paste the https link customers use to leave a review. Once it is saved you can copy it, open it and record who you have asked.
                                @elseif(! $section->writable)
                                    This Location is archived, so its link can no longer be changed.
                                @else
                                    Someone who manages SEO can add the link for this Location.
                                @endif
                            </p>
                        </div>
                    </div>
                @endif

                @if($canWrite)
                    <details class="rv-panel" @if($section->effectiveLink === null) open @endif>
                        <summary class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1 transition-fast">
                            <x-ds-icon name="{{ $section->manualLink !== null ? 'pencil' : 'plus' }}" size="16" />
                            {{ $section->manualLink !== null ? 'Change link' : 'Add link' }}
                        </summary>
                        <div class="rv-panel-body">
                            <form method="POST" action="{{ route('customer.workspaces.businesses.seo.reviews.link.save', [$workspaceUid, $businessUid, $location->uid]) }}" data-role="link-form">
                                @csrf
                                @method('PUT')
                                <label class="rv-field">
                                    <span class="text-label">Review link (https)</span>
                                    <input type="text" name="review_url" class="form-control" maxlength="2048" placeholder="https://g.page/r/…/review" value="{{ $section->manualLink }}">
                                    <span class="text-caption">Shown exactly as you paste it. Customers go straight to this address.</span>
                                </label>
                                <x-button type="submit" variant="primary" size="sm" icon="check">Save link</x-button>
                            </form>
                            @if($section->manualLink !== null)
                                <form method="POST" action="{{ route('customer.workspaces.businesses.seo.reviews.link.clear', [$workspaceUid, $businessUid, $location->uid]) }}" class="mt-1" data-role="link-clear-form">
                                    @csrf
                                    <x-button type="submit" variant="secondary" size="sm" icon="trash-2">Remove link</x-button>
                                </form>
                            @endif
                        </div>
                    </details>
                @endif
            </div>

            <div class="rv-section">
                <div class="rv-section-bar">
                    <p class="rv-section-label mb-0">Requests</p>
                    @if($canWrite)
                        <details class="rv-panel rv-panel-inline">
                            <summary class="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-1 transition-fast">
                                <x-ds-icon name="plus" size="16" /> Record a request
                            </summary>
                            <div class="rv-panel-body">
                                <form method="POST" action="{{ route('customer.workspaces.businesses.seo.reviews.requests.store', [$workspaceUid, $businessUid, $location->uid]) }}" data-role="request-form">
                                    @csrf
                                    <label class="rv-field">
                                        <span class="text-label">How were they asked?</span>
                                        <select name="channel" class="form-select">
                                            @foreach($channels as $channel)
                                                <option value="{{ $channel->value }}">{{ $channel->label() }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    @if(count($section->contacts) > 0)
                                        <label class="rv-field">
                                            <span class="text-label">Contact</span>
                                            <select name="contact_uid" class="form-select">
                                                <option value="">No contact</option>
                                                @foreach($section->contacts as $contact)
                                                    <option value="{{ $contact['uid'] }}">{{ $contact['name'] ?? $contact['phone'] }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                    @endif
                                    <p class="text-caption">This only notes that you asked. Nothing is sent from here.</p>
                                    <x-button type="submit" variant="primary" size="sm" icon="check">Record request</x-button>
                                </form>
                            </div>
                        </details>
                    @endif
                </div>

                @if($requestRows->isEmpty())
                    <div class="rv-empty rv-empty-quiet" data-role="requests-empty">
                        <span class="rv-empty-icon"><x-ds-icon name="clipboard-list" size="22" /></span>
                        <div class="rv-empty-body">
                            <p class="text-label mb-25">No requests recorded yet</p>
                            <p class="text-caption mb-0">When you ask a customer for a review, note it here so the same person is not asked twice.</p>
                        </div>
                    </div>
                @else
                    @if($section->requestsAreTruncated())
                        <p class="text-caption mb-50" data-role="requests-truncated">Showing the latest {{ $requestRows->count() }} of {{ $section->requestCount }} requests recorded.</p>
                    @endif
                    @foreach($requestRows as $row)
                        @if($loop->index === $visibleRequests)
                            <details class="rv-more">
                                <summary class="text-label">Show {{ $requestRows->count() - $visibleRequests }} earlier {{ $requestRows->count() - $visibleRequests === 1 ? 'request' : 'requests' }}</summary>
                        @endif
                        <div class="rv-request" data-role="review-request" data-status="{{ $row['status'] }}" data-request="{{ $row['uid'] }}">
                            <span class="rv-request-icon"><x-ds-icon name="{{ $channelIcon[$row['channel']] ?? 'ellipsis' }}" size="16" /></span>
                            <div class="rv-request-main">
                                <div class="rv-request-top">
                                    <span>
                                        @if($row['contact'] !== null)
                                            <strong data-role="request-contact">{{ $row['contact']['name'] ?? $row['contact']['phone'] }}</strong>
                                            @if($row['contact']['name'] !== null)
                                                <span class="text-caption" data-role="request-contact-phone">{{ $row['contact']['phone'] }}</span>
                                            @endif
                                        @elseif($row['has_contact'])
                                            <strong data-role="request-contact-hidden">Contact on file</strong>
                                        @else
                                            <strong data-role="request-no-contact">No contact recorded</strong>
                                        @endif
                                    </span>
                                    <x-badge :variant="$statusVariant[$row['status']] ?? 'neutral'" data-role="request-status">{{ $row['status_label'] }}</x-badge>
                                </div>
                                <span class="text-caption d-block">
                                    {{ $row['channel_label'] }} · {{ $row['requested_at']?->format('Y-m-d') }}
                                    @if($row['resolved_at'] !== null) · outcome noted {{ $row['resolved_at']->format('Y-m-d') }} @endif
                                </span>

                                <div class="rv-request-links text-caption" data-role="request-links">
                                    @if($row['contact'] !== null)
                                        @can('view_contact')
                                            <a href="{{ route('customer.workspaces.businesses.people.show', [$workspaceUid, $businessUid, $row['contact']['uid']]) }}" data-role="deep-link-contact">Open contact</a>
                                        @endcan
                                    @endif
                                    @can('chat_box')
                                        <a href="{{ route('customer.workspaces.businesses.conversations.index', [$workspaceUid, $businessUid]) }}" data-role="deep-link-conversations">Open Conversations</a>
                                    @endcan
                                    @can('automations')
                                        <a href="{{ route('customer.workspaces.businesses.automations.index', [$workspaceUid, $businessUid]) }}" data-role="deep-link-automations">Open Automations</a>
                                    @endcan
                                </div>

                                @if($canManage && $row['can_resolve'])
                                    <div class="rv-request-actions">
                                        <form method="POST" action="{{ route('customer.workspaces.businesses.seo.reviews.requests.reviewed', [$workspaceUid, $businessUid, $row['uid']]) }}" data-role="mark-reviewed-form">
                                            @csrf
                                            <x-button type="submit" variant="secondary" size="sm" icon="check">They say they reviewed</x-button>
                                        </form>
                                        <form method="POST" action="{{ route('customer.workspaces.businesses.seo.reviews.requests.declined', [$workspaceUid, $businessUid, $row['uid']]) }}" data-role="mark-declined-form">
                                            @csrf
                                            <x-button type="submit" variant="ghost" size="sm" icon="x">Declined</x-button>
                                        </form>
                                    </div>
                                @endif
                            </div>
                        </div>
                        @if($loop->last && $requestRows->count() > $visibleRequests)
                            </details>
                        @endif
                    @endforeach
                @endif
            </div>
        </x-card>
    @empty
        <x-card :padded="true" data-section="review-empty">
            <x-empty-state icon="map-pin" title="No Locations to manage yet" description="There are no locations you can manage reviews for yet." data-role="review-empty" />
        </x-card>
    @endforelse
@endsection
