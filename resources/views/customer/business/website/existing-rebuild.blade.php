{{--
    Existing website -> MotionGrove-hosted website. An owner-reviewed plan: what the new site keeps, which old addresses
    redirect where (301), and which gathered facts the owner confirms. Nothing here is live: the external website stays
    exactly as it is, and nothing is published, switched or deleted from this screen.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Rebuild from your existing website')

@php
    $base = [$workspaceUid, $businessUid];
    $r = fn (string $name) => route('customer.workspaces.businesses.website.existing.'.$name, $base);
    $stateLabel = [
        'kept' => 'Same address kept',
        'redirect' => 'Redirect (301) proposed',
        'needs_decision' => 'You decide',
        'conflict' => 'Conflict: address is another page',
        'skipped' => 'Left out',
        'unsupported' => 'Cannot be redirected automatically',
    ];
@endphp

@section('content')
    <x-flash-alert />

    <div class="row mb-2"><div class="col-12">
        <h4 class="mb-50">Rebuild from your existing website</h4>
        <p class="mb-0 text-muted" data-testid="rebuild-safety-note">
            Your current website at <strong>{{ $business->website_url ?: 'your address' }}</strong> stays live and untouched.
            This plan only prepares a separate MotionGrove draft. Nothing is published, no domain is switched and nothing on your current site is deleted.
            Review everything below first: no review, rating, photo or claim from your current site is copied automatically.
        </p>
    </div></div>

    @if($plan === null)
        <div class="card"><div class="card-body">
            @if($report === null)
                <p class="mb-0">Check your existing website first (Website &rarr; Audit), so this plan can start from its real pages.</p>
            @else
                <p>We will build a plan from the newest check of your site ({{ $report->pages_audited ?? 0 }} pages). No new crawl and no AI is used.</p>
                <form method="post" action="{{ $r('prepare') }}">@csrf <button class="btn btn-primary" type="submit">Prepare my rebuild plan</button></form>
            @endif
        </div></div>
    @else
        <form method="post" action="{{ $r('confirm') }}">
            @csrf
            <div class="card"><div class="card-header"><h5 class="mb-0">1. Facts found on your site: confirm only what is true</h5></div><div class="card-body">
                @forelse($plan->facts as $fact)
                    <div class="form-check mb-50">
                        <input class="form-check-input" type="checkbox" name="facts[{{ $fact['key'] }}]" value="1" id="fact-{{ $loop->index }}" @checked($fact['confirmed'] ?? false)>
                        <label class="form-check-label" for="fact-{{ $loop->index }}">{{ $fact['label'] }} <span class="text-muted">(seen on /{{ $fact['source_path'] }})</span></label>
                    </div>
                @empty
                    <p class="mb-0 text-muted">No event types or service areas were detected from your page addresses.</p>
                @endforelse
                <p class="text-caption text-muted mt-1 mb-0">Unconfirmed facts are never used. Service areas get their own page only when your business profile supports enough real, distinct content for it.</p>
            </div></div>

            <div class="card"><div class="card-header"><h5 class="mb-0">2. Your pages and redirect plan</h5></div><div class="card-body table-responsive">
                <table class="table table-sm" data-testid="redirect-plan">
                    <thead><tr><th>Include</th><th>Old address</th><th>What it is</th><th>Plan</th><th>Redirect to</th><th>Keep my title &amp; description</th></tr></thead>
                    <tbody>
                    @foreach($rows as $row)
                        @php $decision = $plan->decisions['pages'][$row['key']] ?? []; @endphp
                        <tr>
                            <td><input type="checkbox" name="pages[{{ $row['key'] }}][include]" value="1" @checked($decision['include'] ?? true) aria-label="Include {{ $row['path'] ?: 'home page' }}"></td>
                            <td><code>/{{ $row['path'] }}</code>@if($row['case_changed']) <span class="badge bg-light-warning">lowercased</span>@endif</td>
                            <td>{{ ucfirst($row['role']) }}@if($row['area']) &middot; {{ $row['area'] }}@endif</td>
                            <td data-state="{{ $row['state'] }}">{{ $stateLabel[$row['state']] ?? $row['state'] }}</td>
                            <td>
                                @if($row['state'] === 'kept' || $row['path'] === '')
                                    <span class="text-muted">&mdash;</span>
                                @else
                                    <select class="form-select form-select-sm" name="pages[{{ $row['key'] }}][target]">
                                        <option value="auto" @selected(($decision['target'] ?? 'auto') === 'auto')>{{ $row['suggested'] === null ? 'No match yet' : 'Suggested: /'.$row['suggested'] }}</option>
                                        <option value="home" @selected(($decision['target'] ?? '') === 'home')>Home page</option>
                                        @foreach($draftSlugs as $slug)
                                            <option value="{{ $slug }}" @selected(($decision['target'] ?? '') === $slug)>/{{ $slug }}</option>
                                        @endforeach
                                        <option value="none" @selected(($decision['target'] ?? '') === 'none')>No redirect</option>
                                    </select>
                                @endif
                            </td>
                            <td>
                                @if($row['claim_flags'] !== [])
                                    <span class="text-muted" title="Contains claims ({{ implode(', ', $row['claim_flags']) }}) that need your confirmation">Not copied: has claims</span>
                                @else
                                    <input type="checkbox" name="pages[{{ $row['key'] }}][keep_seo]" value="1" @checked($decision['keep_seo'] ?? false)>
                                @endif
                                @if($row['canonical_differs'])<div class="text-caption text-muted">Old canonical pointed elsewhere</div>@endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @unless($hasDraft)<p class="text-caption text-muted mb-0">Redirect targets are matched to your new site's real pages once the draft is generated. Legal and blog pages are never redirected automatically.</p>@endunless
            </div></div>

            <button class="btn btn-primary" type="submit" data-testid="confirm-plan">{{ $plan->isConfirmed() ? 'Save my decisions' : 'Confirm plan' }}</button>
        </form>

        @if($plan->isConfirmed())
            <div class="d-flex gap-1 mt-2">
                <form method="post" action="{{ $r('start') }}">@csrf <button class="btn btn-outline-primary" type="submit">Start building my MotionGrove website</button></form>
                @if($hasDraft)
                    <form method="post" action="{{ $r('apply') }}">@csrf <button class="btn btn-outline-primary" type="submit">Apply redirects to my draft</button></form>
                @endif
            </div>
        @endif
    @endif
@endsection
