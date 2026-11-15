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
        {{-- An email reads like an email (approved design): a card with who/when, the subject once, the body. Older messages of a
             thread are collapsed to one line and expand on click; the newest is open. --}}
        @php
            // Who it is from, in the words the design uses: the Contact's name (or the sender), and the Business for what it sent.
            $emailFrom = $item->isInbound() ? (($contactName ?? null) ?: ($item->via ?: 'Unknown sender')) : (($businessName ?? null) ?: 'You');
            $emailWords = preg_split('/\s+/u', trim((string) $emailFrom), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $emailInitial = mb_strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice($emailWords, 0, 2)))) ?: '?';
            $emailTo = $item->isInbound() ? null : (($contactName ?? null) ? preg_split('/\s+/u', trim((string) $contactName))[0] : null);
            $emailOpen = $loop->last;
            $emailSnippet = \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/', ' ', (string) $item->body)), 120);
            $emailWarn = $item->tone->value === 'warning';
        @endphp
        @if (($compactEmail ?? false) === true)
            {{-- Inside a text conversation: a compact email card with its state, leading to the full email in the Email tab. --}}
            <div class="mgx-em-compact mgx-em-compact-{{ $item->isInbound() ? 'in' : 'out' }}" data-role="timeline-message" data-direction="{{ $item->direction?->value }}" data-channel="email">
                <div class="mgx-emc-head">
                    <span class="mgx-emc-ic" aria-hidden="true"><x-ds-icon name="mail" size="17" /></span>
                    <span class="mgx-emc-t"><b data-role="timeline-email-subject">{{ $item->title !== '' ? $item->title : '(no subject)' }}</b><small>{{ $item->via !== null && str_starts_with((string) $item->via, 'Imported') ? $item->via : 'Email · ' . ($item->isInbound() ? 'Received' : 'Sent') }}</small></span>
                    @if ($item->detail !== null)<span class="mgx-em-chip {{ $emailWarn ? 'is-warn' : '' }}" data-role="timeline-detail">{{ $item->detail }}</span>@endif
                </div>
                @if ($emailSnippet !== '')<div class="mgx-emc-body">{{ $emailSnippet }}</div>@endif
                @if ($item->threadKey !== null && isset($emailThreadUrl))
                    <a class="mgx-emc-link" href="{{ $emailThreadUrl($item->threadKey) }}" data-role="timeline-view-email">View full email <x-ds-icon name="chevron-right" size="14" aria-hidden="true" /></a>
                @endif
                <time class="mgx-emc-time" datetime="{{ $local->toIso8601String() }}">{{ $local->format(config('app.time_format')) }}</time>
            </div>
        @else
        <details class="mgx-em mgx-em-{{ $item->isInbound() ? 'in' : 'out' }} {{ $emailWarn ? 'is-warn' : '' }}" data-role="timeline-message" data-direction="{{ $item->direction?->value }}" data-channel="email" @if ($emailOpen) open @endif>
            <summary class="mgx-em-head">
                <span class="mgx-em-av" aria-hidden="true">{{ $emailInitial }}</span>
                <span class="mgx-em-from">
                    <b class="email-message-sender">{{ $emailFrom }}@if ($emailTo !== null) <span class="mgx-em-to">to {{ $emailTo }}</span>@endif</b>
                    <span class="mgx-em-sub">{{ $item->isInbound() ? ($item->via ?: 'to you') : $item->via }}@if ($item->title !== '') · <span data-role="timeline-email-subject">{{ $item->title }}</span>@endif</span>
                    <span class="mgx-em-snippet">{{ $emailSnippet }}</span>
                </span>
                <span class="mgx-em-meta">
                    @if ($item->detail !== null)<span class="mgx-em-chip {{ $emailWarn ? 'is-warn' : '' }}" data-role="timeline-detail">{{ $item->detail }}</span>@endif
                    <time datetime="{{ $local->toIso8601String() }}">{{ $local->format('M j, ' . config('app.time_format')) }}</time>
                </span>
            </summary>
            @if ($item->body !== null)
                <div class="mgx-em-body timeline-message-body">{{ $item->body }}</div>
            @endif
        </details>
        @endif
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
