{{--
    Conversations — one person's activity timeline (ContactActivityTimeline),
    oldest first, grouped by the viewer's day.

    Human communication dominates: a message is a bubble, inbound on the left.
    Everything else — an automation outcome, an opt-out, the contact being
    added — is a compact card. Rendered on the server, so every text below is
    escaped by Blade and no message is assembled into markup in the browser.
--}}
@php
    $timezone = auth()->user()?->timezone ?: config('app.timezone');
    $now = \Carbon\CarbonImmutable::now($timezone);
    $previousDay = null;
@endphp

@if ($page->truncated)
    <div class="timeline-divider" data-role="timeline-truncated"><span>Earlier activity is not shown</span></div>
@endif

@if ($page->isEmpty())
    <div class="timeline-empty text-caption" data-role="timeline-empty">No messages or activity with this person yet.</div>
@endif

@foreach ($page->items as $item)
    @php
        $local = $item->at->setTimezone($timezone);
        $day = $local->toDateString();
    @endphp

    @if ($day !== $previousDay)
        <div class="timeline-divider" data-role="timeline-day">
            <span>{{ $day === $now->toDateString() ? 'Today' : ($day === $now->subDay()->toDateString() ? 'Yesterday' : $local->format('M j, Y')) }}</span>
        </div>
        @php
            $previousDay = $day;
        @endphp
    @endif

    @if ($item->isMessage() && $item->channel === 'email')
        {{-- An email reads like an email: a header (who, when), the subject, the body on the page — not a chat bubble. --}}
        @php
            $emailFrom = $item->isInbound() ? ($item->via ?: 'Unknown sender') : 'You';
            $emailInitial = mb_strtoupper(mb_substr(trim((string) $emailFrom) !== '' ? (string) $emailFrom : '?', 0, 1));
        @endphp
        <article class="email-message email-message-{{ $item->isInbound() ? 'inbound' : 'outbound' }} {{ $item->tone->value === 'warning' ? 'timeline-message-warning' : '' }}" data-role="timeline-message" data-direction="{{ $item->direction?->value }}" data-channel="email">
            <header class="email-message-head">
                <span class="avatar email-message-avatar" aria-hidden="true">{{ $emailInitial }}</span>
                <div class="email-message-from">
                    <strong class="email-message-sender">{{ $emailFrom }}</strong>
                    <span class="email-message-direction">{{ $item->isInbound() ? 'to you' : 'sent from ' . $item->via }}</span>
                </div>
                <time class="email-message-time" datetime="{{ $local->toIso8601String() }}">{{ $local->format('M j, ' . config('app.time_format')) }}</time>
            </header>
            @if ($item->title !== '')
                <h3 class="email-message-subject timeline-email-subject" data-role="timeline-email-subject">{{ $item->title }}</h3>
            @endif
            @if ($item->body !== null)
                <div class="email-message-body timeline-message-body">{{ $item->body }}</div>
            @endif
            @if ($item->detail !== null)
                <p class="email-message-status" data-role="timeline-detail">{{ $item->detail }}</p>
            @endif
        </article>
    @elseif ($item->isMessage())
        <div class="chat {{ $item->isInbound() ? 'chat-left' : '' }} timeline-message {{ $item->tone->value === 'warning' ? 'timeline-message-warning' : '' }}" data-role="timeline-message" data-direction="{{ $item->direction?->value }}" data-channel="{{ $item->channel }}">
            <div class="chat-body">
                <div class="chat-content">
                    @foreach ($item->media as $url)
                        @if (preg_match('#^(https?://|/)#i', $url) === 1)
                            @php
                                $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
                            @endphp
                            <p class="timeline-media">
                                @if (in_array($extension, ['mp4', 'avi', 'mkv', 'webm', 'mov'], true))
                                    <video controls src="{{ $url }}"></video>
                                @elseif (in_array($extension, ['mp3', 'wav', 'ogg'], true))
                                    <audio controls src="{{ $url }}"></audio>
                                @else
                                    <img src="{{ $url }}" alt="Attachment" loading="lazy">
                                @endif
                            </p>
                        @endif
                    @endforeach

                    @if ($item->channel === 'email' && $item->title !== '')
                        <p class="timeline-email-subject" data-role="timeline-email-subject">{{ $item->title }}</p>
                    @endif

                    @if ($item->body !== null)
                        <p class="timeline-message-body">{{ $item->body }}</p>
                    @endif
                </div>

                {{-- Time, channel and delivery state sit under the bubble, not inside it. --}}
                <div class="timeline-message-foot">
                    <p class="chat-time timeline-message-meta">
                        <span class="timeline-channel timeline-channel-{{ $item->channel }}" data-role="timeline-channel">{{ $item->channel === 'email' ? 'Email' : 'SMS' }}</span>
                        <span>{{ $local->format(config('app.time_format')) }}</span>
                        @if ($item->via !== null)
                            <span data-role="timeline-via">· {{ $item->via }}</span>
                        @endif
                        @if ($item->detail !== null)
                            <span data-role="timeline-detail">· {{ $item->detail }}</span>
                        @endif
                    </p>

                    @if ($item->retryable && $item->retrySendUid !== null)
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-secondary timeline-message-retry"
                            data-role="timeline-message-retry"
                            data-send-uid="{{ $item->retrySendUid }}"
                        >{{ __('locale.conversations.retry') }}</button>
                    @endif
                </div>
            </div>
        </div>
    @else
        <div class="timeline-activity timeline-activity-{{ $item->tone->value }}" data-role="timeline-activity">
            <x-ds-icon :name="$item->icon" size="14" class="timeline-activity-icon" aria-hidden="true" />
            <span class="timeline-activity-title">{{ $item->title }}</span>
            @if ($item->detail !== null)
                <span class="timeline-activity-detail">{{ $item->detail }}</span>
            @endif
            <span class="timeline-activity-time">{{ $local->format(config('app.time_format')) }}</span>
        </div>
    @endif
@endforeach
