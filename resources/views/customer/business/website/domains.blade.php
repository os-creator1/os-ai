@extends('layouts/contentLayoutMaster')

@section('title', 'Custom domains')

@section('page-style')
    @include('customer.business.website._manage-styles')
    <style>
        .mg-live-strip { display: flex; align-items: center; gap: .75rem; padding: .85rem 1.25rem; }
        .mg-live-dot { width: .55rem; height: .55rem; border-radius: 50%; background: var(--color-border, #D8D3CA); flex: none; }
        .mg-live-dot.is-live { background: var(--color-success, #3C8D5A); }
        .mg-live-label { font-size: .6875rem; letter-spacing: .04em; text-transform: uppercase; color: var(--color-text-muted, #6F6D67); }
        .mg-empty { padding: 2.5rem 1.25rem 2rem; text-align: center; }
        .mg-empty form { display: flex; flex-wrap: wrap; justify-content: center; gap: .5rem; max-width: 34rem; margin: 1.25rem auto 0; }
        .mg-empty form input { flex: 1 1 14rem; min-width: 0; }
        .mg-steps4 { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; padding: 1rem 1.25rem; border-top: 1px solid var(--color-border, #E5E1DA); background: var(--color-surface-secondary, #FBFAF7); border-radius: 0 0 .875rem .875rem; }
        .mg-step4 { display: flex; gap: .6rem; align-items: flex-start; font-size: .8125rem; }
        .mg-step4 b { display: block; font-size: .8125rem; }
        .mg-num { display: inline-flex; align-items: center; justify-content: center; width: 1.5rem; height: 1.5rem; flex: none; border-radius: 50%; font-size: .75rem; background: var(--color-primary-soft-bg, #F4E6E4); color: var(--color-primary, #B5524C); }
        .mg-domain-head { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: .75rem; padding: 1.25rem; }
        .mg-domain-name { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; font-size: 1.05rem; font-weight: 600; overflow-wrap: anywhere; }
        .mg-progress { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .75rem; padding: 0 1.25rem 1.25rem; }
        .mg-progress > div { border-top: 3px solid var(--color-border, #E5E1DA); padding-top: .4rem; font-size: .8125rem; color: var(--color-text-muted, #6F6D67); }
        .mg-progress > div.is-done { border-top-color: var(--color-success, #3C8D5A); }
        .mg-progress > div.is-current { border-top-color: var(--color-primary, #B5524C); color: var(--color-text-primary, #262522); }
        .mg-progress > div.is-failed { border-top-color: var(--color-danger, #C0392B); }
        .mg-progress b { display: block; font-weight: 600; }
        .mg-dns { padding: 1.25rem; border-top: 1px solid var(--color-border, #E5E1DA); }
        .mg-dns-table { border: 1px solid var(--color-border, #E5E1DA); border-radius: .6rem; overflow: hidden; margin: .75rem 0 1rem; }
        .mg-dns-row { display: grid; grid-template-columns: 8rem minmax(0, 1fr) auto; align-items: center; gap: .75rem; padding: .7rem 1rem; font-size: .875rem; }
        .mg-dns-row + .mg-dns-row { border-top: 1px solid var(--color-border, #E5E1DA); }
        .mg-dns-key { font-size: .6875rem; letter-spacing: .04em; text-transform: uppercase; color: var(--color-text-muted, #6F6D67); }
        .mg-copy { border: 0; background: transparent; padding: .25rem; color: var(--color-text-muted, #6F6D67); border-radius: .4rem; }
        .mg-copy:hover, .mg-copy:focus-visible { color: var(--color-primary, #B5524C); }
        .mg-add-row { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .75rem; }
        .mg-add-row input { flex: 1 1 14rem; min-width: 0; }
        .mg-status-line { display: inline-flex; align-items: center; gap: .4rem; font-size: .8125rem; color: var(--color-text-muted, #6F6D67); margin-top: .15rem; }
        .mg-status-line i { width: .45rem; height: .45rem; border-radius: 50%; background: var(--color-warning, #B7791F); }
        .mg-status-line.is-live i { background: var(--color-success, #3C8D5A); }
        .mg-status-line.is-bad i { background: var(--color-danger, #C0392B); }
        .mg-rec { border: 1px solid var(--color-border, #E5E1DA); border-radius: .6rem; background: #fff; margin: .6rem 0 .5rem; overflow: hidden; }
        .mg-rec-grid { display: grid; grid-template-columns: 5rem minmax(0, 1.1fr) minmax(0, 2fr) 6.5rem; gap: .75rem; align-items: center; padding: .55rem 1rem; }
        .mg-rec-head { font-size: .6875rem; letter-spacing: .04em; text-transform: uppercase; color: var(--color-text-muted, #6F6D67); background: var(--color-surface-secondary, #FBFAF7); border-bottom: 1px solid var(--color-border, #E5E1DA); }
        .mg-rec-field { display: flex; align-items: center; justify-content: space-between; gap: .4rem; min-width: 0; padding: .35rem .6rem; border-radius: .5rem; background: var(--color-surface-secondary, #F4F2EC); font-size: .8125rem; }
        .mg-rec-field span { min-width: 0; overflow-wrap: anywhere; }
        .mg-rec-mobile { display: none; font-size: .6875rem; letter-spacing: .04em; text-transform: uppercase; color: var(--color-text-muted, #6F6D67); }
        .mg-note { display: flex; gap: .4rem; align-items: flex-start; font-size: .8125rem; color: var(--color-text-muted, #6F6D67); margin: 0 0 .9rem; }
        .mg-check { display: flex; flex-wrap: wrap; align-items: center; gap: .6rem; }
        .mg-pill { display: inline-flex; align-items: center; gap: .3rem; padding: .25rem .65rem; border-radius: 999px; font-size: .8125rem; background: var(--color-surface-secondary, #F4F2EC); color: var(--color-text-muted, #6F6D67); }
        .mg-pill.is-miss { background: #FBE9E7; color: #A33B2F; }
        .mg-pill.is-ok { background: #E6F3EA; color: #2E7048; }
        .mg-connect > summary { list-style: none; cursor: pointer; display: inline-flex; align-items: center; gap: .4rem; padding: .5rem .9rem; border: 1px solid var(--color-border, #D8D3CA); border-radius: .6rem; background: #fff; font-size: .875rem; font-weight: 500; }
        .mg-connect > summary::-webkit-details-marker { display: none; }
        .mg-connect[open] > summary { margin-bottom: .75rem; }
        @media (max-width: 767.98px) {
            .mg-rec-head { display: none; }
            .mg-rec-grid { grid-template-columns: minmax(0, 1fr); gap: .35rem; }
            .mg-rec-mobile { display: block; }
            .mg-steps4 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .mg-progress { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .mg-dns-row { grid-template-columns: minmax(0, 1fr) auto; }
            .mg-dns-row .mg-dns-key { grid-column: 1 / -1; }
        }
    </style>
@endsection

@section('content')
    @include('customer.business.website._manage-header', ['active' => 'domains'])

    @php
        $published = $website->published_revision_id !== null;
        $platformUrl = route('public.website.home', $website->public_id);
        $addUrl = route('customer.workspaces.businesses.website.domains.store', [$workspaceUid, $businessUid]);
        $domainIds = [$workspaceUid, $businessUid];
    @endphp

    <div class="mg-card mg-live-strip" data-testid="platform-address">
        <span class="mg-live-dot {{ $published ? 'is-live' : '' }}" aria-hidden="true"></span>
        <div class="flex-grow-1" style="min-width:0">
            @if ($published)
                <div class="mg-live-label">Your site is live at</div>
                <div class="mg-mono">{{ $platformUrl }}</div>
            @else
                <div class="mg-live-label">Not published yet</div>
                <div class="mg-muted">Publish your website at least once before connecting a custom domain.</div>
            @endif
        </div>
        @if ($published)
            <a href="{{ $platformUrl }}" target="_blank" rel="noopener" class="d-inline-flex align-items-center gap-1">Open <x-ds-icon name="external-link" size="14" /></a>
        @endif
    </div>

    @if ($domains->isEmpty())
        <div class="mg-card" data-testid="domains-empty">
            <div class="mg-empty">
                <span class="mg-icon-tile mb-3"><x-ds-icon name="globe" size="22" /></span>
                <h2 class="mg-card-title" style="font-size:1.25rem">Use your own domain</h2>
                <p class="mg-muted mx-auto" style="max-width:30rem">Show your website at an address you already own, like <span class="mg-mono">www.yourbusiness.com</span>, instead of the platform address.</p>
                <form method="POST" action="{{ $addUrl }}">
                    @csrf
                    <input type="text" name="domain" class="form-control" placeholder="www.yourbusiness.com" value="{{ old('domain') }}" aria-label="Your domain" required>
                    <x-button type="submit" variant="primary">Connect domain</x-button>
                </form>
                @error('domain')<p class="text-danger mg-muted mt-2 mb-0">{{ $message }}</p>@enderror
            </div>
            <div class="mg-steps4">
                <div class="mg-step4"><span class="mg-num">1</span><div><b>Add your domain</b><span class="mg-muted">One you've already bought.</span></div></div>
                <div class="mg-step4"><span class="mg-num">2</span><div><b>Prove you own it</b><span class="mg-muted">Add one record at your domain provider.</span></div></div>
                <div class="mg-step4"><span class="mg-num">3</span><div><b>We secure it</b><span class="mg-muted">A free certificate, set up for you.</span></div></div>
                <div class="mg-step4"><span class="mg-num">4</span><div><b>You're live</b><span class="mg-muted">Nothing is public until then.</span></div></div>
            </div>
        </div>
    @else
        @foreach ($domains as $domain)
            @php
                $instructions = $instructionsByDomainUid[$domain->uid];
                $status = $domain->status->value;
                // Persisted lifecycle -> the four-step progress bar. Index of the current step (0-based); 4 = all done.
                $current = match ($status) {
                    'pending_verification' => 1,
                    'verified', 'provisioning' => 2,
                    'active' => 4,
                    default => null,
                };
                $steps = [
                    ['Domain added', 'Done'],
                    ['Prove you own it', 'Add a TXT record'],
                    ['Secure certificate', $status === 'verified' ? 'Point traffic, then request' : 'Free, automatic'],
                    ['Live', 'Visitors can see your site'],
                ];
            @endphp
            <div class="mg-card" data-testid="domain-card" data-domain-status="{{ $status }}">
                <div class="mg-domain-head">
                    <div>
                        <div class="mg-domain-name">
                            <span class="mg-icon-tile" style="width:2.25rem;height:2.25rem"><x-ds-icon name="globe" size="18" /></span>
                            <span class="mg-mono">{{ $domain->domain }}</span>
                            @if ($domain->is_primary)
                                <x-badge variant="accent">Primary</x-badge>
                            @else
                                <x-badge variant="neutral">Alias</x-badge>
                            @endif
                        </div>
                        <div class="mg-status-line {{ $status === 'active' ? 'is-live' : ($status === 'failed' ? 'is-bad' : '') }}" data-testid="domain-status-line"><i aria-hidden="true"></i>
                            @switch($status)
                                @case('pending_verification') Not public yet @break
                                @case('verified') DNS verified &middot; not public yet @break
                                @case('provisioning') Certificate provisioning &middot; not public yet @break
                                @case('active') Live @if (! $domain->is_primary) &middot; redirects to the primary domain @endif @break
                                @case('failed') Failed @break
                                @case('removing') Removal pending @break
                            @endswitch
                        </div>
                    </div>
                    @if ($status === 'removing')
                        <form method="POST" action="{{ route('customer.workspaces.businesses.website.domains.destroy', [...$domainIds, $domain->uid]) }}">
                            @csrf
                            @method('DELETE')
                            <x-button variant="ghost" size="sm" type="submit">Retry removal</x-button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('customer.workspaces.businesses.website.domains.destroy', [...$domainIds, $domain->uid]) }}" onsubmit="return confirm('Remove {{ $domain->domain }}? It becomes available for anyone to connect again, including you, from scratch.');">
                            @csrf
                            @method('DELETE')
                            <x-button variant="ghost" size="sm" type="submit">Remove</x-button>
                        </form>
                    @endif
                </div>

                @if ($current !== null)
                    <div class="mg-progress" aria-label="Domain setup progress">
                        @foreach ($steps as $i => [$stepTitle, $stepSub])
                            <div class="{{ $i < $current ? 'is-done' : ($i === $current ? 'is-current' : '') }}" @if ($i === $current) aria-current="step" @endif>
                                <b>{{ $i + 1 }}. {{ $stepTitle }}</b>{{ $i === 0 ? 'Done' : $stepSub }}
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- A verification miss is shown ONCE, as the pill below; the stored reason is only surfaced for later (certificate) failures. --}}
                @if ($domain->failure_reason && $status !== 'pending_verification')
                    <div class="px-4 pb-3"><x-alert variant="danger" class="mb-0">{{ $domain->failure_reason }}</x-alert></div>
                @endif

                @if ($status === 'pending_verification')
                    @php
                        $hostShown = $domain->verificationTxtHostRelative();
                        $miss = $domain->last_checked_at !== null && $domain->failure_reason !== null;
                    @endphp
                    <div class="mg-dns" data-testid="dns-record">
                        <strong>Add this record at your domain provider</strong>
                        <div class="mg-rec" role="table" aria-label="TXT record to add">
                            <div class="mg-rec-grid mg-rec-head" role="row"><span>Type</span><span>Host</span><span>Value</span><span>TTL</span></div>
                            <div class="mg-rec-grid" role="row" title="Full record name: {{ $instructions['ownership']['host'] }}">
                                <div><span class="mg-rec-mobile">Type</span><x-badge variant="neutral">{{ $instructions['ownership']['type'] }}</x-badge></div>
                                <div><span class="mg-rec-mobile">Host</span>
                                    <div class="mg-rec-field"><span class="mg-mono" data-testid="dns-host">{{ $hostShown }}</span>
                                        <button type="button" class="mg-copy" data-copy="{{ $hostShown }}" data-copy-label="host" aria-label="Copy host"><x-ds-icon name="copy" size="16" /></button></div></div>
                                <div><span class="mg-rec-mobile">Value</span>
                                    <div class="mg-rec-field"><span class="mg-mono" data-testid="dns-value">{{ $instructions['ownership']['value'] }}</span>
                                        <button type="button" class="mg-copy" data-copy="{{ $instructions['ownership']['value'] }}" data-copy-label="value" aria-label="Copy value"><x-ds-icon name="copy" size="16" /></button></div></div>
                                <div><span class="mg-rec-mobile">TTL</span><span class="mg-muted">Automatic</span></div>
                            </div>
                        </div>
                        <p class="mg-note"><x-ds-icon name="info" size="14" /> <span>Enter only <span class="mg-mono">{{ $hostShown }}</span> as the host. Most providers add your domain automatically.</span></p>
                        <form method="POST" action="{{ route('customer.workspaces.businesses.website.domains.verify', [...$domainIds, $domain->uid]) }}" class="mg-check">
                            @csrf
                            <x-button type="submit" variant="primary" icon="refresh-cw">Check again</x-button>
                            @if ($miss)
                                <span class="mg-pill is-miss" data-testid="verify-status">Not found yet</span>
                            @else
                                <span class="mg-pill" data-testid="verify-status">Waiting for the record</span>
                            @endif
                            <span class="mg-muted">{{ $domain->last_checked_at ? 'Checked ' . $domain->last_checked_at->diffForHumans() . ' · ' : '' }}changes can take a few minutes</span>
                        </form>
                    </div>
                @elseif ($status === 'verified')
                    @php $records = [['Type', 'CNAME', false], ['Host / Name', $instructions['traffic']['host'], true], ['Value', $instructions['traffic']['cname_target'] ?? 'provided at deployment time', true]]; @endphp
                    <div class="mg-dns">
                        <strong>Ownership verified. Now point this domain at us</strong> <span class="mg-pill is-ok">Verified</span>
                        <p class="mg-muted mb-0">This lets the certificate request succeed.</p>
                        @include('customer.business.website._dns-records', ['records' => $records])
                        <p class="mg-muted">Using an apex domain that can't use CNAME? Use an A record to <span class="mg-mono">{{ $instructions['traffic']['a_record_ip'] ?? 'provided at deployment time' }}</span> instead.</p>
                        <form method="POST" action="{{ route('customer.workspaces.businesses.website.domains.provision', [...$domainIds, $domain->uid]) }}">
                            @csrf
                            <x-button type="submit" variant="primary">Request certificate</x-button>
                        </form>
                    </div>
                @elseif ($status === 'provisioning')
                    <div class="mg-dns">
                        <p class="mg-muted">Certificate requested &mdash; this can take a few minutes.</p>
                        <form method="POST" action="{{ route('customer.workspaces.businesses.website.domains.checkCertificate', [...$domainIds, $domain->uid]) }}">
                            @csrf
                            <x-button type="submit" variant="primary" icon="refresh-cw">Check certificate status</x-button>
                        </form>
                    </div>
                @elseif ($status === 'active')
                    <div class="mg-dns">
                        <p class="mg-muted mb-2">This domain is live{{ $domain->activated_at ? ' since ' . $domain->activated_at->diffForHumans() : '' }}.</p>
                        @if (! $domain->is_primary)
                            <form method="POST" action="{{ route('customer.workspaces.businesses.website.domains.makePrimary', [...$domainIds, $domain->uid]) }}">
                                @csrf
                                <x-button type="submit" variant="secondary">Make this the primary address</x-button>
                            </form>
                        @endif
                    </div>
                @elseif ($status === 'failed')
                    <div class="mg-dns"><p class="mg-muted mb-0">Remove this domain and add it again to retry from the start.</p></div>
                @elseif ($status === 'removing')
                    <div class="mg-dns"><p class="mg-muted mb-0">This domain is no longer served, but we couldn't confirm its removal with our hosting provider yet, so its address is still reserved. Try again above.</p></div>
                @endif
            </div>
        @endforeach

        <details class="mg-connect" data-testid="connect-another" @if ($errors->has('domain') || old('domain')) open @endif>
            <summary><x-ds-icon name="plus" size="14" /> Connect another domain</summary>
            <form method="POST" action="{{ $addUrl }}" class="mg-add-row">
                @csrf
                <input type="text" name="domain" class="form-control" placeholder="www.yourbusiness.com" value="{{ old('domain') }}" aria-label="Another domain" required>
                <x-button type="submit" variant="secondary">Add domain</x-button>
            </form>
            @error('domain')<p class="text-danger mg-muted mt-2 mb-0">{{ $message }}</p>@enderror
        </details>
    @endif
@endsection

@section('page-script')
<script>
document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy]');
    if (!btn) { return; }
    var text = btn.getAttribute('data-copy');
    var done = function () { btn.setAttribute('aria-label', 'Copied'); setTimeout(function () { btn.setAttribute('aria-label', 'Copy ' + btn.dataset.copyLabel); }, 1500); };
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, function () {}); }
});
</script>
@endsection
