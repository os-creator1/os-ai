@extends('layouts/contentLayoutMaster')

@section('title', 'Opportunities')

@section('content')
    <section id="opportunities-index">
        <div class="row">
            <div class="col-12">
                <x-card title="Opportunities">
                        @php
                            $statusOptions = ['' => 'All statuses'];
                            foreach (\App\Enums\Opportunity\OpportunityStatus::cases() as $status) {
                                $statusOptions[$status->value] = ucwords(str_replace('_', ' ', $status->value));
                            }
                        @endphp
                        <form method="GET" action="{{ route('customer.opportunities.index') }}" class="row g-1 mb-2">
                            <div class="col-md-4">
                                <x-select
                                    name="status"
                                    label="Status"
                                    :options="$statusOptions"
                                    :selected="$selectedStatus"
                                />
                            </div>

                            <div class="col-md-4">
                                <x-select
                                    name="freshness"
                                    label="Freshness"
                                    :options="['current' => 'Current', 'stale' => 'Stale', 'all' => 'All']"
                                    :selected="$selectedFreshness"
                                />
                            </div>

                            <div class="col-md-4 d-flex align-items-end">
                                <x-button type="submit" class="me-1">Apply Filters</x-button>
                                <x-button variant="secondary" :href="route('customer.opportunities.index')">Clear Filters</x-button>
                            </div>
                        </form>

                        @if ($opportunities->isEmpty())
                            <x-empty-state title="No opportunities are available right now." />
                        @else
                            <x-table :headers="['Title', 'Status', 'Freshness', 'First detected', '']">
                                    @foreach ($opportunities as $opportunity)
                                        <tr>
                                            <td>{{ $opportunity->title }}</td>
                                            <td>
                                                <x-badge variant="accent">
                                                    {{ ucwords(str_replace('_', ' ', $opportunity->status->value)) }}
                                                </x-badge>
                                            </td>
                                            <td>
                                                <x-badge :variant="$opportunity->freshness->value === 'current' ? 'success' : 'warning'">
                                                    {{ ucfirst($opportunity->freshness->value) }}
                                                </x-badge>
                                            </td>
                                            <td>{{ optional($opportunity->first_detected_at)->format('M j, Y') }}</td>
                                            <td class="text-end">
                                                <x-button variant="outline" size="sm" :href="route('customer.opportunities.show', $opportunity->id)">
                                                    View
                                                </x-button>
                                            </td>
                                        </tr>
                                    @endforeach
                            </x-table>

                            <div class="mt-2">
                                {{ $opportunities->appends(['status' => $selectedStatus, 'freshness' => $selectedFreshness])->links() }}
                            </div>
                        @endif
                </x-card>
            </div>
        </div>
    </section>
@endsection
