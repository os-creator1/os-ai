@extends('layouts/contentLayoutMaster')

@section('title', 'Opportunity run #' . $run->id)

@section('content')
    <section id="admin-opportunity-run-show">
        <div class="row">
            <div class="col-12 mb-1">
                <x-button variant="secondary" size="sm" :href="route('admin.opportunities.runs.index', ['business_id' => $run->business_id])">
                    &larr; Back to runs
                </x-button>
            </div>

            <div class="col-12">
                <x-card :title="'Opportunity run #' . $run->id">
                        <h5>Run</h5>
                        <div class="row mb-2">
                            <div class="col-md-3">
                                <strong>Run UID</strong>
                                <div class="text-break">{{ $run->uid }}</div>
                            </div>
                            <div class="col-md-3">
                                <strong>Business ID</strong>
                                <div>{{ $run->business_id }}</div>
                            </div>
                            <div class="col-md-3">
                                <strong>Business name</strong>
                                <div>{{ $run->business?->name ?? 'Unknown' }}</div>
                            </div>
                            <div class="col-md-3">
                                <strong>Owning customer</strong>
                                <div>{{ $run->business?->customer?->user?->displayName() ?? 'Unknown' }}</div>
                            </div>
                        </div>

                        <div class="row mb-2">
                            <div class="col-md-3">
                                <strong>Worker</strong>
                                <div>{{ ucfirst(str_replace('_', ' ', $run->worker_key->value)) }}</div>
                            </div>
                            <div class="col-md-3">
                                <strong>Status</strong>
                                <div>{{ ucfirst($run->status->value) }}</div>
                            </div>
                            <div class="col-md-3">
                                <strong>Reason code</strong>
                                <div>{{ $run->reason_code ?? '—' }}</div>
                            </div>
                            <div class="col-md-3">
                                <strong>Producer version</strong>
                                <div>{{ $run->producer_version }}</div>
                            </div>
                        </div>

                        @if ($run->safe_error_summary)
                            <div class="row mb-2">
                                <div class="col-12">
                                    <strong>Safe error summary</strong>
                                    <div class="text-danger">{{ $run->safe_error_summary }}</div>
                                </div>
                            </div>
                        @endif

                        <div class="row mb-2">
                            <div class="col-md-3">
                                <strong>Started</strong>
                                <div>{{ optional($run->started_at)->format('M j, Y g:ia') ?? '—' }}</div>
                            </div>
                            <div class="col-md-3">
                                <strong>Heartbeat</strong>
                                <div>{{ optional($run->heartbeat_at)->format('M j, Y g:ia') ?? '—' }}</div>
                            </div>
                            <div class="col-md-3">
                                <strong>Completed</strong>
                                <div>{{ optional($run->completed_at)->format('M j, Y g:ia') ?? '—' }}</div>
                            </div>
                            <div class="col-md-3">
                                <strong>Abandoned</strong>
                                <div>{{ optional($run->abandoned_at)->format('M j, Y g:ia') ?? '—' }}</div>
                            </div>
                        </div>

                        <hr>

                        <h5>Staged candidates</h5>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-primary">
                                <tr>
                                    <th>ID</th>
                                    <th>UID</th>
                                    <th>Type</th>
                                    <th>Title</th>
                                    <th>Summary</th>
                                    <th>Impact</th>
                                    <th>Urgency</th>
                                    <th>Priority score</th>
                                    <th>Created</th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse ($candidates as $candidate)
                                    <tr>
                                        <td>{{ $candidate->id }}</td>
                                        <td class="text-break">{{ $candidate->uid }}</td>
                                        <td>{{ $candidate->type }}</td>
                                        <td>{{ $candidate->title }}</td>
                                        <td>{{ $candidate->summary }}</td>
                                        <td>{{ $candidate->impact }}</td>
                                        <td>{{ $candidate->urgency }}</td>
                                        <td>{{ $candidate->priority_score }}</td>
                                        <td>{{ optional($candidate->created_at)->format('M j, Y g:ia') ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="9">No staged candidates recorded for this run.</td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                </x-card>
            </div>
        </div>
    </section>
@endsection
