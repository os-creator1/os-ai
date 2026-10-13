{{--
    The top bar's notification bell (Blueprint §24). One shared component for
    every portal and layout that renders panels.navbar.

    The trigger is a real <button> driving Bootstrap's own dropdown (outside
    click, Escape and focus return come from it). The small dot is shown only
    when this actor has something unread, from the same two sources and the
    same queries the bell has always read; the number itself is announced to
    assistive technology, never invented or capped here.
--}}
@php
    // Only current, re-authorized MotionGrove activity belongs in this bell.
    // Inherited Ultimate SMS notification rows are legacy and must not surface.
    $documentActivity = app(\App\Library\Documents\DocumentActivityCenterReader::class)->unreadFor(Auth::user());
    $count = $documentActivity->count();
@endphp
<li class="nav-item dropdown dropdown-notification mg-topbar-item" data-role="topbar-notifications">
    <button type="button" class="mg-topbar-btn mg-topbar-icon-btn" id="dropdown-notifications"
            data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
        <x-ds-icon name="bell" size="18" aria-hidden="true" />
        <span class="visually-hidden">{{ __('locale.labels.notifications') }}</span>
        @if($count)
            <span class="mg-topbar-dot" data-role="notification-unread-dot" aria-hidden="true"></span>
            <span class="visually-hidden" data-role="notification-unread-count" data-count="{{ $count }}">{{ $count }} {{ __('locale.labels.new') }}</span>
        @endif
    </button>
    <ul class="dropdown-menu dropdown-menu-media dropdown-menu-end" aria-labelledby="dropdown-notifications">
        <li class="dropdown-menu-header">
            <div class="dropdown-header d-flex">
                <h4 class="notification-title mb-0 me-auto">{{ __('locale.labels.notifications') }}</h4>
                <div class="badge rounded-pill badge-light-primary">{{$count}} {{__('locale.labels.new')}}</div>
            </div>
        </li>
        <li class="scrollable-container media-list">

            {{-- Implementation Contract 17 §12.G, Blueprint §24 — Payments &
                 Contracts document/payment activity. A SEPARATE producer
                 (platform_database_notifications, via
                 DocumentActivityCenterReader) from legacy Ultimate SMS notifications: each
                 row here has already been re-authorized right now, so nothing
                 further to check at render time. --}}
            @foreach($documentActivity->take(10) as $activityItem)
                <a class="d-flex" href="{{ $activityItem->url }}">
                    <div class="list-item d-flex align-items-start">
                        <div class="me-1">
                            <div class="avatar bg-light-success">
                                <div class="avatar-content"><x-ds-icon name="file-text" class="avatar-icon" />
                                </div>
                            </div>
                        </div>

                        <div class="list-item-body flex-grow-1">
                            <p class="media-heading"><span class="fw-bolder">Payments &amp; Contracts</span>
                            </p>
                            <small class="notification-text"> {{ str_limit($activityItem->message, 60) }}</small>
                        </div>
                        <small>
                            <time class="media-meta">{{ \App\Library\Tool::formatHumanTime($activityItem->createdAt) }}</time>
                        </small>
                    </div>
                </a>
            @endforeach
        </li>
        @if($documentActivity->isEmpty())
            <li class="px-2 py-2 text-muted">No new notifications.</li>
        @endif
    </ul>
</li>
