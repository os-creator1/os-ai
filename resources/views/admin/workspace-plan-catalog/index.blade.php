@extends('layouts/contentLayoutMaster')

@section('title', 'Workspace Plan Catalog')

@section('content')
    <section id="admin-workspace-plan-catalog-index">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Workspace Plan Catalog</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-primary">
                                <tr>
                                    <th>Tier</th>
                                    <th>Display name</th>
                                    <th>Price</th>
                                    <th>Billing cycle</th>
                                    <th>Business slots included</th>
                                    <th>Business slot max</th>
                                    <th>Additional slot price ratio</th>
                                    <th>Status</th>
                                    <th>Packaged features</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach ($catalogSummaries as $summary)
                                    <tr>
                                        <td>{{ ucfirst($summary->tier->value) }}</td>
                                        <td>{{ $summary->displayName }}</td>
                                        <td>
                                            @if ($summary->price !== null)
                                                {{ $summary->price }} ({{ $summary->currencyId }})
                                            @else
                                                Not defined
                                            @endif
                                        </td>
                                        <td>{{ $summary->billingCycle }}</td>
                                        <td>{{ $summary->businessSlotIncluded }}</td>
                                        <td>
                                            @if ($summary->unlimitedBusinessSlots)
                                                Unlimited
                                            @else
                                                {{ $summary->businessSlotMax ?? '—' }}
                                            @endif
                                        </td>
                                        <td>{{ $summary->additionalBusinessSlotPriceRatio ?? 'Not defined' }}</td>
                                        <td>
                                            @if ($summary->isActive)
                                                <span class="badge badge-light-success">Active</span>
                                            @else
                                                <span class="badge badge-light-secondary">Inactive</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if (empty($summary->planFeatureKeys))
                                                None
                                            @else
                                                @foreach ($summary->planFeatureKeys as $featureKey)
                                                    <span class="badge {{ $summary->featureAvailability[$featureKey] ? 'badge-light-success' : 'badge-light-secondary' }}">
                                                        {{ $featureKey }}{{ $summary->featureAvailability[$featureKey] ? '' : ' (planned)' }}
                                                    </span>
                                                @endforeach
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
