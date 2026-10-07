@extends('layouts/contentLayoutMaster')

@section('title', 'Plans')

@section('content')
    <section id="admin-platform-plans-index">
        @include('admin.partials.flash')

        <div class="card">
            <div class="card-header">
                <div>
                    <h4 class="card-title mb-25">Plans</h4>
                    <p class="mb-0 text-muted">The three plans customers buy. This is the only place plans are edited; a plan is retired by archiving it, never deleted, so every subscription keeps its history.</p>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>Plan</th>
                        <th>Price</th>
                        <th>Business slots</th>
                        <th>Features</th>
                        <th>Workspaces on plan</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($rows as $row)
                        @php($c = $row['catalog'])
                        <tr>
                            <td><strong>{{ $c->display_name }}</strong></td>
                            <td>
                                @if ($c->price !== null)
                                    {{ $c->currency?->code }} {{ number_format((float) $c->price, 2) }} / {{ $c->billing_cycle === 'yearly' ? 'year' : 'month' }}
                                @else
                                    <span class="text-muted">Not priced</span>
                                @endif
                            </td>
                            <td>
                                {{ $c->business_slot_included }} included,
                                {{ $c->unlimited_business_slots ? 'unlimited' : 'up to ' . ($c->business_slot_max ?? $c->business_slot_included) }}
                            </td>
                            <td>{{ $row['featureCount'] }} packaged</td>
                            <td>{{ $row['subscribers'] }}</td>
                            <td>
                                @if (! $c->is_active)
                                    <span class="badge badge-light-secondary">Archived</span>
                                @elseif ($row['plan'] && $row['plan']['sellable'])
                                    <span class="badge badge-light-success">On sale</span>
                                @else
                                    <span class="badge badge-light-warning" title="{{ implode(' ', $row['plan']['blockers'] ?? []) }}">Not on sale</span>
                                @endif
                            </td>
                            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.platform-plans.edit', $c->tier->value) }}">Edit plan</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <p class="text-muted">Revenue, subscriptions and payment health live under <a href="{{ route('admin.platform-billing.index') }}">Billing &amp; Revenue</a>. Per-customer plan changes are made on the customer's Workspace page.</p>
    </section>
@endsection
