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
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'SEO — Reviews')

@php
    use App\Library\Seo\SeoLinkSafety;

    $rel = SeoLinkSafety::EXTERNAL_REL;
@endphp

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center flex-wrap">
            <h4 class="mb-0">Reviews</h4>
            <span class="text-caption">{{ $business->name }}</span>
        </div>
    </div>

    <x-flash-alert class="mb-2" />

    <p class="text-caption mb-2" data-role="reviews-note">
        Keep the link where customers can leave a Google review, and note who you have asked.
        This page does not send anything. To contact someone, use Conversations or an Automation.
    </p>

    @forelse($sections as $section)
        @php $location = $section->location; @endphp
        <x-card :padded="true" class="mb-2" data-section="review-location" data-location="{{ $location->uid }}">
            <div class="d-flex justify-content-between align-items-center flex-wrap mb-1">
                <p class="text-section-heading mb-0">{{ $location->name }}</p>
                @unless($section->writable)
                    <span class="badge bg-light text-dark" data-role="location-archived">Archived — read only</span>
                @endunless
            </div>

            <div class="mb-1" data-role="review-link">
                @if($section->effectiveLink !== null)
                    <a href="{{ $section->effectiveLink }}" target="_blank" rel="{{ $rel }}" data-role="review-link-url" data-source="{{ $section->linkSource }}">Review link</a>
                    <span class="text-caption" data-role="review-link-source">{{ $section->linkSource === 'manual' ? 'Added by you' : 'From your linked Google Business Profile' }}</span>
                @else
                    <span class="text-caption" data-role="review-link-none">No review link yet.</span>
                @endif

                @if($canManage && $section->writable)
                    <details class="mt-50">
                        <summary class="text-caption">{{ $section->manualLink !== null ? 'Change link' : 'Add link' }}</summary>
                        <form method="POST" action="{{ route('customer.workspaces.businesses.seo.reviews.link.save', [$workspaceUid, $businessUid, $location->uid]) }}" data-role="link-form">
                            @csrf
                            @method('PUT')
                            <label class="d-block mb-50">Review link (https)
                                <input type="text" name="review_url" class="form-control" maxlength="2048" value="{{ $section->manualLink }}">
                            </label>
                            <button type="submit" class="btn btn-primary btn-sm">Save link</button>
                        </form>
                        @if($section->manualLink !== null)
                            <form method="POST" action="{{ route('customer.workspaces.businesses.seo.reviews.link.clear', [$workspaceUid, $businessUid, $location->uid]) }}" class="mt-50" data-role="link-clear-form">
                                @csrf
                                <button type="submit" class="btn btn-outline-secondary btn-sm">Remove link</button>
                            </form>
                        @endif
                    </details>
                @endif
            </div>

            <p class="text-caption mb-50" data-role="request-count">Requests recorded: {{ $section->requestCount }}</p>

            @foreach($section->requests as $row)
                <div class="py-1 border-top" data-role="review-request" data-status="{{ $row['status'] }}" data-request="{{ $row['uid'] }}">
                    <div class="d-flex justify-content-between align-items-center flex-wrap">
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
                        <span class="badge bg-light text-dark" data-role="request-status">{{ $row['status_label'] }}</span>
                    </div>
                    <span class="text-caption d-block">
                        {{ $row['channel_label'] }} · {{ $row['requested_at']?->format('Y-m-d') }}
                        @if($row['resolved_at'] !== null) · outcome noted {{ $row['resolved_at']->format('Y-m-d') }} @endif
                    </span>

                    <div class="text-caption" data-role="request-links">
                        @if($row['contact'] !== null)
                            @can('view_contact')
                                <a href="{{ route('customer.workspaces.businesses.people.show', [$workspaceUid, $businessUid, $row['contact']['uid']]) }}" data-role="deep-link-contact">Open contact</a>
                            @endcan
                        @endif
                        @can('chat_box')
                            <a class="ms-1" href="{{ route('customer.workspaces.businesses.conversations.index', [$workspaceUid, $businessUid]) }}" data-role="deep-link-conversations">Open Conversations</a>
                        @endcan
                        @can('automations')
                            <a class="ms-1" href="{{ route('customer.workspaces.businesses.automations.index', [$workspaceUid, $businessUid]) }}" data-role="deep-link-automations">Open Automations</a>
                        @endcan
                    </div>

                    @if($canManage && $row['can_resolve'])
                        <div class="mt-50 d-flex gap-1">
                            <form method="POST" action="{{ route('customer.workspaces.businesses.seo.reviews.requests.reviewed', [$workspaceUid, $businessUid, $row['uid']]) }}" data-role="mark-reviewed-form">
                                @csrf
                                <button type="submit" class="btn btn-outline-secondary btn-sm">They say they reviewed</button>
                            </form>
                            <form method="POST" action="{{ route('customer.workspaces.businesses.seo.reviews.requests.declined', [$workspaceUid, $businessUid, $row['uid']]) }}" data-role="mark-declined-form">
                                @csrf
                                <button type="submit" class="btn btn-outline-secondary btn-sm">Declined</button>
                            </form>
                        </div>
                    @endif
                </div>
            @endforeach

            @if($canManage && $section->writable)
                <details class="mt-1">
                    <summary class="text-caption">Record a request</summary>
                    <form method="POST" action="{{ route('customer.workspaces.businesses.seo.reviews.requests.store', [$workspaceUid, $businessUid, $location->uid]) }}" data-role="request-form">
                        @csrf
                        <label class="d-block mb-50">How were they asked?
                            <select name="channel" class="form-select">
                                @foreach($channels as $channel)
                                    <option value="{{ $channel->value }}">{{ $channel->label() }}</option>
                                @endforeach
                            </select>
                        </label>
                        @if(count($section->contacts) > 0)
                            <label class="d-block mb-50">Contact
                                <select name="contact_uid" class="form-select">
                                    <option value="">No contact</option>
                                    @foreach($section->contacts as $contact)
                                        <option value="{{ $contact['uid'] }}">{{ $contact['name'] ?? $contact['phone'] }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endif
                        <button type="submit" class="btn btn-primary btn-sm">Record request</button>
                    </form>
                </details>
            @endif
        </x-card>
    @empty
        <x-card :padded="true" data-section="review-empty">
            <p class="mb-0" data-role="review-empty">There are no locations you can manage reviews for yet.</p>
        </x-card>
    @endforelse
@endsection
