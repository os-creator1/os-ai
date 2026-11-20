@extends('layouts/contentLayoutMaster')

@section('title', 'Outreach import complete')
@section('page-heading', 'own')

@section('content')
@php
    $created = (int) ($report['created'] ?? 0);
    $existing = (int) ($report['existing_in_group'] ?? 0);
    $referenced = (int) ($report['referenced'] ?? 0);
    $skipped = array_sum((array) ($report['skipped'] ?? []));
    $groupUrl = $group && $business ? route('customer.workspaces.businesses.contacts.show', [$workspaceUid, $business->uid, $group->uid]) : null;
    $campaignUrl = $group ? route('customer.workspaces.prospecting.campaigns.index', $workspaceUid) . '?' . http_build_query(['new' => 1, 'group' => $group->uid]) : null;
@endphp
<div class="og page">
    @include('customer.workspaces.prospecting._nav', ['prospectingActive' => 'prospects', 'ogInside' => true])

    <section class="card imp" data-role="import-done">
        <div class="imp-head"><h2>Import complete</h2>@include('customer.workspaces.prospecting._import-stepper')</div>
        <div class="pad" style="display:flex;flex-direction:column;gap:16px">
            <div class="flash" role="status">
                Saved to the contact group <b data-role="done-group">{{ $group?->name ?? ($report['group'] ?? $batch->group_name) }}</b>. No messages were sent and no campaign was started.
            </div>
            <div class="kpis kgrid" data-role="done-counts">
                <div class="k5"><span>New contacts</span><b class="t-g">{{ $created }}</b><small>added to the group</small></div>
                <div class="k5"><span>Already in the group</span><b>{{ $existing }}</b><small>kept as they were</small></div>
                <div class="k5"><span>Linked from another group</span><b>{{ $referenced }}</b><small>number already in your contacts</small></div>
                <div class="k5"><span>Skipped</span><b class="t-a">{{ $skipped }}</b><small>STOP, existing clients, blocked</small></div>
            </div>
            @if(count((array) ($report['fields'] ?? [])) > 0)
                <div data-role="done-fields">
                    <b>Custom fields</b>
                    <div class="chips" style="margin-top:6px">
                        @foreach($report['fields'] as $f)
                            <span class="pl {{ in_array($f['action'], ['create', 'reuse'], true) ? 'g' : 'a' }}" title="{{ $f['note'] }}">{{ $f['label'] }} · {{ ['create' => 'created', 'reuse' => 'reused', 'archived' => 'archived, skipped', 'conflict' => 'conflict, skipped', 'limit' => 'limit, skipped'][$f['action']] ?? $f['action'] }}</span>
                        @endforeach
                    </div>
                    <p class="mut" style="margin-top:6px">{{ (int) ($report['values_saved'] ?? 0) }} values saved. They show under Custom Fields on each contact.</p>
                </div>
            @endif
        </div>
        <div class="foot imp-foot">
            @if($groupUrl)<a class="btn out" data-role="open-group" href="{{ $groupUrl }}">Open contact group</a>@endif
            <span class="grow"></span>
            <a class="btn" href="{{ route('customer.workspaces.prospecting.prospects.index', $workspaceUid) }}">Back to prospects</a>
            @if($campaignUrl)<a class="btn pri" data-role="new-campaign-from-group" href="{{ $campaignUrl }}">New campaign with this group</a>@endif
        </div>
    </section>
</div>
@endsection
