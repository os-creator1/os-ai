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
        @media (max-width: 767.98px) {
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
                                <x-badge variant="neutral">Alias &mdash; redirects to primary once active</x-badge>
                            @endif
                        </div>
                        <div class="mt-1">
                            @switch($status)
                                @case('pending_verification') <x-badge variant="warning">Waiting on DNS verification &middot; not public yet</x-badge> @break
                                @case('verified') <x-badge variant="accent">DNS verified &middot; certificate not yet requested</x-badge> @break
                                @case('provisioning') <x-badge variant="accent">Certificate provisioning</x-badge> @break
                                @case('active') <x-badge variant="success">Live</x-badge> @break
                                @case('failed') <x-badge variant="danger">Failed</x-badge> @break
                                @case('removing') <x-badge variant="warning">Removal pending</x-badge> @break
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
                                <b>{{ $i + 1 }}. {{ $stepTitle }}</b>{{ $i < $current && $i === 0 ? 'Done' : $stepSub }}
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($domain->failure_reason)
                    <div class="px-4 pb-3"><x-alert variant="danger" class="mb-0">{{ $domain->failure_reason }}</x-alert></div>
                @endif

                @if ($status === 'pending_verification')
                    @php $records = [['Type', $instructions['ownership']['type'], false], ['Host / Name', $instructions['ownership']['host'], true], ['Value', $instructions['ownership']['value'], true]]; @endphp
                    <div class="mg-dns">
                        <strong>Add this record where you bought your domain</strong>
                        <p class="mg-muted mb-0">Copy each value exactly into your DNS provider's settings.</p>
                        @include('customer.business.website._dns-records', ['records' => $records])
                        <form method="POST" action="{{ route('customer.workspaces.businesses.website.domains.verify', [...$domainIds, $domain->uid]) }}" class="d-flex flex-wrap align-items-center gap-2">
                            @csrf
                            <x-button type="submit" variant="primary" icon="refresh-cw">Check verification</x-button>
                            <span class="mg-muted">DNS changes can take a few minutes to show up. Nothing on this domain is public until it's verified.</span>
                        </form>
                    </div>
                @elseif ($status === 'verified')
                    @php $records = [['Type', 'CNAME', false], ['Host / Name', $instructions['traffic']['host'], true], ['Value', $instructions['traffic']['cname_target'] ?? 'provided at deployment time', true]]; @endphp
                    <div class="mg-dns">
                        <strong>Ownership verified. Now point this domain at us</strong>
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

        <div class="mg-card mg-card-pad">
            <h2 class="mg-card-title">Connect another domain</h2>
            <p class="mg-muted mb-0">Use a domain you already own. Nothing goes public on it until its secure certificate is active.</p>
            <form method="POST" action="{{ $addUrl }}" class="mg-add-row">
                @csrf
                <input type="text" name="domain" class="form-control" placeholder="www.yourbusiness.com" value="{{ old('domain') }}" aria-label="Another domain" required>
                <x-button type="submit" variant="secondary" icon="plus">Add domain</x-button>
            </form>
            @error('domain')<p class="text-danger mg-muted mt-2 mb-0">{{ $message }}</p>@enderror
        </div>
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
