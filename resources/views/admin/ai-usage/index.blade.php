@extends('layouts/contentLayoutMaster')

@section('title', 'AI Usage')

@section('content')
    {{-- Unified Business Home & COO contract §10.3 C-9 (slice AI-2) — admin-only.
         Provider cost, tokens, route and model provenance are platform facts and
         appear nowhere else. No prompt or response text exists to show: AI-1
         never persists either. Both lists are paginated; there is no export. --}}
    <section id="admin-ai-usage-index">
        <div class="row">
            <div class="col-12">
                <x-card title="Filter">
                    <form method="GET" action="{{ route('admin.ai-usage.index') }}" class="row g-2 align-items-end" data-role="ai-usage-filters">
                        <div class="col-sm-6 col-lg-3">
                            <label class="text-label" for="ai-usage-period">Period</label>
                            <input type="text" id="ai-usage-period" name="period" class="form-control transition-fast" value="{{ $filters['period_key'] }}" placeholder="YYYY-MM" maxlength="64">
                        </div>
                        <div class="col-sm-6 col-lg-2">
                            <label class="text-label" for="ai-usage-workspace">Account (workspace) ID</label>
                            <input type="text" inputmode="numeric" id="ai-usage-workspace" name="workspace_id" class="form-control transition-fast" value="{{ $filters['workspace_id'] }}">
                        </div>
                        <div class="col-sm-6 col-lg-2">
                            <label class="text-label" for="ai-usage-status">Status</label>
                            <select id="ai-usage-status" name="status" class="form-select transition-fast">
                                <option value="">Any</option>
                                @foreach ($statuses as $status)
                                    <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $status }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <label class="text-label" for="ai-usage-category">Category</label>
                            <select id="ai-usage-category" name="category" class="form-select transition-fast">
                                <option value="">Any</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category }}" @selected($filters['category'] === $category)>{{ $category }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-lg-2">
                            <x-button type="submit" variant="primary">Apply</x-button>
                        </div>
                    </form>
                </x-card>
            </div>

            <div class="col-12">
                <x-card id="admin-ai-usage-periods" :title="'Budget periods — ' . $filters['period_key']">
                    @if ($periods->isEmpty())
                        <x-empty-state icon="cpu" title="No AI usage period has been opened for this filter." />
                    @else
                        <x-table :headers="['Account', 'Scope', 'Business', 'Policy', 'Cap', 'Committed', 'Reserved', 'Interactive committed / reserved', 'Updated']">
                            @foreach ($periods as $period)
                                <tr data-role="ai-usage-period-row">
                                    <td>{{ $period->workspace_name ?? '—' }} <span class="text-caption text-muted">#{{ $period->workspace_id }}</span></td>
                                    <td>{{ $period->scope_type }}</td>
                                    <td>
                                        @if ($period->scope_type === \App\Models\AiUsagePeriod::SCOPE_BUSINESS)
                                            {{ $period->business_name ?? '—' }} <span class="text-caption text-muted">#{{ $period->scope_id }}</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>{{ $period->policy_key }} <span class="text-caption text-muted">v{{ $period->policy_version }}</span></td>
                                    <td class="text-numeric">{{ $usd((int) $period->cap_microusd) }}</td>
                                    <td class="text-numeric">{{ $usd((int) $period->committed_microusd) }}</td>
                                    <td class="text-numeric">{{ $usd((int) $period->reserved_microusd) }}</td>
                                    <td class="text-numeric">{{ $usd((int) $period->interactive_committed_microusd) }} / {{ $usd((int) $period->interactive_reserved_microusd) }}</td>
                                    <td class="text-caption">{{ $period->updated_at }}</td>
                                </tr>
                            @endforeach
                        </x-table>
                        {{ $periods->links() }}
                    @endif
                </x-card>
            </div>

            <div class="col-12">
                <x-card id="admin-ai-usage-ledger" title="Ledger entries (newest first)">
                    @if ($ledger->isEmpty())
                        <x-empty-state icon="list" title="No ledger entries match this filter." />
                    @else
                        <x-table :headers="['#', 'Created / settled', 'Account', 'Business', 'Category / lane', 'Route', 'Provider / model / price v.', 'Status', 'Tokens in / cached / out', 'Estimated', 'Actual', 'Actor', 'Idempotency key']">
                            @foreach ($ledger as $entry)
                                <tr data-role="ai-usage-ledger-row">
                                    <td class="text-numeric">{{ $entry->id }}</td>
                                    <td class="text-caption">{{ $entry->created_at }}<br>{{ $entry->settled_at ?? '—' }}</td>
                                    <td>{{ $entry->workspace_name ?? '—' }} <span class="text-caption text-muted">#{{ $entry->workspace_id }}</span></td>
                                    <td>
                                        @if ($entry->business_id !== null)
                                            {{ $entry->business_name ?? '—' }} <span class="text-caption text-muted">#{{ $entry->business_id }}</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>{{ $entry->category?->value }}<br><span class="text-caption text-muted">{{ $entry->lane?->value }}</span></td>
                                    <td>{{ $entry->model_route?->value }}</td>
                                    <td>{{ $entry->provider }}<br><span class="text-caption">{{ $entry->provider_model ?? '—' }}</span> <span class="text-caption text-muted">v{{ $entry->price_version }}</span></td>
                                    <td>
                                        {{ $entry->status?->value }}
                                        @if ($entry->refusal_reason !== null)
                                            <br><span class="text-caption text-muted">{{ $entry->refusal_reason->value }}</span>
                                        @endif
                                        @if ($entry->refusal_scope !== null)
                                            <br><span class="text-caption text-muted" data-role="ai-usage-refusal-scope">limit: {{ $entry->refusal_scope->value }}</span>
                                        @endif
                                    </td>
                                    <td class="text-numeric">{{ $entry->input_tokens }} / {{ $entry->cached_input_tokens }} / {{ $entry->output_tokens }}</td>
                                    <td class="text-numeric">{{ $usd($entry->estimated_cost_microusd) }}</td>
                                    <td class="text-numeric">{{ $usd($entry->actual_cost_microusd) }}</td>
                                    <td class="text-numeric">{{ $entry->actor_user_id ?? '—' }}</td>
                                    <td class="text-caption text-break">{{ $entry->idempotency_key }}</td>
                                </tr>
                            @endforeach
                        </x-table>
                        {{ $ledger->links() }}
                    @endif
                </x-card>
            </div>
        </div>
    </section>
@endsection
