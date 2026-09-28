@extends('layouts/contentLayoutMaster')

@section('title', 'Custom domains')

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h4 class="mb-0">Custom domains</h4>
            <x-button variant="secondary" href="{{ route('customer.workspaces.businesses.website.pages.index', [$workspaceUid, $businessUid]) }}">Back to pages</x-button>
        </div>
    </div>

    <x-flash-alert class="mb-3" />

    @if ($website->published_revision_id === null)
        <x-alert variant="warning" class="mb-3">Publish your website at least once before connecting a custom domain.</x-alert>
    @endif

    <x-card title="Connect a domain" class="mb-3">
        <p class="text-caption mb-2">Use a domain you already own, e.g. <code>www.yourbusiness.com</code>. You'll prove you own it, then we request a free TLS certificate — nothing is public on this domain until that certificate is active.</p>
        <form method="POST" action="{{ route('customer.workspaces.businesses.website.domains.store', [$workspaceUid, $businessUid]) }}" class="d-flex gap-2">
            @csrf
            <input type="text" name="domain" class="form-control" placeholder="www.yourbusiness.com" value="{{ old('domain') }}" required>
            <x-button type="submit" variant="primary">Add domain</x-button>
        </form>
        @error('domain')
            <p class="text-danger text-caption mt-2 mb-0">{{ $message }}</p>
        @enderror
    </x-card>

    @forelse ($domains as $domain)
        @php($instructions = $instructionsByDomainUid[$domain->uid])
        <x-card class="mb-3">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <strong>{{ $domain->domain }}</strong>
                    @if ($domain->is_primary)
                        <x-badge variant="accent">Primary</x-badge>
                    @else
                        <x-badge variant="neutral">Alias — redirects to primary once active</x-badge>
                    @endif
                    <span class="d-block">
                        @switch($domain->status->value)
                            @case('pending_verification')
                                <x-badge variant="warning">Waiting on DNS verification</x-badge>
                                @break
                            @case('verified')
                                <x-badge variant="accent">DNS verified — certificate not yet requested</x-badge>
                                @break
                            @case('provisioning')
                                <x-badge variant="accent">Certificate provisioning</x-badge>
                                @break
                            @case('active')
                                <x-badge variant="success">Live</x-badge>
                                @break
                            @case('failed')
                                <x-badge variant="danger">Failed</x-badge>
                                @break
                            @case('removing')
                                <x-badge variant="warning">Removal pending</x-badge>
                                @break
                        @endswitch
                    </span>
                </div>
                @if ($domain->status->value === 'removing')
                    <form method="POST" action="{{ route('customer.workspaces.businesses.website.domains.destroy', [$workspaceUid, $businessUid, $domain->uid]) }}" class="d-inline">
                        @csrf
                        @method('DELETE')
                        <x-button variant="ghost" size="sm" type="submit">Retry removal</x-button>
                    </form>
                @else
                    <form method="POST" action="{{ route('customer.workspaces.businesses.website.domains.destroy', [$workspaceUid, $businessUid, $domain->uid]) }}" class="d-inline" onsubmit="return confirm('Remove {{ $domain->domain }}? It becomes available for anyone to connect again, including you, from scratch.');">
                        @csrf
                        @method('DELETE')
                        <x-button variant="ghost" size="sm" type="submit">Remove</x-button>
                    </form>
                @endif
            </div>

            @if ($domain->failure_reason)
                <x-alert variant="danger" class="mb-2">{{ $domain->failure_reason }}</x-alert>
            @endif

            @if ($domain->status->value === 'pending_verification')
                <p class="text-caption mb-1">Add this exact TXT record at your DNS provider to prove you own this domain:</p>
                <table class="table table-sm mb-2">
                    <tr><td>Type</td><td>{{ $instructions['ownership']['type'] }}</td></tr>
                    <tr><td>Host</td><td><code>{{ $instructions['ownership']['host'] }}</code></td></tr>
                    <tr><td>Value</td><td><code>{{ $instructions['ownership']['value'] }}</code></td></tr>
                </table>
                <p class="text-caption">DNS changes can take a few minutes to take effect — this hasn't been verified yet, so nothing on this domain is public.</p>
                <form method="POST" action="{{ route('customer.workspaces.businesses.website.domains.verify', [$workspaceUid, $businessUid, $domain->uid]) }}">
                    @csrf
                    <x-button type="submit" variant="secondary">Check DNS verification</x-button>
                </form>
            @elseif ($domain->status->value === 'verified')
                <p class="text-caption mb-1">Ownership verified. Now point this domain's traffic at us so the certificate request succeeds:</p>
                <table class="table table-sm mb-2">
                    <tr><td>Type</td><td>CNAME</td></tr>
                    <tr><td>Host</td><td><code>{{ $instructions['traffic']['host'] }}</code></td></tr>
                    <tr><td>Value</td><td><code>{{ $instructions['traffic']['cname_target'] ?? 'provided at deployment time' }}</code></td></tr>
                </table>
                <p class="text-caption">Using an apex domain that can't use CNAME? Use an A record to <code>{{ $instructions['traffic']['a_record_ip'] ?? 'provided at deployment time' }}</code> instead.</p>
                <form method="POST" action="{{ route('customer.workspaces.businesses.website.domains.provision', [$workspaceUid, $businessUid, $domain->uid]) }}">
                    @csrf
                    <x-button type="submit" variant="secondary">Request certificate</x-button>
                </form>
            @elseif ($domain->status->value === 'provisioning')
                <p class="text-caption mb-2">Certificate requested — this can take a few minutes.</p>
                <form method="POST" action="{{ route('customer.workspaces.businesses.website.domains.checkCertificate', [$workspaceUid, $businessUid, $domain->uid]) }}">
                    @csrf
                    <x-button type="submit" variant="secondary">Check certificate status</x-button>
                </form>
            @elseif ($domain->status->value === 'active')
                <p class="text-caption mb-2">This domain is live{{ $domain->activated_at ? ' since ' . $domain->activated_at->diffForHumans() : '' }}.</p>
                @if (! $domain->is_primary)
                    <form method="POST" action="{{ route('customer.workspaces.businesses.website.domains.makePrimary', [$workspaceUid, $businessUid, $domain->uid]) }}">
                        @csrf
                        <x-button type="submit" variant="secondary">Make this the primary address</x-button>
                    </form>
                @endif
            @elseif ($domain->status->value === 'failed')
                <p class="text-caption">Remove this domain and add it again to retry from the start.</p>
            @elseif ($domain->status->value === 'removing')
                <p class="text-caption">This domain is no longer served, but we couldn't confirm its removal with our hosting provider yet, so its address is still reserved. Try again above.</p>
            @endif
        </x-card>
    @empty
        <x-empty-state icon="globe" title="No custom domains yet" description="Connect a domain above to serve your published website there instead of the platform address." />
    @endforelse
@endsection
