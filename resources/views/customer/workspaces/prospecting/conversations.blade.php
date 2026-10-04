@extends('layouts/contentLayoutMaster')

@section('title', 'Outreach conversations')

@section('content')
    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-0">Outreach</h4>
            <p class="text-caption mb-0">Replies from prospects. Open a conversation to reply yourself; pause the AI first if you want to take over.</p>
        </div>
    </div>

    @include('customer.workspaces.prospecting._nav', ['prospectingActive' => 'conversations'])

    <x-card :padded="true" class="mb-2">
        <form method="get" class="row g-1 align-items-end" data-role="conversation-filters">
            <div class="col-md-3">
                <label class="form-label" for="f_campaign">Campaign</label>
                <select id="f_campaign" name="campaign" class="form-select">
                    <option value="">All campaigns</option>
                    @foreach($campaigns as $campaign)
                        <option value="{{ $campaign->uid }}" @selected($filters['campaign'] === $campaign->uid)>{{ $campaign->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="f_stage">Stage</label>
                <select id="f_stage" name="stage" class="form-select">
                    <option value="">Any stage</option>
                    @foreach($stages as $stage)
                        <option value="{{ $stage->value }}" @selected($filters['stage'] === (string) $stage->value)>{{ \App\Library\AgencyOutreach\OutreachProspectStatusPresenter::stageLabel($stage) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="f_mode">Replies by</label>
                <select id="f_mode" name="mode" class="form-select">
                    <option value="">AI or you</option>
                    <option value="ai" @selected($filters['mode'] === 'ai')>AI</option>
                    <option value="manual" @selected($filters['mode'] === 'manual')>Manual (you)</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="f_outcome">Outcome</label>
                <select id="f_outcome" name="outcome" class="form-select">
                    <option value="">Any</option>
                    <option value="booked" @selected($filters['outcome'] === 'booked')>Booked</option>
                    <option value="rejected" @selected($filters['outcome'] === 'rejected')>Rejected / opted out</option>
                </select>
            </div>
            <div class="col-md-2">
                <x-button type="submit" variant="primary" size="sm">Filter</x-button>
            </div>
        </form>
    </x-card>

    <x-card :padded="true">
        @if($members->isEmpty())
            <x-empty-state icon="message-square" title="No conversations yet"
                            description="Conversations appear here once a prospect has been texted or replies." />
        @else
            <div class="table-responsive">
                <table class="table" data-role="conversations-table">
                    <thead>
                        <tr>
                            <th>Prospect</th>
                            <th>Company</th>
                            <th>Campaign</th>
                            <th>Stage</th>
                            <th>Replies by</th>
                            <th>Last reply</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($members as $member)
                            @php $paused = $member->isAiPaused(); @endphp
                            <tr data-member="{{ $member->uid }}">
                                <td>{{ $member->prospect->contact_name ?: $member->prospect->phone }}</td>
                                <td>{{ $member->prospect->company_name }}</td>
                                <td>{{ $member->campaign?->name ?? '—' }}</td>
                                <td>{{ \App\Library\AgencyOutreach\OutreachProspectStatusPresenter::stageLabel($member->stage) }}</td>
                                <td><x-badge :variant="$paused ? 'warning' : 'success'">{{ $paused ? 'Manual' : 'AI' }}</x-badge></td>
                                <td>{{ $member->last_inbound_at?->diffForHumans() ?? '—' }}</td>
                                <td class="d-flex gap-1">
                                    @if($links[$member->id])
                                        <x-button variant="outline" size="sm" :href="$links[$member->id]">Open conversation</x-button>
                                    @endif
                                    @unless($member->isTerminal())
                                        <form method="post" action="{{ route($paused ? 'customer.workspaces.prospecting.conversations.resume' : 'customer.workspaces.prospecting.conversations.pause', [$workspaceUid, $member->uid]) }}">
                                            @csrf
                                            <x-button type="submit" variant="secondary" size="sm">{{ $paused ? 'Resume AI' : 'Pause AI' }}</x-button>
                                        </form>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $members->links() }}
        @endif
    </x-card>
@endsection
