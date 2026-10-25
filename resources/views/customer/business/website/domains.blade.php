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
                <div class="mg-step4"><span class="mg-num">2</span><div><b>Connect DNS</b><span class="mg-muted">Add two records at your domain provider.</span></div></div>
                <div class="mg-step4"><span class="mg-num">3</span><div><b>We secure it</b><span class="mg-muted">HTTPS is set up automatically.</span></div></div>
                <div class="mg-step4"><span class="mg-num">4</span><div><b>You're live</b><span class="mg-muted">Nothing is public until then.</span></div></div>
            </div>
        </div>
    @else
        @foreach ($domains as $domain)
            @php
                $instructions = $instructionsByDomainUid[$domain->uid];
                $status = $domain->status->value;
                // Three customer-facing steps: 1 Connect DNS, 2 Securing, 3 Live. Index of the current one; 3 = all done.
                $current = match ($status) {
                    'pending_verification' => 0,
                    'verified', 'provisioning' => 1,
                    'active' => 3,
                    'failed' => $domain->verified_at !== null ? 1 : 0,
                    default => null,
                };
                $steps = [['Connect DNS', 'Add two records'], ['Securing', 'Automatic'], ['Live', 'Visitors can see your site']];
                $checkUrl = route('customer.workspaces.businesses.website.domains.verify', [...$domainIds, $domain->uid]);
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
                                @case('pending_verification') Waiting for your DNS records @break
                                @case('verified') DNS connected &middot; securing your domain @break
                                @case('provisioning') Securing your domain &middot; not public yet @break
                                @case('active') Live @if (! $domain->is_primary) &middot; redirects to the primary domain @endif @break
                                @case('failed') Needs attention @break
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
                    <div class="mg-progress" style="grid-template-columns:repeat(3,minmax(0,1fr))" aria-label="Domain setup progress">
                        @foreach ($steps as $i => [$stepTitle, $stepSub])
                            <div class="{{ $i < $current ? 'is-done' : ($i === $current ? ($status === 'failed' ? 'is-failed' : 'is-current') : '') }}" @if ($i === $current) aria-current="step" @endif>
                                <b>{{ $i + 1 }}. {{ $stepTitle }}</b>{{ $i < $current ? 'Done' : $stepSub }}
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- One message per domain: its own status, here. --}}
                @if ($domain->failure_reason && in_array($status, ['failed', 'verified', 'provisioning'], true))
                    <div class="px-4 pb-3"><x-alert variant="{{ $status === 'failed' ? 'danger' : 'info' }}" class="mb-0">{{ $domain->failure_reason }}</x-alert></div>
                @endif

                @if ($status === 'pending_verification')
                    <div class="mg-dns" data-testid="dns-record">
                        @if (! $domainsReady || ! $instructions['ready'])
                            <strong>We're finishing setup for custom domains</strong>
                            <p class="mg-muted mb-0" data-testid="dns-not-ready">Please don't change your DNS yet. This page will show your two records as soon as everything is ready on our side.</p>
                        @else
                            <strong>Connect DNS</strong>
                            <p class="mg-muted mb-0">Add these two records at your domain provider. Leave your email (MX, SPF, DKIM, DMARC) records as they are.</p>
                            <div class="mg-rec" role="table" aria-label="DNS records to add">
                                <div class="mg-rec-grid mg-rec-head" role="row"><span>Type</span><span>Host</span><span>Value</span><span>TTL</span></div>
                                @foreach ($instructions['records'] as $record)
                                    @php $ok = $record['type'] === 'A' ? $domain->dns_a_ok : $domain->dns_cname_ok; @endphp
                                    <div class="mg-rec-grid" role="row" data-testid="dns-row-{{ strtolower($record['type']) }}" @if (! $loop->first) style="border-top:1px solid var(--color-border,#E5E1DA)" @endif>
                                        <div><span class="mg-rec-mobile">Type</span><x-badge variant="neutral">{{ $record['type'] }}</x-badge></div>
                                        <div><span class="mg-rec-mobile">Host</span>
                                            <div class="mg-rec-field"><span class="mg-mono">{{ $record['host'] }}</span>
                                                <button type="button" class="mg-copy" data-copy="{{ $record['host'] }}" data-copy-label="host" aria-label="Copy host"><x-ds-icon name="copy" size="16" /></button></div></div>
                                        <div><span class="mg-rec-mobile">Value</span>
                                            <div class="mg-rec-field"><span class="mg-mono" data-testid="dns-value-{{ strtolower($record['type']) }}">{{ $record['value'] }}</span>
                                                <button type="button" class="mg-copy" data-copy="{{ $record['value'] }}" data-copy-label="value" aria-label="Copy value"><x-ds-icon name="copy" size="16" /></button></div></div>
                                        <div><span class="mg-rec-mobile">TTL</span><span class="mg-muted">{{ $record['ttl'] }}</span>
                                            @if ($domain->last_checked_at) <span class="mg-pill {{ $ok ? 'is-ok' : 'is-miss' }}" data-testid="dns-pill-{{ strtolower($record['type']) }}">{{ $ok ? 'Found' : 'Not found yet' }}</span> @endif</div>
                                    </div>
                                @endforeach
                            </div>
                            <p class="mg-note"><x-ds-icon name="info" size="14" /> <span>Replacing your current website records moves your live traffic to MotionGrove. Your site here is published and ready. HTTPS switches on a few minutes after both records resolve, so visitors may see a browser security warning until then.</span></p>
                            <form method="POST" action="{{ $checkUrl }}" class="mg-check">
                                @csrf
                                <x-button type="submit" variant="primary" icon="refresh-cw">Check again</x-button>
                                <span class="mg-muted">We check automatically, so you can close this page. {{ $domain->last_checked_at ? 'Checked ' . $domain->last_checked_at->diffForHumans() . '. ' : '' }}Changes can take a few minutes.</span>
                            </form>
                        @endif
                    </div>
                @elseif (in_array($status, ['verified', 'provisioning'], true))
                    <div class="mg-dns">
                        <strong>Securing your domain</strong>
                        <p class="mg-muted mb-0" data-testid="securing">We're setting up your secure connection (HTTPS) for {{ $domain->domain }} and www.{{ $domain->domain }}. This is automatic and usually takes a few minutes. Nothing for you to do, and you can close this page.</p>
                    </div>
                @elseif ($status === 'active')
                    <div class="mg-dns">
                        <p class="mg-muted mb-2">Your website is live at <span class="mg-mono">{{ $domain->domain }}</span>@if ($domain->activated_at) since {{ $domain->activated_at->diffForHumans() }}@endif. <span class="mg-mono">www.{{ $domain->domain }}</span> redirects to it.</p>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="https://{{ $domain->domain }}" target="_blank" rel="noopener" class="btn btn-primary d-inline-flex align-items-center gap-1" data-testid="open-website">Open website <x-ds-icon name="external-link" size="14" /></a>
                            @if (! $domain->is_primary)
                                <form method="POST" action="{{ route('customer.workspaces.businesses.website.domains.makePrimary', [...$domainIds, $domain->uid]) }}">
                                    @csrf
                                    <x-button type="submit" variant="secondary">Make this the primary address</x-button>
                                </form>
                            @endif
                        </div>
                    </div>
                @elseif ($status === 'failed')
                    <div class="mg-dns">
                        <form method="POST" action="{{ route('customer.workspaces.businesses.website.domains.retry', [...$domainIds, $domain->uid]) }}" class="mg-check">
                            @csrf
                            <x-button type="submit" variant="primary" icon="refresh-cw">Try again</x-button>
                            <span class="mg-muted">We'll re-check your DNS records and continue from there.</span>
                        </form>
                    </div>
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
