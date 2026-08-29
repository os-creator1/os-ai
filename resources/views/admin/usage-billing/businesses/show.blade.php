@extends('layouts/contentLayoutMaster')

@section('title', 'Usage Billing — ' . $business->name)

@section('content')
    <section id="admin-usage-billing-business-show">
        <div class="row">
            <div class="col-12">
                <a href="{{ route('admin.businesses.show', $business) }}" class="d-inline-flex align-items-center gap-1 transition-fast text-label mb-2">
                    <x-ds-icon name="arrow-left" size="16" />
                    Back to {{ $business->name }}
                </a>
            </div>

            <div class="col-12">
                @if (session('flash_success'))
                    <x-alert variant="success" icon="check-circle" class="mb-2">{{ session('flash_success') }}</x-alert>
                @endif

                @if (session('flash_error'))
                    <x-alert variant="danger" icon="alert-circle" class="mb-2">{{ session('flash_error') }}</x-alert>
                @endif

                @if ($errors->any())
                    <x-alert variant="danger" icon="alert-circle" class="mb-2">
                        <ul class="mb-0 ps-3">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </x-alert>
                @endif
            </div>

            <div class="col-12">
                @if ($wallet === null)
                    <x-empty-state icon="alert-triangle" title="No wallet exists for this Business." />
                @else
                    <x-card :title="'Wallet — ' . $business->name">
                        <dl class="row mb-3">
                            <dt class="col-sm-3 text-label">Billing status</dt>
                            <dd class="col-sm-9"><x-badge :variant="$wallet->billing_status->value === 'suspended' ? 'danger' : 'success'">{{ $wallet->billing_status->value }}</x-badge></dd>

                            <dt class="col-sm-3 text-label">Available balance</dt>
                            <dd class="col-sm-9 text-numeric">{{ $wallet->available_balance_micro }}</dd>

                            <dt class="col-sm-3 text-label">Reserved balance</dt>
                            <dd class="col-sm-9 text-numeric">{{ $wallet->reserved_balance_micro }}</dd>

                            <dt class="col-sm-3 text-label">Debt balance</dt>
                            <dd class="col-sm-9 text-numeric">{{ $wallet->debt_balance_micro }}</dd>

                            <dt class="col-sm-3 text-label">Monthly spend cap</dt>
                            <dd class="col-sm-9 text-numeric">{{ $wallet->monthly_spend_cap_micro ?? '—' }}</dd>

                            <dt class="col-sm-3 text-label">Committed spend this period</dt>
                            <dd class="col-sm-9 text-numeric">{{ $wallet->committed_spend_this_period_micro }}</dd>

                            <dt class="col-sm-3 text-label">Reserved spend this period</dt>
                            <dd class="col-sm-9 text-numeric">{{ $wallet->reserved_spend_this_period_micro }}</dd>

                            <dt class="col-sm-3 text-label">Auto-recharge (read-only)</dt>
                            <dd class="col-sm-9">
                                {{ $wallet->auto_recharge_enabled ? 'Enabled' : 'Disabled' }}
                                @if ($wallet->auto_recharge_enabled)
                                    — threshold {{ $wallet->auto_recharge_threshold_micro }}, amount {{ $wallet->auto_recharge_amount_micro }}, monthly cap {{ $wallet->monthly_recharge_cap_micro ?? '—' }}
                                @endif
                            </dd>
                        </dl>

                        <div class="d-flex gap-2 flex-wrap">
                            @if ($wallet->billing_status->value !== 'suspended')
                                <form method="POST" action="{{ route('admin.businesses.usage-billing.suspend', $business) }}">
                                    @csrf
                                    <div class="input-group input-group-sm">
                                        <input type="text" name="reason" class="form-control transition-fast" placeholder="Mandatory reason" required maxlength="5000">
                                        <x-button type="submit" variant="outline-danger" size="sm">Suspend Billing</x-button>
                                    </div>
                                </form>
                            @else
                                <form method="POST" action="{{ route('admin.businesses.usage-billing.resume', $business) }}">
                                    @csrf
                                    <div class="input-group input-group-sm">
                                        <input type="text" name="reason" class="form-control transition-fast" placeholder="Mandatory reason" required maxlength="5000">
                                        <x-button type="submit" variant="primary" size="sm">Resume Billing</x-button>
                                    </div>
                                </form>
                            @endif
                        </div>
                    </x-card>
                @endif
            </div>

            <div class="col-12 col-lg-6">
                <x-card title="Configured Feature Limits">
                    @if ($featureLimits->isEmpty())
                        <x-empty-state icon="sliders" title="No per-feature limits configured." />
                    @else
                        <x-table :headers="['Feature', 'Monthly limit']">
                            @foreach ($featureLimits as $limit)
                                <tr>
                                    <td>{{ $limit->feature_key }}</td>
                                    <td class="text-numeric">{{ $limit->monthly_limit_micro ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </x-table>
                    @endif
                </x-card>
            </div>

            <div class="col-12 col-lg-6">
                <x-card :title="'Provider-Cost / Margin — ' . $periodKey">
                    @if ($marginAggregate->isEmpty())
                        <x-empty-state icon="bar-chart-2" title="No metered usage for this period." />
                    @else
                        <x-table :headers="['Feature', 'Retail revenue', 'Provider cost', 'Margin']">
                            @foreach ($marginAggregate as $row)
                                <tr>
                                    <td>{{ $row->feature_key }}</td>
                                    <td class="text-numeric">{{ $row->retail_revenue_display }}</td>
                                    <td class="text-numeric">{{ $row->provider_cost_display }}</td>
                                    <td class="text-numeric">{{ $row->margin_display }}</td>
                                </tr>
                            @endforeach
                        </x-table>
                    @endif
                </x-card>
            </div>

            <div class="col-12">
                <x-card title="Issue Manual / Promotional Credit">
                    <form method="POST" action="{{ route('admin.businesses.usage-billing.credit', $business) }}">
                        @csrf
                        <input type="hidden" name="operation_id" value="{{ old('operation_id', $operationId) }}">
                        <div class="row g-2 align-items-end">
                            <div class="col-sm-3">
                                <label class="text-label">Entry type</label>
                                <select name="entry_type" class="form-select transition-fast" required>
                                    <option value="manual_credit" @selected(old('entry_type') === 'manual_credit')>Manual Credit</option>
                                    <option value="promotional_credit" @selected(old('entry_type') === 'promotional_credit')>Promotional Credit</option>
                                </select>
                            </div>
                            <div class="col-sm-3">
                                <label class="text-label">Amount (micros)</label>
                                <input type="number" name="amount_micro" class="form-control transition-fast" min="1" required value="{{ old('amount_micro') }}">
                            </div>
                            <div class="col-sm-4">
                                <label class="text-label">Reason</label>
                                <input type="text" name="reason" class="form-control transition-fast" required maxlength="5000" value="{{ old('reason') }}">
                            </div>
                            <div class="col-sm-2">
                                <x-button type="submit" variant="primary" size="sm" class="w-100">Issue Credit</x-button>
                            </div>
                        </div>
                    </form>
                </x-card>
            </div>

            <div class="col-12">
                <x-card title="Ledger">
                    <form method="GET" action="{{ route('admin.businesses.usage-billing.show', $business) }}" class="row g-2 mb-3">
                        <div class="col-sm-3">
                            <select name="entry_type" class="form-select form-select-sm transition-fast">
                                <option value="">All entry types</option>
                                @foreach (\App\Enums\Usage\UsageLedgerEntryType::cases() as $case)
                                    <option value="{{ $case->value }}" @selected(request('entry_type') === $case->value)>{{ $case->value }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-sm-3">
                            <input type="date" name="from" class="form-control form-control-sm transition-fast" value="{{ request('from') }}">
                        </div>
                        <div class="col-sm-3">
                            <input type="date" name="to" class="form-control form-control-sm transition-fast" value="{{ request('to') }}">
                        </div>
                        <div class="col-sm-3">
                            <x-button type="submit" variant="outline-primary" size="sm">Filter</x-button>
                        </div>
                    </form>

                    @if ($ledgerEntries->isEmpty())
                        <x-empty-state icon="list" title="No ledger entries match this filter." />
                    @else
                        <x-table :headers="['ID', 'Type', 'Available Δ', 'Debt Δ', 'Reason', 'Created']">
                            @foreach ($ledgerEntries as $entry)
                                <tr>
                                    <td class="text-numeric">{{ $entry->id }}</td>
                                    <td><x-badge variant="info">{{ $entry->entry_type->value }}</x-badge></td>
                                    <td class="text-numeric">{{ $entry->available_delta_micro }}</td>
                                    <td class="text-numeric">{{ $entry->debt_delta_micro }}</td>
                                    <td>{{ $entry->reason ?? '—' }}</td>
                                    <td class="text-caption">{{ $entry->created_at?->format('Y-m-d H:i') }}</td>
                                </tr>
                            @endforeach
                        </x-table>
                        {{ $ledgerEntries->links() }}
                    @endif
                </x-card>
            </div>

            <div class="col-12">
                <x-card title="Recent Funding Attempts">
                    @if ($recentFundingAttempts->isEmpty())
                        <x-empty-state icon="credit-card" title="No funding attempts for this Business." />
                    @else
                        <x-table :headers="['ID', 'Purpose', 'State', 'Amount', 'Action']">
                            @foreach ($recentFundingAttempts as $attempt)
                                <tr>
                                    <td class="text-numeric">{{ $attempt->id }}</td>
                                    <td>{{ $attempt->purpose->value }}</td>
                                    <td><x-badge variant="warning">{{ $attempt->state->value }}</x-badge></td>
                                    <td class="text-numeric">{{ $attempt->expected_amount_micro }}</td>
                                    <td>
                                        @if (in_array($attempt->state->value, ['provider_pending', 'requires_action', 'failed'], true))
                                            <form method="POST" action="{{ route('admin.businesses.usage-billing.funding-attempts.retry', [$business, $attempt->id]) }}">
                                                @csrf
                                                <div class="input-group input-group-sm">
                                                    <input type="text" name="reason" class="form-control transition-fast" placeholder="Mandatory reason" required maxlength="5000">
                                                    <x-button type="submit" variant="primary" size="sm">Retry</x-button>
                                                </div>
                                            </form>
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </x-table>
                    @endif
                </x-card>
            </div>

            <div class="col-12 col-lg-6">
                <x-card title="Billing-Status History">
                    @if ($billingStatusHistory->isEmpty())
                        <x-empty-state icon="clock" title="No billing-status changes recorded." />
                    @else
                        <x-table :headers="['From', 'To', 'Source', 'Actor', 'Reason', 'When']">
                            @foreach ($billingStatusHistory as $transition)
                                <tr>
                                    <td>{{ $transition->from_status }}</td>
                                    <td>{{ $transition->to_status }}</td>
                                    <td>{{ $transition->source->value }}</td>
                                    <td class="text-numeric">{{ $transition->actor_user_id ?? '—' }}</td>
                                    <td>{{ $transition->reason }}</td>
                                    <td class="text-caption">{{ $transition->created_at?->format('Y-m-d H:i') }}</td>
                                </tr>
                            @endforeach
                        </x-table>
                    @endif
                </x-card>
            </div>

            <div class="col-12 col-lg-6">
                <x-card title="Limit-Change History">
                    @if ($limitHistory->isEmpty())
                        <x-empty-state icon="clock" title="No limit changes recorded." />
                    @else
                        <x-table :headers="['Type', 'Feature', 'From', 'To', 'Actor', 'Reason', 'When']">
                            @foreach ($limitHistory as $transition)
                                <tr>
                                    <td>{{ $transition->limit_type->value }}</td>
                                    <td>{{ $transition->feature_key ?? '—' }}</td>
                                    <td class="text-numeric">{{ $transition->from_value_micro ?? '—' }}</td>
                                    <td class="text-numeric">{{ $transition->to_value_micro ?? '—' }}</td>
                                    <td class="text-numeric">{{ $transition->actor_user_id }}</td>
                                    <td>{{ $transition->reason }}</td>
                                    <td class="text-caption">{{ $transition->created_at?->format('Y-m-d H:i') }}</td>
                                </tr>
                            @endforeach
                        </x-table>
                    @endif
                </x-card>
            </div>
        </div>
    </section>
@endsection
