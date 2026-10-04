@extends('layouts/contentLayoutMaster')

@section('title', 'Rank tracking cost')

@section('content')
    {{-- SEO Keyword Rank Tracking V1 §24 — admin-only, read-only. Platform provider
         cost (what we pay the SERP vendor), never customer-billable usage. No
         provider task ids or credentials are shown. --}}
    <section id="admin-seo-rank-cost">
        <x-card title="Month" class="mb-2">
            <form method="GET" action="{{ route('admin.seo-rank-cost.index') }}" class="row g-2 align-items-end">
                <div class="col-sm-4 col-lg-3">
                    <label class="text-label" for="rank-cost-month">Usage month</label>
                    <input type="text" id="rank-cost-month" name="month" class="form-control" value="{{ $month }}" placeholder="YYYY-MM" maxlength="7">
                </div>
                <div class="col-auto"><button class="btn btn-primary" type="submit">Show</button></div>
                <div class="col-auto text-caption" data-role="provider-state" data-state="{{ $providerState }}">Provider:
                    <strong data-role="provider-switch">{{ match ($providerState) { 'enabled' => 'Enabled', 'not_configured' => 'Disabled (switch on, credentials not configured)', default => 'Disabled' } }}</strong>
                    <span class="d-block">{{ $enabled ? 'Scheduled checks run.' : 'No paid checks will run; stored results stay visible to customers.' }}</span>
                </div>
            </form>
        </x-card>

        <div class="row g-2 mb-2">
            <div class="col-md-4"><x-card :padded="true"><p class="text-caption mb-25">Spend in {{ $month }} (estimated or provider-reported)</p>
                <div class="h3 mb-0" data-role="month-total">{{ $usd((int) $totals->micros) }}</div>
                <p class="text-caption mb-0">{{ (int) $totals->runs }} provider tasks · cap {{ $usd($caps['global_monthly']) }}</p></x-card></div>
            <div class="col-md-4"><x-card :padded="true"><p class="text-caption mb-25">Spend today (UTC)</p>
                <div class="h3 mb-0" data-role="today-total">{{ $usd($todayMicros) }}</div>
                <p class="text-caption mb-0">cap {{ $usd($caps['global_daily']) }}</p></x-card></div>
            <div class="col-md-4"><x-card :padded="true"><p class="text-caption mb-25">Workspace / Agency monthly cap</p>
                <div class="h3 mb-0">{{ $usd($caps['workspace_monthly']) }}</div></x-card></div>
        </div>

        <div class="row g-2 mb-2">
            <div class="col-lg-6"><x-card title="By operation">
                <x-table :headers="['Operation', 'Tasks', 'Cost']">
                    @forelse($byOperation as $r)
                        <tr><td>{{ $r->operation }}</td><td>{{ $r->runs }}</td><td>{{ $usd((int) $r->micros) }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="text-muted">No provider spend this month.</td></tr>
                    @endforelse
                </x-table></x-card></div>
            <div class="col-lg-6"><x-card title="By ledger status (including released)">
                <x-table :headers="['Status', 'Tasks', 'Cost']">
                    @forelse($byStatus as $r)
                        <tr><td>{{ $r->status }}</td><td>{{ $r->runs }}</td><td>{{ $usd((int) $r->micros) }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="text-muted">No ledger rows this month.</td></tr>
                    @endforelse
                </x-table></x-card></div>
        </div>

        <div class="row g-2 mb-2">
            <div class="col-lg-6"><x-card title="By Workspace / Agency">
                <x-table :headers="['Workspace ID', 'Tasks', 'Cost']">
                    @forelse($byWorkspace as $r)
                        <tr><td>{{ $r->workspace_id }}</td><td>{{ $r->runs }}</td><td>{{ $usd((int) $r->micros) }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="text-muted">None.</td></tr>
                    @endforelse
                </x-table></x-card></div>
            <div class="col-lg-6"><x-card title="By Business">
                <x-table :headers="['Business ID', 'Tasks', 'Cost']">
                    @forelse($byBusiness as $r)
                        <tr><td>{{ $r->business_id }}</td><td>{{ $r->runs }}</td><td>{{ $usd((int) $r->micros) }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="text-muted">None.</td></tr>
                    @endforelse
                </x-table>
                {{ $byBusiness->links() }}</x-card></div>
        </div>
    </section>
@endsection
