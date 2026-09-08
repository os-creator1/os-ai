@extends('layouts/contentLayoutMaster')

@section('title', __('locale.usage_billing.title'))

@section('content')
    @php
        // Customer Experience Slice 5 (contract §12, §17; brief §5) —
        // presentation only. Every figure is the presenter's or a manager's
        // own integer micro-unit string, formatted here with exact string
        // arithmetic (bcmath), never a float, never a recomputation of a
        // balance, cap or entitlement. Visibility of every control is a
        // server-side fact from BillingProfileManager::billingResponsibilityFor();
        // hiding a form is never the authorization mechanism.
        $currency = $dashboard->wallet['currency_code'] ?? '';
        $money = static function (?string $micro) use ($currency): string {
            if ($micro === null || $micro === '') {
                return '—';
            }

            $negative = str_starts_with($micro, '-');
            $major = bcdiv(ltrim($micro, '-'), '1000000', 2);
            [$whole, $cents] = array_pad(explode('.', $major, 2), 2, '00');
            $grouped = strrev(implode(',', str_split(strrev($whole), 3)));

            return ($negative ? '-' : '') . ($currency !== '' ? $currency . ' ' : '') . $grouped . '.' . $cents;
        };
        $decimal = static function (?string $micro): string {
            return $micro === null || $micro === '' ? '' : bcdiv($micro, '1000000', 2);
        };
        $shellContext = request()->attributes->get('customerContext');
        $isAgencyFrame = $shellContext instanceof \App\Library\Navigation\CustomerContext && $shellContext->isAgency();
        $wallet = $dashboard->wallet;
        $status = $dashboard->business['billing_status'];
        $actorIsPayer = (bool) $responsibility['actor_is_payer'];
        $managesLimits = (bool) $responsibility['actor_manages_limits'];
        $managesAgency = (bool) $responsibility['actor_manages_responsibility'];
        $agencyPaidClientView = $responsibility['agency_paid'] && ! $actorIsPayer && ! $managesAgency;
        $entryLabel = static fn (string $type): string => \Illuminate\Support\Facades\Lang::has('locale.usage_billing.activity.entries.' . $type) ? __('locale.usage_billing.activity.entries.' . $type) : ucfirst(str_replace('_', ' ', $type));
        $purposeLabel = static fn (string $purpose): string => \Illuminate\Support\Facades\Lang::has('locale.usage_billing.activity.purposes.' . $purpose) ? __('locale.usage_billing.activity.purposes.' . $purpose) : ucfirst(str_replace('_', ' ', $purpose));
        $stateLabel = static fn (string $state): string => \Illuminate\Support\Facades\Lang::has('locale.usage_billing.activity.states.' . $state) ? __('locale.usage_billing.activity.states.' . $state) : ucfirst(str_replace('_', ' ', $state));
    @endphp

    <section id="usage-billing-dashboard">
        <div class="row">
            <div class="col-12">
                <a href="{{ route('customer.workspaces.show', $workspaceUid) }}" class="d-inline-flex align-items-center gap-1 transition-fast text-label mb-2">
                    <x-ds-icon name="arrow-left" size="16" aria-hidden="true" />
                    {{ $isAgencyFrame ? __('locale.usage_billing.back_to_agency') : __('locale.usage_billing.back_to_account') }}
                </a>
            </div>

            <div class="col-12">
                @if (session('flash_success'))
                    <x-alert variant="success" icon="check-circle" class="mb-2" role="status">{{ session('flash_success') }}</x-alert>
                @endif

                @if (session('flash_info'))
                    <x-alert variant="neutral" icon="info" class="mb-2" role="status">{{ session('flash_info') }}</x-alert>
                @endif

                @if (session('flash_error'))
                    <x-alert variant="danger" icon="alert-circle" class="mb-2" role="alert">{{ session('flash_error') }}</x-alert>
                @endif

                @if ($errors->any())
                    <x-alert variant="danger" icon="alert-circle" class="mb-2" role="alert">
                        <ul class="mb-0 ps-3">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </x-alert>
                @endif
            </div>

            {{-- 1. Balance --}}
            <div class="col-12">
                <x-card id="usage-billing-wallet" :title="$dashboard->business['name'] . ' — ' . __('locale.usage_billing.title')">
                    @if ($wallet === null)
                        <p class="mb-0">{{ __('locale.usage_billing.not_set_up') }}</p>
                    @else
                        <div class="row g-2 align-items-stretch" data-role="balance-summary">
                            <div class="col-md-4">
                                <p class="text-label mb-25" id="usage-billing-available-label">{{ __('locale.usage_billing.available_balance') }}</p>
                                <p class="text-numeric h3 mb-25" data-role="available-balance" aria-labelledby="usage-billing-available-label">{{ $money($wallet['available_balance_micro']) }}</p>
                                <p class="text-caption mb-0">{{ __('locale.usage_billing.available_balance_help') }}</p>
                            </div>
                            <div class="col-md-4">
                                <p class="text-label mb-25" id="usage-billing-usage-label">{{ __('locale.usage_billing.usage_this_month') }}</p>
                                <p class="text-numeric h4 mb-25" data-role="usage-this-month" aria-labelledby="usage-billing-usage-label">{{ $money($wallet['committed_spend_this_period_micro']) }}</p>
                                <p class="text-caption mb-0">{{ __('locale.usage_billing.usage_this_month_help') }}</p>
                            </div>
                            <div class="col-md-4">
                                <p class="text-label mb-25">{{ __('locale.usage_billing.status') }}</p>
                                <p class="mb-25" role="status" data-role="wallet-status">
                                    @if ($status === 'suspended')
                                        <x-badge variant="danger">{{ __('locale.usage_billing.status_suspended') }}</x-badge>
                                    @elseif ($paidActivityPaused)
                                        <x-badge variant="warning">{{ __('locale.usage_billing.status_paused') }}</x-badge>
                                    @elseif ((int) $wallet['debt_balance_micro'] > 0)
                                        <x-badge variant="danger">{{ __('locale.usage_billing.status_debt') }}</x-badge>
                                    @else
                                        <x-badge variant="success">{{ __('locale.usage_billing.status_active') }}</x-badge>
                                    @endif
                                </p>
                                @if ($status === 'suspended')
                                    <p class="text-caption mb-0">{{ __('locale.usage_billing.suspended_help') }}</p>
                                @elseif ((int) $wallet['debt_balance_micro'] > 0)
                                    <p class="text-caption mb-0">{{ __('locale.usage_billing.amount_owed') }}: <span class="text-numeric">{{ $money($wallet['debt_balance_micro']) }}</span>. {{ __('locale.usage_billing.amount_owed_help') }}</p>
                                @endif
                            </div>
                        </div>

                        @if ((int) $wallet['reserved_balance_micro'] > 0)
                            <p class="text-caption mt-2 mb-0">{{ __('locale.usage_billing.held_for_work_in_progress') }}: <span class="text-numeric">{{ $money($wallet['reserved_balance_micro']) }}</span>. {{ __('locale.usage_billing.held_help') }}</p>
                        @endif
                    @endif
                </x-card>
            </div>

            {{-- 2. Billing responsibility (plain statement; the selector lives in the account frame, contract §12.4) --}}
            <div class="col-12">
                <x-card id="usage-billing-responsibility" :title="__('locale.usage_billing.responsibility.title')">
                    @if ($agencyPaidClientView)
                        <p class="mb-1 fw-bolder" data-role="responsibility-statement">{{ __('locale.usage_billing.responsibility.agency_manages') }}</p>
                        <p class="text-caption mb-0">{{ __('locale.usage_billing.responsibility.agency_manages_help') }}</p>
                    @elseif (! $responsibility['is_agency'])
                        <p class="mb-1 fw-bolder" data-role="responsibility-statement">{{ __('locale.usage_billing.responsibility.you_pay') }}</p>
                        <p class="text-caption mb-0">{{ __('locale.usage_billing.responsibility.you_pay_help') }}</p>
                    @elseif ($responsibility['payer_type'] === 'workspace')
                        <p class="mb-1 fw-bolder" data-role="responsibility-statement">{{ __('locale.usage_billing.responsibility.agency_pays') }}</p>
                        <p class="text-caption mb-1">{{ __('locale.usage_billing.responsibility.agency_pays_help') }}</p>
                        @if ($managesAgency)
                            <p class="text-caption mb-0">{{ __('locale.usage_billing.responsibility.manage_where') }}</p>
                        @endif
                    @else
                        <p class="mb-1 fw-bolder" data-role="responsibility-statement">{{ __('locale.usage_billing.responsibility.client_pays') }}</p>
                        <p class="text-caption mb-1">{{ __('locale.usage_billing.responsibility.client_pays_help') }}</p>
                        @if ($managesAgency)
                            <p class="text-caption mb-0">{{ __('locale.usage_billing.responsibility.manage_where') }}</p>
                        @endif
                    @endif
                </x-card>
            </div>

            {{-- 3. Funding — only the payer ever sees add-funds / automatic top-up (T-PAYER-4) --}}
            @if ($actorIsPayer)
                <div class="col-12">
                    <x-card id="usage-billing-funding" :title="__('locale.usage_billing.add_funds.title')">
                        @if (! $dashboard->providerConfigured)
                            <p class="mb-0 text-caption">{{ __('locale.usage_billing.add_funds.not_configured') }}</p>
                        @elseif ($wallet === null)
                            <p class="mb-0">{{ __('locale.usage_billing.not_set_up') }}</p>
                        @else
                            @include('customer.business.usage-billing.partials.payment-method', ['dashboard' => $dashboard, 'workspaceUid' => $workspaceUid, 'businessUid' => $businessUid])

                            <hr>

                            <p class="mb-25" id="usage-billing-add-funds-help">{{ __('locale.usage_billing.add_funds.help') }}</p>
                            <p class="text-caption mb-25">{{ __('locale.usage_billing.add_funds.who_pays', ['payer' => $responsibility['payer_type'] === 'workspace' ? __('locale.usage_billing.responsibility.agency_short') : __('locale.usage_billing.responsibility.business_short')]) }}</p>
                            <p class="text-caption mb-1" id="usage-billing-add-funds-minimum">{{ __('locale.usage_billing.add_funds.minimum', ['amount' => $money($minimumTopUpMicro)]) }}</p>
                            @if ((int) $wallet['available_balance_micro'] > 0)
                                <p class="text-caption mb-1" data-role="existing-balance-note">{{ __('locale.usage_billing.add_funds.existing_balance', ['amount' => $money($wallet['available_balance_micro'])]) }}</p>
                            @endif

                            @if ($dashboard->paymentMethod === null)
                                <p class="text-caption mb-1" data-role="add-funds-needs-method">{{ __('locale.usage_billing.add_funds.needs_payment_method') }}</p>
                            @endif

                            <form method="POST" action="{{ route('customer.workspaces.businesses.usage-billing.top-up.initiate', [$workspaceUid, $businessUid]) }}" id="usage-billing-top-up-form" class="row g-1 align-items-end" novalidate>
                                @csrf
                                <div class="col-sm-5">
                                    <label class="form-label text-label" for="usage-billing-top-up-amount">{{ __('locale.usage_billing.add_funds.amount_label') }} ({{ $currency }})</label>
                                    <input type="text" inputmode="decimal" class="form-control transition-fast" id="usage-billing-top-up-amount" name="amount" value="{{ old('amount', $decimal($minimumTopUpMicro)) }}" aria-describedby="usage-billing-add-funds-help usage-billing-add-funds-minimum" required>
                                </div>
                                <div class="col-sm-auto">
                                    <x-button type="submit" variant="primary">{{ __('locale.usage_billing.add_funds.button') }}</x-button>
                                </div>
                            </form>
                        @endif
                    </x-card>
                </div>

                <div class="col-12">
                    <x-card id="usage-billing-auto-top-up" :title="__('locale.usage_billing.auto_top_up.title')">
                        @if (! $dashboard->providerConfigured)
                            <p class="mb-0 text-caption">{{ __('locale.usage_billing.add_funds.not_configured') }}</p>
                        @elseif ($wallet === null)
                            <p class="mb-0">{{ __('locale.usage_billing.not_set_up') }}</p>
                        @else
                            <p class="mb-1" id="usage-billing-auto-top-up-help">{{ __('locale.usage_billing.auto_top_up.help') }}</p>
                            <p class="mb-1 fw-bolder" role="status" data-role="auto-top-up-state">
                                @if ($dashboard->autoRecharge['enabled'])
                                    {{ __('locale.usage_billing.auto_top_up.on', ['amount' => $money($dashboard->autoRecharge['amount_micro']), 'threshold' => $money($dashboard->autoRecharge['threshold_micro'])]) }}
                                @else
                                    {{ __('locale.usage_billing.auto_top_up.off') }}
                                @endif
                            </p>
                            @if ($dashboard->autoRecharge['consecutive_recharge_failures'] > 0)
                                <x-alert variant="warning" icon="alert-circle" class="mb-1" role="alert">{{ __('locale.usage_billing.auto_top_up.failed_notice', ['count' => $dashboard->autoRecharge['consecutive_recharge_failures']]) }}</x-alert>
                            @endif
                            @if ((int) $dashboard->autoRecharge['recharged_this_period_micro'] > 0)
                                <p class="text-caption mb-1">{{ __('locale.usage_billing.auto_top_up.recharged_this_month', ['amount' => $money($dashboard->autoRecharge['recharged_this_period_micro'])]) }}</p>
                            @endif

                            <form method="POST" action="{{ route('customer.workspaces.businesses.usage-billing.auto-recharge.configure', [$workspaceUid, $businessUid]) }}" id="usage-billing-auto-recharge-form" novalidate>
                                @csrf
                                <input type="hidden" name="auto_recharge_enabled" value="0">
                                <div class="form-check mb-1">
                                    <input class="form-check-input" type="checkbox" name="auto_recharge_enabled" id="usage-billing-auto-recharge-enabled" value="1" @checked(old('auto_recharge_enabled', $dashboard->autoRecharge['enabled'])) aria-describedby="usage-billing-auto-top-up-help">
                                    <label class="form-check-label text-label" for="usage-billing-auto-recharge-enabled">{{ __('locale.usage_billing.auto_top_up.enable_label') }}</label>
                                </div>

                                <fieldset class="mb-1">
                                    <legend class="form-label text-label fs-6">{{ __('locale.usage_billing.auto_top_up.amount_label') }}</legend>
                                    <p class="text-caption mb-50" id="usage-billing-auto-recharge-amount-help">{{ __('locale.usage_billing.auto_top_up.amount_help') }}</p>
                                    <div class="d-flex flex-wrap gap-1" data-role="auto-recharge-presets">
                                        @foreach ($autoRechargePresetsMicro as $preset)
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="auto_recharge_amount_micro" id="usage-billing-auto-recharge-amount-{{ $preset }}" value="{{ $preset }}" @checked((string) old('auto_recharge_amount_micro', $dashboard->autoRecharge['amount_micro']) === (string) $preset) aria-describedby="usage-billing-auto-recharge-amount-help">
                                                <label class="form-check-label" for="usage-billing-auto-recharge-amount-{{ $preset }}">{{ $money($preset) }}</label>
                                            </div>
                                        @endforeach
                                    </div>
                                </fieldset>

                                <div class="row g-1">
                                    <div class="col-sm-6">
                                        <label class="form-label text-label" for="usage-billing-auto-recharge-threshold">{{ __('locale.usage_billing.auto_top_up.threshold_label') }} ({{ $currency }})</label>
                                        <input type="text" inputmode="decimal" class="form-control transition-fast" id="usage-billing-auto-recharge-threshold" name="auto_recharge_threshold" value="{{ old('auto_recharge_threshold', $decimal($dashboard->autoRecharge['threshold_micro'])) }}">
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label text-label" for="usage-billing-auto-recharge-cap">{{ __('locale.usage_billing.auto_top_up.monthly_limit_label') }} ({{ $currency }})</label>
                                        <input type="text" inputmode="decimal" class="form-control transition-fast" id="usage-billing-auto-recharge-cap" name="monthly_recharge_cap" value="{{ old('monthly_recharge_cap', $decimal($dashboard->autoRecharge['monthly_cap_micro'])) }}" aria-describedby="usage-billing-auto-recharge-cap-help">
                                        <p class="text-caption mb-0" id="usage-billing-auto-recharge-cap-help">{{ __('locale.usage_billing.auto_top_up.monthly_limit_help') }}</p>
                                    </div>
                                </div>

                                <p class="text-caption mt-1 mb-1">{{ __('locale.usage_billing.auto_top_up.charge_note') }}</p>
                                <x-button type="submit" variant="outline">{{ __('locale.usage_billing.auto_top_up.button') }}</x-button>
                            </form>
                        @endif
                    </x-card>
                </div>
            @endif

            {{-- 4. Spending controls --}}
            @if ($wallet !== null && $managesLimits)
                <div class="col-12">
                    <x-card id="usage-billing-spending" :title="__('locale.usage_billing.spending.title')">
                        <form method="POST" action="{{ route('customer.workspaces.businesses.usage-billing.spend-cap', [$workspaceUid, $businessUid]) }}" class="mb-2" novalidate>
                            @csrf
                            <input type="hidden" name="control" value="business_spend_cap">
                            <label class="form-label text-label" for="usage-billing-spend-cap">{{ __('locale.usage_billing.spending.monthly_limit_label') }} ({{ $currency }})</label>
                            <p class="text-caption mb-50" id="usage-billing-spend-cap-help">{{ __('locale.usage_billing.spending.monthly_limit_help') }}</p>
                            <p class="mb-50" data-role="spend-cap-state">
                                @if ($wallet['monthly_spend_cap_micro'] === null)
                                    {{ __('locale.usage_billing.spending.no_limit') }}
                                @else
                                    <span class="text-numeric">{{ $money($wallet['monthly_spend_cap_micro']) }}</span> &mdash; {{ __('locale.usage_billing.spending.remaining', ['amount' => $money($wallet['remaining_headroom_micro'])]) }}
                                @endif
                            </p>
                            <div class="row g-1 align-items-end">
                                <div class="col-sm-5">
                                    <input type="text" inputmode="decimal" class="form-control transition-fast" id="usage-billing-spend-cap" name="monthly_spend_cap" value="{{ old('monthly_spend_cap', $decimal($wallet['monthly_spend_cap_micro'])) }}" aria-describedby="usage-billing-spend-cap-help">
                                </div>
                                <div class="col-sm-auto">
                                    <x-button type="submit" variant="outline">{{ __('locale.usage_billing.spending.button') }}</x-button>
                                </div>
                            </div>
                        </form>

                        <hr>

                        <h3 class="text-section-heading h5" id="usage-billing-pause-title">{{ __('locale.usage_billing.spending.pause_title') }}</h3>
                        <p class="text-caption mb-1" id="usage-billing-pause-help">{{ __('locale.usage_billing.spending.pause_help') }}</p>
                        <form method="POST" action="{{ route('customer.workspaces.businesses.usage-billing.spend-cap', [$workspaceUid, $businessUid]) }}" novalidate aria-labelledby="usage-billing-pause-title" aria-describedby="usage-billing-pause-help" data-role="pause-form">
                            @csrf
                            <input type="hidden" name="control" value="business_pause">
                            @if ($paidActivityPaused)
                                <input type="hidden" name="paused" value="0">
                                <p class="mb-1 fw-bolder" role="status" data-role="pause-state">{{ __('locale.usage_billing.status_paused') }}</p>
                                <x-button type="submit" variant="primary">{{ __('locale.usage_billing.spending.resume_button') }}</x-button>
                            @else
                                <input type="hidden" name="paused" value="1">
                                <div class="form-check mb-1">
                                    <input class="form-check-input" type="checkbox" name="confirm_pause" id="usage-billing-pause-confirm" value="1">
                                    <label class="form-check-label" for="usage-billing-pause-confirm">{{ __('locale.usage_billing.spending.pause_confirm') }}</label>
                                </div>
                                <x-button type="submit" variant="danger">{{ __('locale.usage_billing.spending.pause_button') }}</x-button>
                            @endif
                        </form>

                        @if ($managesAgency && $workspaceControls !== null)
                            <hr>
                            <h3 class="text-section-heading h5" id="usage-billing-agency-title">{{ __('locale.usage_billing.spending.agency_title') }}</h3>
                            <p class="text-caption mb-1" id="usage-billing-agency-help">{{ __('locale.usage_billing.spending.agency_help') }}</p>
                            <p class="text-caption mb-1" data-role="agency-spent">{{ __('locale.usage_billing.spending.agency_spent', ['amount' => $money($workspaceControls['workspace_paid_spend_this_period_micro'])]) }}</p>
                            @if ($workspaceControls['paid_activity_paused'])
                                <x-alert variant="warning" icon="alert-circle" class="mb-1" role="status" data-role="agency-paused">{{ __('locale.usage_billing.spending.agency_paused_notice') }}</x-alert>
                            @endif
                            <form method="POST" action="{{ route('customer.workspaces.businesses.usage-billing.spend-cap', [$workspaceUid, $businessUid]) }}" novalidate aria-labelledby="usage-billing-agency-title" data-role="agency-controls-form">
                                @csrf
                                <input type="hidden" name="control" value="workspace_controls">
                                <div class="row g-1">
                                    <div class="col-sm-6">
                                        <label class="form-label text-label" for="usage-billing-agency-spend-cap">{{ __('locale.usage_billing.spending.agency_monthly_limit_label') }} ({{ $currency }})</label>
                                        <input type="text" inputmode="decimal" class="form-control transition-fast" id="usage-billing-agency-spend-cap" name="workspace_monthly_spend_cap" value="{{ old('workspace_monthly_spend_cap', $decimal($workspaceControls['monthly_aggregate_spend_cap_micro'])) }}" aria-describedby="usage-billing-agency-spend-cap-help">
                                        <p class="text-caption mb-0" id="usage-billing-agency-spend-cap-help">{{ __('locale.usage_billing.spending.agency_monthly_limit_help') }}</p>
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label text-label" for="usage-billing-agency-recharge-cap">{{ __('locale.usage_billing.spending.agency_recharge_limit_label') }} ({{ $currency }})</label>
                                        <input type="text" inputmode="decimal" class="form-control transition-fast" id="usage-billing-agency-recharge-cap" name="workspace_monthly_recharge_cap" value="{{ old('workspace_monthly_recharge_cap', $decimal($workspaceControls['monthly_aggregate_recharge_cap_micro'])) }}" aria-describedby="usage-billing-agency-recharge-cap-help">
                                        <p class="text-caption mb-0" id="usage-billing-agency-recharge-cap-help">{{ __('locale.usage_billing.spending.agency_recharge_limit_help') }}</p>
                                    </div>
                                </div>
                                <input type="hidden" name="workspace_paused" value="0">
                                <div class="form-check my-1">
                                    <input class="form-check-input" type="checkbox" name="workspace_paused" id="usage-billing-agency-paused" value="1" @checked($workspaceControls['paid_activity_paused'])>
                                    <label class="form-check-label" for="usage-billing-agency-paused">{{ __('locale.usage_billing.spending.agency_pause_label') }}</label>
                                </div>
                                <x-button type="submit" variant="outline">{{ __('locale.usage_billing.spending.agency_button') }}</x-button>
                            </form>
                        @endif
                    </x-card>
                </div>

                {{-- 5. Limits by capability — curated catalogue, never a typed key (E-14) --}}
                <div class="col-12">
                    <x-card id="usage-billing-feature-limits" :title="__('locale.usage_billing.capabilities.title')">
                        <p class="text-caption mb-1" id="usage-billing-capability-help">{{ __('locale.usage_billing.capabilities.help') }}</p>

                        @if (empty($dashboard->featureLimits))
                            <p>{{ __('locale.usage_billing.capabilities.none') }}</p>
                        @else
                            <x-table :headers="[__('locale.usage_billing.capabilities.select_label'), __('locale.usage_billing.capabilities.limit_label'), __('locale.usage_billing.capabilities.platform_ceiling')]" class="mb-2">
                                @foreach ($dashboard->featureLimits as $limit)
                                    <tr data-role="capability-limit" data-capability="{{ $limit['feature_key'] }}">
                                        <td>{{ $capabilityLabel($limit['feature_key']) }}</td>
                                        <td class="text-numeric">{{ $limit['monthly_limit_micro'] !== null ? $money($limit['monthly_limit_micro']) : '—' }}</td>
                                        <td class="text-numeric">{{ $limit['safety_limit_micro'] !== null ? $money($limit['safety_limit_micro']) : '—' }}</td>
                                    </tr>
                                @endforeach
                            </x-table>
                        @endif

                        @if (empty($capabilities))
                            <p class="text-caption mb-0">{{ __('locale.usage_billing.capabilities.none') }}</p>
                        @else
                            <form method="POST" id="usage-billing-feature-limit-form" class="row g-1 align-items-end" data-base-action="{{ route('customer.workspaces.businesses.usage-billing.show', [$workspaceUid, $businessUid]) }}/feature-limits/" novalidate>
                                @csrf
                                <div class="col-sm-5">
                                    <label class="form-label text-label" for="usage-billing-capability">{{ __('locale.usage_billing.capabilities.select_label') }}</label>
                                    <select class="form-select form-control transition-fast" id="usage-billing-capability" name="capability" aria-describedby="usage-billing-capability-help" required>
                                        @foreach ($capabilities as $key => $capability)
                                            <option value="{{ $key }}">{{ $capability['label'] }}@if($capability['help']) — {{ $capability['help'] }}@endif</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-sm-4">
                                    <label class="form-label text-label" for="usage-billing-feature-limit">{{ __('locale.usage_billing.capabilities.limit_label') }} ({{ $currency }})</label>
                                    <input type="text" inputmode="decimal" class="form-control transition-fast" id="usage-billing-feature-limit" name="monthly_limit" aria-describedby="usage-billing-feature-limit-help">
                                    <p class="text-caption mb-0" id="usage-billing-feature-limit-help">{{ __('locale.usage_billing.capabilities.limit_help') }}</p>
                                </div>
                                <div class="col-sm-auto">
                                    <x-button type="submit" variant="outline">{{ __('locale.usage_billing.capabilities.button') }}</x-button>
                                </div>
                            </form>
                        @endif
                    </x-card>
                </div>
            @endif

            {{-- 6. Billing contact --}}
            @if ($managesLimits)
                <div class="col-12">
                    <x-card id="usage-billing-contact" title="Billing contact">
                        @if ($dashboard->billingContact === null)
                            <p>No billing contact configured.</p>
                        @else
                            <dl class="row">
                                <dt class="col-sm-3 text-label">Name</dt>
                                <dd class="col-sm-9">{{ $dashboard->billingContact['name'] }}</dd>

                                <dt class="col-sm-3 text-label">Email</dt>
                                <dd class="col-sm-9">{{ $dashboard->billingContact['email'] }}</dd>

                                <dt class="col-sm-3 text-label">Notifications</dt>
                                <dd class="col-sm-9">{{ $dashboard->billingContact['notification_opt_in'] ? 'Enabled' : 'Disabled' }}</dd>
                            </dl>
                        @endif

                        <form method="POST" action="{{ route('customer.workspaces.businesses.usage-billing.billing-contact', [$workspaceUid, $businessUid]) }}" novalidate>
                            @csrf

                            <div class="mb-1">
                                <label class="form-label text-label" for="usage-billing-contact-name">Contact name</label>
                                <input type="text" class="form-control transition-fast" id="usage-billing-contact-name" name="contact_name" value="{{ old('contact_name', $dashboard->billingContact['name'] ?? '') }}">
                            </div>

                            <div class="mb-1">
                                <label class="form-label text-label" for="usage-billing-contact-email">Contact email</label>
                                <input type="email" class="form-control transition-fast" id="usage-billing-contact-email" name="contact_email" value="{{ old('contact_email', $dashboard->billingContact['email'] ?? '') }}">
                            </div>

                            <div class="form-check mb-1">
                                <input type="hidden" name="notification_opt_in" value="0">
                                <input class="form-check-input" type="checkbox" name="notification_opt_in" id="usage-billing-contact-notify" value="1" @checked(old('notification_opt_in', $dashboard->billingContact['notification_opt_in'] ?? true))>
                                <label class="form-check-label text-label" for="usage-billing-contact-notify">Send billing notifications to this contact</label>
                            </div>

                            <x-button type="submit" variant="outline">Update billing contact</x-button>
                        </form>
                    </x-card>
                </div>
            @endif

            {{-- 7. Activity — readable labels, never raw keys or states (E-14, §20 C-9) --}}
            <div class="col-12">
                <x-card id="usage-billing-ledger" :title="__('locale.usage_billing.activity.title')">
                    @if ($dashboard->ledger->isEmpty())
                        <x-empty-state icon="receipt" :title="__('locale.usage_billing.activity.empty')" />
                    @else
                        <x-table :headers="__('locale.usage_billing.activity.headers')">
                            @foreach ($dashboard->ledger as $entry)
                                <tr>
                                    <td class="text-caption">{{ $entry->createdAt->format('Y-m-d H:i') }}</td>
                                    <td>{{ $entryLabel($entry->entryType) }}</td>
                                    <td>{{ $capabilityLabel($entry->featureKey) }}</td>
                                    <td class="text-numeric">{{ $money($entry->signedAmountMicro) }}</td>
                                </tr>
                            @endforeach
                        </x-table>

                        <div class="mt-2">
                            <x-pagination :paginator="$dashboard->ledger" />
                        </div>
                    @endif
                </x-card>
            </div>

            <div class="col-12">
                <x-card id="usage-billing-funding-history" :title="__('locale.usage_billing.activity.funding_title')">
                    @if ($dashboard->fundingHistory->isEmpty())
                        <x-empty-state icon="wallet" :title="__('locale.usage_billing.activity.funding_empty')" />
                    @else
                        <x-table :headers="__('locale.usage_billing.activity.funding_headers')">
                            @foreach ($dashboard->fundingHistory as $attempt)
                                <tr data-role="funding-attempt" data-result="{{ $attempt['state'] }}">
                                    <td class="text-caption">{{ $attempt['created_at']->format('Y-m-d H:i') }}</td>
                                    <td>{{ $purposeLabel($attempt['purpose']) }}</td>
                                    <td>{{ $attempt['payment_method_display'] }}</td>
                                    <td class="text-numeric">{{ $money($attempt['amount_micro']) }}</td>
                                    <td>
                                        @if (in_array($attempt['state'], ['failed', 'canceled', 'disputed'], true))
                                            <x-badge variant="danger">{{ $stateLabel($attempt['state']) }}</x-badge>
                                        @elseif ($attempt['state'] === 'succeeded')
                                            <x-badge variant="success">{{ $stateLabel($attempt['state']) }}</x-badge>
                                        @else
                                            <x-badge variant="neutral">{{ $stateLabel($attempt['state']) }}</x-badge>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </x-table>

                        <div class="mt-2">
                            <x-pagination :paginator="$dashboard->fundingHistory" />
                        </div>
                    @endif
                </x-card>
            </div>
        </div>
    </section>

    <script>
        (function () {
            // The capability is chosen from the curated list; the route
            // carries it as a path segment, so the form's action is set
            // from the selected option — never from typed text.
            var featureLimitForm = document.getElementById('usage-billing-feature-limit-form');

            if (featureLimitForm) {
                var updateAction = function () {
                    var select = document.getElementById('usage-billing-capability');
                    featureLimitForm.setAttribute('action', featureLimitForm.getAttribute('data-base-action') + encodeURIComponent(select.value));
                };
                updateAction();
                document.getElementById('usage-billing-capability').addEventListener('change', updateAction);
            }

            // RFC-005 M3 contract §18/§18.2 — Stripe.js is loaded only when
            // the payment-method setup button is actually rendered (i.e.
            // providerConfigured === true). The publishable key is the only
            // Stripe-related value embedded server-side; the client_secret
            // is fetched per-request and never stored.
            var paymentMethodButton = document.getElementById('usage-billing-payment-method-submit');

            if (paymentMethodButton) {
                var stripeScript = document.createElement('script');
                stripeScript.src = 'https://js.stripe.com/v3/';
                stripeScript.onload = function () {
                    var stripe = Stripe(paymentMethodButton.getAttribute('data-publishable-key'));
                    var elements = stripe.elements();
                    var card = elements.create('card');
                    card.mount('#usage-billing-card-element');

                    paymentMethodButton.addEventListener('click', function () {
                        var errorEl = document.getElementById('usage-billing-card-errors');
                        errorEl.textContent = '';

                        fetch(paymentMethodButton.getAttribute('data-action-url'), {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]') ? document.querySelector('meta[name="csrf-token"]').getAttribute('content') : '',
                                'Accept': 'application/json',
                            },
                        })
                            .then(function (response) { return response.json(); })
                            .then(function (data) {
                                if (data.error) {
                                    errorEl.textContent = data.error;
                                    return;
                                }

                                return stripe.confirmCardSetup(data.client_secret, {
                                    payment_method: { card: card },
                                }).then(function (result) {
                                    if (result.error) {
                                        errorEl.textContent = result.error.message;
                                        return;
                                    }

                                    var confirmForm = document.createElement('form');
                                    confirmForm.method = 'POST';
                                    confirmForm.action = paymentMethodButton.getAttribute('data-confirm-url');

                                    var csrfInput = document.createElement('input');
                                    csrfInput.type = 'hidden';
                                    csrfInput.name = '_token';
                                    csrfInput.value = document.querySelector('meta[name="csrf-token"]') ? document.querySelector('meta[name="csrf-token"]').getAttribute('content') : '';
                                    confirmForm.appendChild(csrfInput);

                                    var setupIntentInput = document.createElement('input');
                                    setupIntentInput.type = 'hidden';
                                    setupIntentInput.name = 'setup_intent';
                                    setupIntentInput.value = result.setupIntent.id;
                                    confirmForm.appendChild(setupIntentInput);

                                    document.body.appendChild(confirmForm);
                                    confirmForm.submit();
                                });
                            });
                    });
                };
                document.head.appendChild(stripeScript);
            }
        })();
    </script>
@endsection
