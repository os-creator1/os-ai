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

    Layout: header -> four compact fact cards (derived only from the sections
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

    $statusVariant = ['requested' => 'warning', 'reviewed' => 'success', 'declined' => 'neutral'];

    // The bar under "Requests recorded". Segment sizes are the ledger's own counts; when a Location's
    // list is capped only the whole-ledger awaiting / done split is exact, so the bar uses that.
    if (! $anyTruncated) {
        $reviewedCount = $allRows->where('status', 'reviewed')->count();
        $declinedCount = $allRows->where('status', 'declined')->count();
        $requestsSub = $totalRequests === 0 ? 'Nothing recorded yet' : $reviewedCount . ' reviewed · ' . $awaitingOutcome . ' awaiting · ' . $declinedCount . ' declined';
        $barParts = ['ok' => $reviewedCount, 'wait' => $awaitingOutcome, 'off' => $declinedCount];
    } else {
        $requestsSub = $awaitingOutcome . ' awaiting · ' . $doneCount . ' done';
        $barParts = ['ok' => $doneCount, 'wait' => $awaitingOutcome, 'off' => 0];
    }

    // "MB" for Marcus Bell; a Contact with only a number, or none, gets the person icon instead.
    $initials = function (?string $name): ?string {
        $words = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $words === [] ? null : mb_strtoupper(mb_substr($words[0], 0, 1) . (count($words) > 1 ? mb_substr(end($words), 0, 1) : ''));
    };
@endphp

@section('content')
    <div class="rv-header mb-2">
        <div>
            <h1 class="h3 mb-25">Reviews</h1>
            <p class="rv-sub mb-0">Keep each Location's review link in one place and note who you have asked.</p>
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
                <p class="rv-stat-label">Requests recorded</p>
                <p class="rv-stat-value">{{ $totalRequests }}</p>
                @if($totalRequests > 0)
                    <span class="rv-bar" aria-hidden="true">
                        @foreach($barParts as $part => $count)
                            @if($count > 0)<span class="rv-bar-{{ $part }}" style="flex-grow: {{ $count }}"></span>@endif
                        @endforeach
                    </span>
                @endif
                <p class="rv-stat-sub">{{ $requestsSub }}</p>
            </div>
            <div class="rv-stat {{ $awaitingOutcome > 0 ? 'rv-stat-warm' : '' }}" data-role="summary-awaiting">
                <p class="rv-stat-label">Awaiting an outcome</p>
                <p class="rv-stat-value {{ $awaitingOutcome > 0 ? 'rv-stat-value-warn' : '' }}">{{ $awaitingOutcome }}</p>
                <p class="rv-stat-sub">{{ $awaitingOutcome === 0 ? 'All outcomes noted' : 'Asked, no answer noted yet' }}</p>
            </div>
            <div class="rv-stat" data-role="summary-last-request">
                <p class="rv-stat-label">Last request</p>
                @if($lastRequestAt !== null)
                    <p class="rv-stat-value rv-stat-value-text">{{ $lastRequestAt->diffForHumans() }}</p>
                    <p class="rv-stat-sub">{{ $lastRequestAt->format('M j, Y') }}</p>
                @else
                    <p class="rv-stat-value rv-stat-value-text">None yet</p>
                    <p class="rv-stat-sub">Record one after you ask</p>
                @endif
            </div>
            <div class="rv-stat" data-role="summary-with-link">
                <p class="rv-stat-label">Locations with a review link</p>
                <p class="rv-stat-value {{ $missingLink === 0 && $locationCount > 0 ? 'rv-stat-value-ok' : '' }}">{{ $withLink }}<small> of {{ $locationCount }}</small></p>
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
                                    <p class="rv-stat-sub mb-0" data-role="review-link-source">
                                        @if($section->effectiveLink === null)
                                            Review link
                                        @else
                                            Review link · {{ $section->linkSource === 'manual' ? 'added by you' : 'from your linked Google Business Profile' }}
                                        @endif
                                    </p>
                                    @if($manyLocations)
                                        <p class="rv-stat-sub mb-0" data-role="request-count">Requests recorded: {{ $section->requestCount }}</p>
                                    @endif
                                </div>
                            </div>
                            <div class="rv-location-badges">
                                @unless($section->writable)
                                    <x-badge variant="neutral" data-role="location-archived">Archived — read only</x-badge>
                                @endunless
                                @if($section->effectiveLink !== null)
                                    <x-badge variant="success" data-role="review-link-status"><x-ds-icon name="check" size="12" /> Ready</x-badge>
                                @else
                                    <x-badge variant="warning" data-role="review-link-status"><x-ds-icon name="link-2-off" size="12" /> Needs a review link</x-badge>
                                @endif
                            </div>
                        </div>

                        <div data-role="review-link">
                            @if($section->effectiveLink !== null)
                                <div class="rv-link-row">
                                    <div class="rv-link-box">
                                        <input type="text" class="rv-link-field" value="{{ $section->effectiveLink }}" readonly aria-label="Review link for {{ $location->name }}" data-role="review-link-field">
                                        <button type="button" class="rv-link-copy" data-copy-link="{{ $section->effectiveLink }}" aria-label="Copy link"><x-ds-icon name="copy" size="14" /></button>
                                    </div>
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
                                    <summary class="rv-change">
                                        <x-ds-icon name="{{ $section->manualLink !== null ? 'pencil' : 'plus' }}" size="14" />
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
                    <p class="mb-0"><strong>This page does not send anything.</strong> Keep the link where customers can leave a review, and note who you have asked. To contact someone, use Conversations or an Automation.</p>
                </div>
            </div>

            <div class="col-lg-8 order-lg-1 mb-2">
                <x-card :padded="false" class="rv-ledger" data-section="review-requests">
                    <div class="rv-ledger-head">
                        <div>
                            <h2 class="text-section-heading mb-0">Requests</h2>
                            <p class="rv-stat-sub mb-0">{{ $manyLocations ? 'All Locations' : $sections[0]->location->name }}</p>
                        </div>
                        <div class="rv-pills" role="group" aria-label="Show requests" data-role="request-pills">
                            <button type="button" class="rv-pill is-active" data-rv-filter="all">All</button>
                            <button type="button" class="rv-pill" data-rv-filter="awaiting">Awaiting</button>
                            <button type="button" class="rv-pill" data-rv-filter="done">Done</button>
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
                                @php
                                    $isAwaiting = $row['status'] === 'requested';
                                    $badge = $row['contact'] !== null ? $initials($row['contact']['name']) : null;
                                @endphp
                                <div class="rv-request {{ $isAwaiting ? 'is-awaiting' : '' }}" data-role="review-request" data-status="{{ $row['status'] }}" data-state="{{ $isAwaiting ? 'awaiting' : 'done' }}" data-request="{{ $row['uid'] }}">
                                    <span class="rv-avatar" aria-hidden="true">
                                        @if($badge !== null){{ $badge }}@else<x-ds-icon name="user" size="16" />@endif
                                    </span>
                                    <div class="rv-request-main">
                                        <p class="rv-request-who mb-0">
                                            @if($row['contact'] !== null)
                                                <strong data-role="request-contact">{{ $row['contact']['name'] ?? $row['contact']['phone'] }}</strong>
                                            @elseif($row['has_contact'])
                                                <strong data-role="request-contact-hidden">Contact on file</strong>
                                            @else
                                                <strong data-role="request-no-contact">No contact recorded</strong>
                                            @endif
                                        </p>
                                        <p class="rv-request-meta mb-0">
                                            @if($row['contact'] !== null && $row['contact']['name'] !== null)
                                                <span data-role="request-contact-phone">{{ $row['contact']['phone'] }}</span> ·
                                            @endif
                                            {{ $row['channel_label'] }} · {{ $row['requested_at']?->format('M j, Y') }}
                                            @if($manyLocations) · {{ $row['location_name'] }} @endif
                                        </p>
                                        <div class="rv-request-links" data-role="request-links">
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
                                    </div>
                                    <div class="rv-request-state">
                                        <x-badge :variant="$statusVariant[$row['status']] ?? 'neutral'" data-role="request-status">
                                            @if($row['status'] === 'reviewed')<x-ds-icon name="check" size="12" /> @endif{{ $row['status_label'] }}
                                        </x-badge>
                                        @if($row['resolved_at'] !== null)
                                            <span class="rv-request-note">Outcome noted {{ $row['resolved_at']->format('M j, Y') }}</span>
                                        @endif
                                    </div>
                                    @if($canManage && $row['can_resolve'])
                                        <div class="rv-request-actions">
                                            <form method="POST" action="{{ route('customer.workspaces.businesses.seo.reviews.requests.reviewed', [$workspaceUid, $businessUid, $row['uid']]) }}" data-role="mark-reviewed-form">
                                                @csrf
                                                <x-button type="submit" variant="secondary" size="sm" icon="check">They say they reviewed</x-button>
                                            </form>
                                            <form method="POST" action="{{ route('customer.workspaces.businesses.seo.reviews.requests.declined', [$workspaceUid, $businessUid, $row['uid']]) }}" data-role="mark-declined-form">
                                                @csrf
                                                <x-button type="submit" variant="secondary" size="sm" icon="x">Declined</x-button>
                                            </form>
                                        </div>
                                    @endif
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
