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
    // Shared customer request query-budget optimization
    // (Automations V2 §18) — one read of this customer's
    // unread notifications serves both the unread indicator
    // and the dropdown's latest-10 list below, instead of
    // querying the identical `user_id`/`mark_read` scope
    // twice.
    $unreadNotifications = \App\Models\Notifications::where('user_id', Auth::user()->id)->where('mark_read', 0)->latest()->get();
    // Implementation Contract 17 §12.G — a SEPARATE substrate
    // (platform_database_notifications), already filtered to
    // exactly what this actor is authorized to see right now.
    $documentActivity = app(\App\Library\Documents\DocumentActivityCenterReader::class)->unreadFor(Auth::user());
    $count = $unreadNotifications->count() + $documentActivity->count();
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

            @foreach($unreadNotifications->take(10) as $value)

                <a class="d-flex" href="{{ route('user.account', ['tab' => 'notification']) }}">
                    <div class="list-item d-flex align-items-start">
                        @switch($value->notification_type)

                            @case('user')
                                <div class="me-1">
                                    <div class="avatar bg-light-primary">
                                        <div class="avatar-content"><x-ds-icon name="user" class="avatar-icon" />
                                        </div>
                                    </div>
                                </div>

                                <div class="list-item-body flex-grow-1">
                                    <p class="media-heading"><span
                                                class="fw-bolder">{{__('locale.labels.you_have_new_user')}}
                                    </p>
                                    <small class="notification-text"> {{ str_limit($value->message, 30) }}</small>
                                </div>
                                @break

                            @case('plan')
                                <div class="me-1">
                                    <div class="avatar bg-light-success">
                                        <div class="avatar-content"><x-ds-icon name="shopping-cart" class="avatar-icon" />
                                        </div>
                                    </div>
                                </div>

                                <div class="list-item-body flex-grow-1">
                                    <p class="media-heading"><span
                                                class="fw-bolder">{{__('locale.labels.you_have_new_subscription')}}
                                    </p>
                                    <small class="notification-text"> {{ str_limit($value->message, 30) }}</small>
                                </div>

                                @break

                            @case('senderid')
                                <div class="me-1">
                                    <div class="avatar bg-light-danger">
                                        <div class="avatar-content"><x-ds-icon name="user-check" class="avatar-icon" />
                                        </div>
                                    </div>
                                </div>

                                <div class="list-item-body flex-grow-1">
                                    <p class="media-heading"><span
                                                class="fw-bolder">{{__('locale.labels.new_sender_id_notification')}}
                                    </p>
                                    <small class="notification-text"> {{ str_limit($value->message, 30) }}</small>
                                </div>
                                @break

                            @case('number')
                                <div class="me-1">
                                    <div class="avatar bg-light-info">
                                        <div class="avatar-content"><x-ds-icon name="phone" class="avatar-icon" />
                                        </div>
                                    </div>
                                </div>

                                <div class="list-item-body flex-grow-1">
                                    <p class="media-heading"><span class="fw-bolder">New Number sales</span>
                                    </p>
                                    <small class="notification-text"> {{ str_limit($value->message, 30) }}</small>
                                </div>
                                @break

                            @case('keyword')
                                <div class="me-1">
                                    <div class="avatar bg-light-warning">
                                        <div class="avatar-content"><x-ds-icon name="clipboard" class="avatar-icon" />
                                        </div>
                                    </div>
                                </div>

                                <div class="list-item-body flex-grow-1">
                                    <p class="media-heading"><span class="fw-bolder">New Keyword sales</span>
                                    </p>
                                    <small class="notification-text"> {{ str_limit($value->message, 30) }}</small>
                                </div>
                                @break

                            @case('chatbox')
                                <div class="me-1">
                                    <div class="avatar bg-light-danger">
                                        <div class="avatar-content"><x-ds-icon name="message-square" class="avatar-icon" />
                                        </div>
                                    </div>
                                </div>

                                <div class="list-item-body flex-grow-1">
                                    <p class="media-heading"><span class="fw-bolder">New Inbox Message</span>
                                    </p>
                                    <small class="notification-text"> {{ str_limit($value->message, 30) }}</small>
                                </div>
                                @break


                            @case('subscription')
                                <div class="me-1">
                                    <div class="avatar bg-light-danger">
                                        <div class="avatar-content"><x-ds-icon name="shopping-cart" class="avatar-icon" />
                                        </div>
                                    </div>
                                </div>

                                <div class="list-item-body flex-grow-1">
                                    <p class="media-heading"><span class="fw-bolder">Subscription Expired!</span>
                                    </p>
                                    <small class="notification-text"> {{ str_limit($value->message, 60) }}</small>
                                </div>
                                @break

                            @case('smsunit')
                                <div class="me-1">
                                    <div class="avatar bg-light-danger">
                                        <div class="avatar-content"><x-ds-icon name="message-square" class="avatar-icon" />
                                        </div>
                                    </div>
                                </div>

                                <div class="list-item-body flex-grow-1">
                                    <p class="media-heading"><span class="fw-bolder">SMS Unit Running Low!</span>
                                    </p>
                                    <small class="notification-text"> {{ str_limit($value->message, 60) }}</small>
                                </div>
                                @break

                            @case('topup')
                                <div class="me-1">
                                    <div class="avatar bg-light-danger">
                                        <div class="avatar-content"><x-ds-icon name="shopping-cart" class="avatar-icon" />
                                        </div>
                                    </div>
                                </div>

                                <div class="list-item-body flex-grow-1">
                                    <p class="media-heading"><span class="fw-bolder">Offline Topup Payment!</span>
                                    </p>
                                    <small class="notification-text">{{ str_limit($value->message, 60) }}</small>
                                </div>
                                @break
                        @endswitch
                        <small>
                            <time class="media-meta">{{ \App\Library\Tool::formatHumanTime($value->created_at) }}</time>
                        </small>

                    </div>
                </a>

            @endforeach

            {{-- Implementation Contract 17 §12.G, Blueprint §24 — Payments &
                 Contracts document/payment activity. A SEPARATE producer
                 (platform_database_notifications, via
                 DocumentActivityCenterReader) from the legacy list above: each
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
        <li class="dropdown-menu-footer">
            <a class="btn btn-primary w-100"
               href="{{ route('user.account', ['tab' => 'notification']) }}">{{__('locale.labels.read_all_notifications')}}</a>
        </li>
    </ul>
</li>
