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

    Layout: header -> four plain-fact tiles (derived only from the sections
    already read; nothing new is queried) -> the request ledger (all
    Locations, newest first) beside one review-link card per Location. The
    link card comes first in the markup so it also comes first on a phone.
    "Record a request" and "Change link" open in-page (the :target hash and
    native <details>), so the page works without JavaScript. The only script
    is in _reviews-copy (copy-link and the All / Awaiting / Done pills);
    styling lives in _reviews-style. Both are kept out of this file because
    this file is scanned by SeoReviewsBoundaryTest.

    "Reviewed (self-reported)" is the person's own word: nothing here checks
    that a review exists.
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
    // and is not a gap (Growth judges the same set). They are listed without an action.
    $activeSections = collect($sections)->filter(fn ($section) => $section->writable);
    $writableSections = $canManage ? $activeSections->values() : collect();
    $locationCount = $activeSections->count();
    $withLink = $activeSections->filter(fn ($section) => $section->effectiveLink !== null)->count();
    $missingLink = $locationCount - $withLink;
    $totalRequests = collect($sections)->sum(fn ($section) => $section->requestCount);
    $awaitingOutcome = collect($sections)->sum(fn ($section) => $section->awaitingCount);
    $doneCount = $totalRequests - $awaitingOutcome;
    $anyTruncated = collect($sections)->contains(fn ($section) => $section->requestsAreTruncated());
    $lastRequestAt = collect($sections)->flatMap(fn ($section) => collect($section->requests)->pluck('requested_at'))->filter()->max();
    $manyLocations = count($sections) > 1;

    // One newest-first ledger across Locations, each row remembering where it came from.
    $allRows = collect($sections)
        ->flatMap(fn ($section) => collect($section->requests)->map(fn ($row) => $row + ['location_name' => $section->location->name]))
        ->sortByDesc(fn ($row) => $row['requested_at']?->getTimestamp() ?? 0)
        ->values();
    $visibleRequests = 8;

    $channelIcon = ['sms' => 'message-square', 'email' => 'mail', 'in_person' => 'users'];
    $statusVariant = ['requested' => 'warning', 'reviewed' => 'success', 'declined' => 'neutral'];

    if (! $anyTruncated) {
        $reviewedCount = $allRows->where('status', 'reviewed')->count();
        $declinedCount = $allRows->where('status', 'declined')->count();
        $requestsSub = $totalRequests === 0 ? 'Nothing recorded yet' : $reviewedCount . ' reviewed · ' . $awaitingOutcome . ' awaiting · ' . $declinedCount . ' declined';
    } else {
        $requestsSub = $awaitingOutcome . ' awaiting · ' . $doneCount . ' done';
    }
@endphp

