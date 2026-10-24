@extends('layouts/contentLayoutMaster')

@section('title', __('locale.menu.Conversations'))

@section('content')
    <div class="mb-1 d-flex gap-1" data-role="conversation-channels">
        <a class="btn btn-sm btn-outline-primary" href="{{ route('customer.workspaces.businesses.conversations.index', [$workspaceUid, $businessUid]) }}">SMS</a>
        <a class="btn btn-sm btn-primary" href="{{ route('customer.workspaces.businesses.conversations.email', [$workspaceUid, $businessUid]) }}">Email</a>
    </div>

    @if (session('message'))
        <x-alert variant="{{ session('status') === 'success' ? 'success' : 'warning' }}">{{ session('message') }}</x-alert>
    @endif

    <div class="row">
        <div class="col-md-4 mb-2" data-role="email-thread-list">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Email threads</h5>
                    @forelse ($threads as $thread)
                        <div class="mb-1">
                            <a href="{{ route('customer.workspaces.businesses.conversations.email', [$workspaceUid, $businessUid, 'thread' => $thread['key']]) }}">
                                <strong>{{ $thread['subject'] !== '' ? $thread['subject'] : '(no subject)' }}</strong>
                            </a>
                            <div class="text-muted small">
                                {{ $thread['counterpart'] }} · {{ $thread['count'] }} {{ \Illuminate\Support\Str::plural('message', $thread['count']) }}
                                · {{ ($thread['latest']->received_at ?? $thread['latest']->created_at)?->diffForHumans() }}
                            </div>
                        </div>
                    @empty
                        <p class="text-muted mb-0">No email yet. Connect a mailbox in Settings → Email.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="col-md-8" data-role="email-thread">
            @if ($selected === null)
                <p class="text-muted">Select a thread to read it.</p>
            @else
                <div class="card">
                    <div class="card-body">
                        @foreach ($selected['messages'] as $message)
                            <div class="mb-2" data-direction="{{ $message->direction }}">
                                <div>
                                    <strong>
                                        @if ($message->direction === 'inbound')
                                            {{ $message->from_name ? $message->from_name . ' <' . $message->from_email . '>' : $message->from_email }}
                                        @else
                                            You ({{ $message->from_email }})
                                        @endif
                                    </strong>
                                    <span class="text-muted small">· {{ ($message->received_at ?? $message->created_at)?->toDayDateTimeString() }}</span>
                                </div>
                                <div class="fw-bold">{{ $message->subject }}</div>
                                <div>{!! nl2br(e($message->body_text)) !!}</div>
                            </div>
                        @endforeach

                        @if ($canReply && $selected['reply_to'] !== null)
                            <form method="POST" action="{{ route('customer.workspaces.businesses.email.reply', [$workspaceUid, $businessUid, $selected['reply_to']->uid]) }}">
                                @csrf
                                <input type="hidden" name="send_token" value="{{ $sendToken }}">
                                <input type="hidden" name="return_to" value="conversations">
                                @if ($selected['location_choices']->isNotEmpty())
                                    <select class="form-select mb-1" name="location_uid" required>
                                        <option value="">Which location is this for?</option>
                                        @foreach ($selected['location_choices'] as $choice)
                                            <option value="{{ $choice->uid }}">{{ $choice->name }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                <input class="form-control mb-1" type="text" name="subject" maxlength="200" required
                                       value="{{ \Illuminate\Support\Str::startsWith(strtolower($selected['reply_to']->subject), 're:') ? $selected['reply_to']->subject : 'Re: ' . $selected['reply_to']->subject }}">
                                <textarea class="form-control mb-1" name="body" rows="4" maxlength="20000" required></textarea>
                                <button type="submit" class="btn btn-primary">Send reply</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
