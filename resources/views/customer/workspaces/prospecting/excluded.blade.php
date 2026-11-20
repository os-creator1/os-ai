@extends('layouts/contentLayoutMaster')

@section('title', 'Outreach excluded prospects')
@section('page-heading', 'own')

@section('content')
@php
    $formatPhone = function (?string $digits) {
        $d = preg_replace('/\D/', '', (string) $digits);
        return (strlen($d) === 11 && $d[0] === '1') ? sprintf('+1 (%s) %s-%s', substr($d, 1, 3), substr($d, 4, 3), substr($d, 7)) : ($d === '' ? '—' : '+' . $d);
    };
    $cols = 'minmax(0,1.8fr) 170px minmax(0,1.2fr) 110px';
@endphp
<div class="og page">
    @include('customer.workspaces.prospecting._nav', ['prospectingActive' => 'prospects', 'ogInside' => true, 'ogActions' => 'prospects', 'importUrl' => route('customer.workspaces.prospecting.prospects.import', $workspaceUid)])

    <div class="filters">@include('customer.workspaces.prospecting._prospect-tabs', ['ptActive' => 'excluded'])</div>

    <div class="alertbar" data-role="stop-protection"><span><b>Opted out — permanent.</b> People who replied STOP are never texted again. This list is read-only: a compliance opt-out cannot be reversed from Outreach.</span></div>

    <section class="card" aria-label="Excluded prospects">
        @if($rows->count() === 0)
            <div class="empty" data-role="excluded-empty"><b>Nobody has opted out</b>People who reply STOP will appear here.</div>
        @else
            <div class="row h" style="grid-template-columns:{{ $cols }}"><span>Business</span><span>Phone</span><span>Reason</span><span>When</span></div>
            @foreach($rows as $p)
                <div class="row" style="grid-template-columns:{{ $cols }}">
                    <a href="{{ route('customer.workspaces.prospecting.prospects.show', [$workspaceUid, $p->uid]) }}"><b>{{ $p->company_name }}</b></a>
                    <span style="font-variant-numeric:tabular-nums">{{ $formatPhone($p->phone) }}</span>
                    <span class="mut">{{ match ($p->stop_reason) { 'opt_out' => 'Replied STOP', 'rejected' => 'Rejected', 'manual' => 'Stopped by you', default => 'Stopped' } }}</span>
                    <span class="mut">{{ $p->stopped_at?->format('M j') ?? '—' }}</span>
                </div>
            @endforeach
            <div class="pager">{{ $rows->links() }}</div>
        @endif
    </section>
</div>
@endsection
