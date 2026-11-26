{{-- White Label tab strip (Branding | Landing Page | Analytics). Expects $agencyWorkspace and $tab. --}}
@php
    $wlRoute = fn (string $name, array $query = []) => route('customer.workspaces.agency.white-label.' . $name, [$agencyWorkspace->uid]) . ($query ? '?' . http_build_query($query) : '');
@endphp
<ul class="nav nav-tabs mb-3" role="tablist" data-role="white-label-tabs">
    <li class="nav-item"><a class="nav-link {{ $tab === 'branding' ? 'active' : '' }}" href="{{ $wlRoute('show') }}" role="tab" aria-selected="{{ $tab === 'branding' ? 'true' : 'false' }}" data-role="tab-branding">Branding</a></li>
    <li class="nav-item"><a class="nav-link {{ $tab === 'landing' ? 'active' : '' }}" href="{{ $wlRoute('show', ['tab' => 'landing']) }}" role="tab" aria-selected="{{ $tab === 'landing' ? 'true' : 'false' }}" data-role="tab-landing">Landing Page</a></li>
    <li class="nav-item"><a class="nav-link {{ $tab === 'analytics' ? 'active' : '' }}" href="{{ $wlRoute('analytics') }}" role="tab" aria-selected="{{ $tab === 'analytics' ? 'true' : 'false' }}" data-role="tab-analytics">Analytics</a></li>
</ul>
