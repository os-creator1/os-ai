@extends('layouts/contentLayoutMaster')

@section('title', $a->exists ? 'Edit announcement' : 'New announcement')

@section('content')
    <section id="admin-platform-announcement-form">
        @include('admin.partials.flash')

        <form method="POST" action="{{ $a->exists ? route('admin.platform-announcements.update', $a->uid) : route('admin.platform-announcements.store') }}">
            @csrf
            @if ($a->exists) @method('PUT') @endif

            <div class="card mb-2">
                <div class="card-body">
                    <div class="mb-1">
                        <label class="form-label" for="title">Title</label>
                        <input class="form-control" id="title" name="title" maxlength="160" value="{{ old('title', $a->title) }}" required>
                    </div>
                    <div class="mb-1">
                        <label class="form-label" for="body">Message</label>
                        <textarea class="form-control" id="body" name="body" rows="5" maxlength="5000" required>{{ old('body', $a->body) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="card mb-2">
                <div class="card-header"><h4 class="card-title">Audience &amp; channels</h4></div>
                <div class="card-body">
                    @php($audience = old('audience', $a->audience ?? 'all'))
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="audience" id="aud-all" value="all" @checked($audience === 'all')>
                        <label class="form-check-label" for="aud-all">Everyone</label>
                    </div>
                    <div class="form-check mb-1">
                        <input class="form-check-input" type="radio" name="audience" id="aud-tiers" value="tiers" @checked($audience === 'tiers')>
                        <label class="form-check-label" for="aud-tiers">Only customers on these plans</label>
                    </div>
                    <div class="ms-2 mb-2">
                        @foreach ($tiers as $t)
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="audience_tiers[]" id="tier-{{ $t->value }}" value="{{ $t->value }}"
                                       @checked(in_array($t->value, old('audience_tiers', $a->audience_tiers ?? []), true))>
                                <label class="form-check-label" for="tier-{{ $t->value }}">{{ ucfirst($t->value) }}</label>
                            </div>
                        @endforeach
                    </div>
                    @php($channels = old('channels', $a->channels ?? ['in_app']))
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="channels[]" id="ch-in_app" value="in_app" @checked(in_array('in_app', $channels, true))>
                        <label class="form-check-label" for="ch-in_app">In the app</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="channels[]" id="ch-email" value="email" @checked(in_array('email', $channels, true))>
                        <label class="form-check-label" for="ch-email">Email</label>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h4 class="card-title">Timing</h4></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 mb-1">
                            <label class="form-label" for="scheduled_at">Send at (optional)</label>
                            <input class="form-control" type="datetime-local" id="scheduled_at" name="scheduled_at" value="{{ old('scheduled_at', $a->scheduled_at?->setTimezone(config('app.timezone'))->format('Y-m-d\TH:i')) }}">
                        </div>
                        <div class="col-md-4 mb-1">
                            <label class="form-label" for="expires_at">Stop showing after (optional)</label>
                            <input class="form-control" type="datetime-local" id="expires_at" name="expires_at" value="{{ old('expires_at', $a->expires_at?->setTimezone(config('app.timezone'))->format('Y-m-d\TH:i')) }}">
                        </div>
                    </div>
                    <p class="text-muted">Times are in {{ config('app.timezone') }}. The send time is only used when you choose <strong>Save &amp; schedule</strong>; <strong>Save draft</strong> keeps the message without sending it.</p>
                    <button class="btn btn-outline-primary" type="submit" name="submit" value="draft">Save draft</button>
                    <button class="btn btn-primary" type="submit" name="submit" value="schedule">Save &amp; schedule</button>
                    <a class="btn btn-flat-secondary" href="{{ route('admin.platform-announcements.index') }}">Back</a>
                </div>
            </div>
        </form>
    </section>
@endsection
