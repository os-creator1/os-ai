@extends('layouts/contentLayoutMaster')

@section('title', $announcement->exists ? 'Edit announcement' : 'New announcement')

@php
    $audience = old('audience', $announcement->audience ?? ['kind' => 'everyone']);
    $channels = old('channels', $announcement->channels ?? ['banner']);
    $refs = is_array($audience['refs'] ?? null) ? implode("\n", $audience['refs']) : ($audience['refs'] ?? '');
    $action = $announcement->exists ? route('admin.platform-announcements.update', $announcement) : route('admin.platform-announcements.store');
    $local = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('Y-m-d\TH:i') : '';
@endphp

@section('content')
    <section id="admin-platform-announcement-form">
        <h3 class="mb-2">{{ $announcement->exists ? 'Edit announcement' : 'New announcement' }}</h3>

        @if ($errors->any())
            <div class="alert alert-danger" role="alert" data-role="pann-errors"><ul class="mb-0 ps-1">@foreach ($errors->all() as $m)<li>{{ $m }}</li>@endforeach</ul></div>
        @endif

        <form method="POST" action="{{ $action }}">
            @csrf
            @if ($announcement->exists) @method('PUT') @endif
            <div class="card"><div class="card-body">
                <div class="mb-1"><label class="form-label" for="pann-title">Title</label>
                    <input id="pann-title" name="title" class="form-control" maxlength="160" required value="{{ old('title', $announcement->title) }}"></div>
                <div class="mb-1"><label class="form-label" for="pann-body">Message</label>
                    <textarea id="pann-body" name="body" class="form-control" rows="5" maxlength="5000" required>{{ old('body', $announcement->body) }}</textarea></div>
                <div class="row">
                    <div class="col-md-4 mb-1"><label class="form-label" for="pann-severity">Severity</label>
                        <select id="pann-severity" name="severity" class="form-select">
                            @foreach (\App\Models\PlatformAnnouncement::SEVERITIES as $s)<option value="{{ $s }}" @selected(old('severity', $announcement->severity) === $s)>{{ ucfirst($s) }}</option>@endforeach
                        </select></div>
                    <div class="col-md-8 mb-1"><label class="form-label d-block">Channels</label>
                        @foreach (['banner' => 'Banner at the top of every page', 'notification' => 'In-app notification', 'email' => 'Email'] as $c => $label)
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="channels[]" value="{{ $c }}" id="pann-ch-{{ $c }}" @checked(in_array($c, $channels, true))>
                                <label class="form-check-label" for="pann-ch-{{ $c }}">{{ $label }}</label>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div></div>

            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Who gets it</h5></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-1"><label class="form-label" for="pann-kind">Audience</label>
                            <select id="pann-kind" name="audience[kind]" class="form-select" data-role="pann-audience-kind">
                                @foreach ($kinds as $k => $label)
                                    @if ($k !== 'users')<option value="{{ $k }}" @selected(($audience['kind'] ?? '') === $k)>{{ $label }}</option>@endif
                                @endforeach
                            </select></div>
                        <div class="col-md-6 mb-1 pann-extra" data-for="tier"><label class="form-label" for="pann-tier">Plan tier</label>
                            <select id="pann-tier" name="audience[tier]" class="form-select">
                                @foreach ($tiers as $t)<option value="{{ $t }}" @selected(($audience['tier'] ?? '') === $t)>{{ ucfirst($t) }}</option>@endforeach
                            </select></div>
                        <div class="col-12 mb-1 pann-extra" data-for="workspaces businesses">
                            <label class="form-label" for="pann-refs">Workspace or Business ids / uids, one per line</label>
                            <textarea id="pann-refs" name="audience[refs]" class="form-control" rows="3">{{ $refs }}</textarea></div>
                    </div>
                    <p class="small text-muted mb-0">Only customer accounts that own a Workspace or Business are ever included; Platform Owners and staff never are. Large audiences are delivered in the background.</p>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">When</h5></div>
                <div class="card-body row">
                    <div class="col-md-6 mb-1"><label class="form-label" for="pann-publish">Publish at (to schedule)</label>
                        <input id="pann-publish" type="datetime-local" name="publish_at" class="form-control" value="{{ old('publish_at', $local($announcement->publish_at)) }}"></div>
                    <div class="col-md-6 mb-1"><label class="form-label" for="pann-expires">Expires at (optional)</label>
                        <input id="pann-expires" type="datetime-local" name="expires_at" class="form-control" value="{{ old('expires_at', $local($announcement->expires_at)) }}"></div>
                </div>
            </div>

            <div class="d-flex gap-1">
                <button type="submit" name="mode" value="draft" class="btn btn-outline-primary" data-role="pann-save-draft">Save draft</button>
                <button type="submit" name="mode" value="schedule" class="btn btn-primary" data-role="pann-schedule">Schedule</button>
                <button type="submit" name="mode" value="publish" class="btn btn-success" data-role="pann-publish-now"
                        onclick="return confirm('Publish now to the chosen audience?');">Publish now</button>
                <a href="{{ route('admin.platform-announcements.index') }}" class="btn btn-outline-secondary">Back</a>
            </div>
        </form>
    </section>
@endsection

@section('page-script')
    <script>
        (function () {
            var kind = document.getElementById('pann-kind');
            function sync() {
                document.querySelectorAll('.pann-extra').forEach(function (e) {
                    e.style.display = e.getAttribute('data-for').split(' ').indexOf(kind.value) >= 0 ? '' : 'none';
                });
            }
            kind.addEventListener('change', sync); sync();
        })();
    </script>
@endsection