@section('content')
    <div class="rv-header mb-2">
        <div>
            <h1 class="h3 mb-25">Reviews</h1>
            <p class="text-caption mb-0">Keep each Location's review link handy and note who you have asked.</p>
        </div>
        @if($writableSections->isNotEmpty())
            <div class="rv-header-actions">
                <x-button variant="primary" icon="plus" href="#record-request" data-role="record-request-open">Record a request</x-button>
            </div>
        @endif
    </div>

    <x-flash-alert class="mb-2" />

    @if(count($sections) > 0)
        <div class="rv-stats mb-2" data-section="review-summary">
            <div class="rv-stat" data-role="summary-requests">
                <p class="rv-stat-label"><x-ds-icon name="clipboard-list" size="16" /> Requests recorded</p>
                <p class="rv-stat-value">{{ $totalRequests }}</p>
                <p class="rv-stat-sub">{{ $requestsSub }}</p>
            </div>
            <div class="rv-stat" data-role="summary-awaiting">
                <p class="rv-stat-label"><x-ds-icon name="hourglass" size="16" /> Awaiting an outcome</p>
                <p class="rv-stat-value">{{ $awaitingOutcome }}</p>
                <p class="rv-stat-sub">{{ $awaitingOutcome === 0 ? 'All outcomes noted' : 'No outcome noted yet' }}</p>
            </div>
            <div class="rv-stat" data-role="summary-last-request">
                <p class="rv-stat-label"><x-ds-icon name="clock" size="16" /> Last request</p>
                @if($lastRequestAt !== null)
                    <p class="rv-stat-value rv-stat-value-text">{{ $lastRequestAt->diffForHumans() }}</p>
                    <p class="rv-stat-sub">{{ $lastRequestAt->format('Y-m-d') }}</p>
                @else
                    <p class="rv-stat-value rv-stat-value-text">None yet</p>
                    <p class="rv-stat-sub">Record one after you ask</p>
                @endif
            </div>
            <div class="rv-stat" data-role="summary-with-link">
                <p class="rv-stat-label"><x-ds-icon name="link" size="16" /> Locations with a review link</p>
                <p class="rv-stat-value">{{ $withLink }}<small> / {{ $locationCount }}</small></p>
                <p class="rv-stat-sub" data-role="summary-missing-link">{{ $missingLink === 0 ? 'Every Location is ready' : $missingLink . ($missingLink === 1 ? ' still needs a link' : ' still need a link') }}</p>
            </div>
        </div>
    @endif

    @if($writableSections->isNotEmpty())
        <x-card class="rv-record mb-2" id="record-request" data-section="record-request">
            <div class="rv-record-head">
                <h2 class="text-section-heading mb-0">Record a request</h2>
                <a class="text-caption" href="#" data-role="record-request-close">Close</a>
            </div>
            <p class="text-caption">This only notes that you asked. Nothing is sent from here.</p>
            <div class="rv-record-forms">
                @foreach($writableSections as $section)
                    <form method="POST" action="{{ route('customer.workspaces.businesses.seo.reviews.requests.store', [$workspaceUid, $businessUid, $section->location->uid]) }}" data-role="request-form" class="rv-record-form">
                        @csrf
                        @if($manyLocations)
                            <p class="rv-section-label mb-50">{{ $section->location->name }}</p>
                        @endif
                        <div class="rv-record-fields">
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
                            <x-button type="submit" variant="primary" size="sm" icon="check">Record request</x-button>
                        </div>
                    </form>
                @endforeach
            </div>
        </x-card>
    @endif

    @if(count($sections) === 0)
        <x-card :padded="true" data-section="review-empty">
            <x-empty-state icon="map-pin" title="No Locations to manage yet" description="There are no locations you can manage reviews for yet." data-role="review-empty" />
        </x-card>
    @else
        <div class="row">
            <div class="col-lg-4 order-lg-2 mb-2" data-section="review-links">
                @foreach($sections as $section)
                    @php
                        $location = $section->location;
                        $canWrite = $canManage && $section->writable;
                    @endphp
                    <x-card class="rv-location mb-2" id="location-{{ $location->uid }}" data-section="review-location" data-location="{{ $location->uid }}">
                        <div class="rv-location-head">
                            <div class="rv-location-title">
                                <span class="rv-location-icon"><x-ds-icon name="map-pin" size="16" /></span>
                                <div class="min-w-0">
                                    <p class="rv-section-label mb-0">{{ $location->name }}</p>
                                    <p class="rv-stat-sub mb-0" data-role="request-count">Requests recorded: {{ $section->requestCount }}</p>
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

                        <div data-role="review-link">
                            @if($section->effectiveLink !== null)
                                <p class="rv-stat-sub" data-role="review-link-source">{{ $section->linkSource === 'manual' ? 'Added by you' : 'From your linked Google Business Profile' }}</p>
                                <div class="rv-link-row">
                                    <input type="text" class="form-control rv-link-field" value="{{ $section->effectiveLink }}" readonly aria-label="Review link for {{ $location->name }}" data-role="review-link-field">
                                    <div class="rv-link-actions">
                                        <x-button variant="primary" size="sm" icon="copy" data-copy-link="{{ $section->effectiveLink }}" data-role="copy-link"><span data-copy-label>Copy link</span></x-button>
                                        <a class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1 transition-fast" href="{{ $section->effectiveLink }}" target="_blank" rel="{{ $rel }}" data-role="review-link-url" data-source="{{ $section->linkSource }}"><x-ds-icon name="external-link" size="16" /> Open link</a>
                                    </div>
                                </div>
                            @else
                                <div class="rv-empty" data-role="review-link-empty">
                                    <span class="rv-empty-icon"><x-ds-icon name="link-2-off" size="20" /></span>
                                    <div>
                                        <p class="text-label mb-25" data-role="review-link-none">No review link yet.</p>
                                        <p class="text-caption mb-0">
                                            @if($canWrite)
                                                Paste the https link customers use to leave a review.
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
                                    <summary class="btn btn-flat-secondary btn-sm d-inline-flex align-items-center gap-1 transition-fast">
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
                                                <span class="text-caption">Shown exactly as you paste it.</span>
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
                    </x-card>
                @endforeach

                <div class="rv-note" data-role="reviews-note">
                    <x-ds-icon name="info" size="16" />
                    <p class="mb-0">This page does not send anything. Use Conversations or an Automation to contact customers.</p>
                </div>
            </div>

            <div class="col-lg-8 order-lg-1 mb-2">
                <x-card :padded="false" class="rv-ledger" data-section="review-requests">
                    <div class="rv-ledger-head">
                        <h2 class="text-section-heading mb-0">Requests</h2>
                        <div class="rv-pills" role="group" aria-label="Show requests" data-role="request-pills">
                            <button type="button" class="rv-pill is-active" data-rv-filter="all">All <span>{{ $totalRequests }}</span></button>
                            <button type="button" class="rv-pill" data-rv-filter="awaiting">Awaiting <span>{{ $awaitingOutcome }}</span></button>
                            <button type="button" class="rv-pill" data-rv-filter="done">Done <span>{{ $doneCount }}</span></button>
                        </div>
                    </div>

                    @if($allRows->isEmpty())
                        <div class="rv-empty rv-empty-quiet" data-role="requests-empty">
                            <span class="rv-empty-icon"><x-ds-icon name="clipboard-list" size="20" /></span>
                            <div>
                                <p class="text-label mb-25">No requests recorded yet</p>
                                <p class="text-caption mb-0">Note each customer you ask, so the same person is not asked twice.</p>
                            </div>
                        </div>
                    @else
                        <div class="rv-rows" data-role="request-list">
                            @foreach($allRows as $row)
                                @if($loop->index === $visibleRequests)
                                    <details class="rv-more">
                                        <summary class="text-label">Show {{ $allRows->count() - $visibleRequests }} earlier {{ $allRows->count() - $visibleRequests === 1 ? 'request' : 'requests' }}</summary>
                                @endif
                                <div class="rv-request" data-role="review-request" data-status="{{ $row['status'] }}" data-state="{{ $row['status'] === 'requested' ? 'awaiting' : 'done' }}" data-request="{{ $row['uid'] }}">
                                    <span class="rv-request-icon"><x-ds-icon name="{{ $channelIcon[$row['channel']] ?? 'ellipsis' }}" size="16" /></span>
                                    <div class="rv-request-main">
                                        <div class="rv-request-top">
                                            <span class="rv-request-who">
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
                                            @if($manyLocations) · {{ $row['location_name'] }} @endif
                                            @if($row['resolved_at'] !== null) · outcome noted {{ $row['resolved_at']->format('Y-m-d') }} @endif
                                        </span>

                                        <div class="rv-request-foot">
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
                                            <div class="rv-request-links text-caption" data-role="request-links">
                                                @if($row['contact'] !== null)
                                                    @can('view_contact')
                                                        <a href="{{ route('customer.workspaces.businesses.people.show', [$workspaceUid, $businessUid, $row['contact']['uid']]) }}" data-role="deep-link-contact">Open contact</a>
                                                    @endcan
                                                @endif
                                                @can('chat_box')
                                                    <a href="{{ route('customer.workspaces.businesses.conversations.index', [$workspaceUid, $businessUid]) }}" data-role="deep-link-conversations">Open conversation</a>
                                                @endcan
                                                @can('automations')
                                                    <a href="{{ route('customer.workspaces.businesses.automations.index', [$workspaceUid, $businessUid]) }}" data-role="deep-link-automations">Open automation</a>
                                                @endcan
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                @if($loop->last && $allRows->count() > $visibleRequests)
                                    </details>
                                @endif
                            @endforeach
                        </div>
                        <p class="rv-filter-empty text-caption" data-role="request-filter-empty" hidden>Nothing here yet.</p>
                        @foreach($sections as $section)
                            @if($section->requestsAreTruncated())
                                <p class="text-caption rv-trunc" data-role="requests-truncated">Showing the latest {{ count($section->requests) }} of {{ $section->requestCount }} requests recorded.@if($manyLocations) · {{ $section->location->name }}@endif</p>
                            @endif
                        @endforeach
                    @endif
                </x-card>
            </div>
        </div>
    @endif
@endsection
