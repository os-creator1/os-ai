@extends('layouts/contentLayoutMaster')

@section('title', __('locale.usage_billing.title'))

@section('page-style')
    @include('customer.settings._phone-messaging-assets')
@endsection

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
        // Correction Round 1 §9 — the billing contact keeps M2's generic
        // billing-management authority; financial controls use the payer-side
        // authority ($managesLimits). Never the same flag.
        $managesBillingContact = (bool) ($responsibility['actor_manages_billing_contact'] ?? $responsibility['actor_manages_limits']);
        // Correction Round 1 §2.3 — the suggested preset is a visual
        // preselection only: computed here, never written on GET, never
        // consent. A saved value that is not a preset (a legacy custom
        // amount) is never offered; the page falls back to the suggestion.
        $savedPreset = $dashboard->autoRecharge['amount_micro'] !== null ? (string) $dashboard->autoRecharge['amount_micro'] : null;
        $selectedPreset = (string) old(
            'auto_recharge_amount_micro',
            $savedPreset !== null && in_array($savedPreset, $autoRechargePresetsMicro, true) ? $savedPreset : $autoRechargeSuggestedPresetMicro,
        );
        $agencyPaidClientView = $responsibility['agency_paid'] && ! $actorIsPayer && ! $managesAgency;
        $entryLabel = static fn (string $type): string => \Illuminate\Support\Facades\Lang::has('locale.usage_billing.activity.entries.' . $type) ? __('locale.usage_billing.activity.entries.' . $type) : ucfirst(str_replace('_', ' ', $type));
        $purposeLabel = static fn (string $purpose): string => \Illuminate\Support\Facades\Lang::has('locale.usage_billing.activity.purposes.' . $purpose) ? __('locale.usage_billing.activity.purposes.' . $purpose) : ucfirst(str_replace('_', ' ', $purpose));
        $stateLabel = static fn (string $state): string => \Illuminate\Support\Facades\Lang::has('locale.usage_billing.activity.states.' . $state) ? __('locale.usage_billing.activity.states.' . $state) : ucfirst(str_replace('_', ' ', $state));
    @endphp

    <section id="usage-billing-dashboard" class="sr-page sr-page--wide pm-page">
        <a href="{{ $isAgencyFrame ? route('customer.workspaces.show', $workspaceUid) : route('customer.workspaces.businesses.settings.show', [$workspaceUid, $businessUid]) }}" class="sr-back">
            <x-ds-icon name="arrow-left" size="14" aria-hidden="true" /> {{ $isAgencyFrame ? __('locale.usage_billing.back_to_agency') : __('locale.usage_billing.back_to_settings') }}
        </a>

        <div class="sr-head">
            <div class="sr-head__main">
                <h1 class="sr-title">
                    {{ __('locale.usage_billing.title') }}
                    @if ($wallet !== null)
                        <span role="status" data-role="wallet-status" style="font-size:1rem">
                            @if ($status === 'suspended')
                                <span class="sr-chip sr-chip--danger">{{ __('locale.usage_billing.status_suspended') }}</span>
                            @elseif ($paidActivityPaused)
                                <span class="sr-chip sr-chip--warn">{{ __('locale.usage_billing.status_paused') }}</span>
                            @elseif ((int) $wallet['debt_balance_micro'] > 0)
                                <span class="sr-chip sr-chip--danger">{{ __('locale.usage_billing.status_debt') }}</span>
                            @else
                                <span class="sr-chip sr-chip--success">{{ __('locale.usage_billing.status_active') }}</span>
                            @endif
                        </span>
                    @endif
                </h1>
                <div class="sr-lede" id="usage-billing-responsibility">
                    @if ($agencyPaidClientView)
                        <strong data-role="responsibility-statement">{{ __('locale.usage_billing.responsibility.agency_manages') }}</strong>
                        {{ __('locale.usage_billing.responsibility.agency_manages_help') }}
                    @elseif ($responsibility['payer_type'] === 'agency_rebill')
                        {{-- Implementation Contract 09 — funded by the managing agency; never shown as client-paid. --}}
                        <strong data-role="responsibility-statement">{{ __('locale.usage_billing.responsibility.managing_agency_pays') }}</strong>
                        {{ __('locale.usage_billing.responsibility.managing_agency_pays_help') }}
                    @elseif (! $responsibility['is_agency'])
                        <strong data-role="responsibility-statement">{{ __('locale.usage_billing.responsibility.you_pay') }}</strong>
                        {{ __('locale.usage_billing.responsibility.you_pay_help') }}
                    @elseif ($ownsAgencyBusiness && $responsibility['payer_type'] === 'workspace')
                        <strong data-role="responsibility-statement">{{ __('locale.usage_billing.responsibility.agency_own_pays') }}</strong>
                        {{ __('locale.usage_billing.responsibility.agency_own_pays_help') }}
                    @elseif ($responsibility['payer_type'] === 'workspace')
                        <strong data-role="responsibility-statement">{{ __('locale.usage_billing.responsibility.agency_pays') }}</strong>
                        {{ __('locale.usage_billing.responsibility.agency_pays_help') }}
                        @if ($managesAgency) {{ __('locale.usage_billing.responsibility.manage_where') }} @endif
                    @else
                        <strong data-role="responsibility-statement">{{ __('locale.usage_billing.responsibility.client_pays') }}</strong>
                        {{ __('locale.usage_billing.responsibility.client_pays_help') }}
                        @if ($managesAgency) {{ __('locale.usage_billing.responsibility.manage_where') }} @endif
                    @endif
                </div>
            </div>
        </div>

        @if (session('flash_success'))
            <x-alert :dismissible="true" variant="success" icon="check-circle" class="mb-2" role="status">{{ session('flash_success') }}</x-alert>
        @endif

        @if (session('flash_info'))
            <x-alert :dismissible="true" variant="neutral" icon="info" class="mb-2" role="status">{{ session('flash_info') }}</x-alert>
        @endif

        @if (session('flash_error'))
            <x-alert :dismissible="true" variant="danger" icon="alert-circle" class="mb-2" role="alert">{{ session('flash_error') }}</x-alert>
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

        {{-- 1. Balance, this month's usage and AI usage --}}
        <div id="usage-billing-wallet">
            @if ($wallet === null)
                <div class="sr-card"><div class="sr-card__body"><p class="mb-0">{{ __('locale.usage_billing.not_set_up') }}</p></div></div>
            @else
                <div class="pm-summary" data-role="balance-summary">
                    <div class="pm-summary__cell pm-summary__cell--balance">
                        <div class="pm-summary__label" id="usage-billing-available-label">{{ __('locale.usage_billing.available_balance') }}</div>
                        <div class="pm-summary__value" data-role="available-balance" aria-labelledby="usage-billing-available-label">{{ $money($wallet['available_balance_micro']) }}</div>
                        <div class="pm-summary__note">{{ __('locale.usage_billing.available_balance_help') }}</div>
                        @if ($actorIsPayer && $dashboard->providerConfigured)
                            <a href="#usage-billing-funding" class="btn btn-primary btn-sm mt-2">{{ __('locale.usage_billing.add_funds.button') }}</a>
                        @endif
                    </div>
                    <div class="pm-summary__cell">
                        <div class="pm-summary__label" id="usage-billing-usage-label">{{ __('locale.usage_billing.usage_this_month') }}</div>
                        <div class="pm-summary__value" data-role="usage-this-month" aria-labelledby="usage-billing-usage-label">{{ $money($wallet['committed_spend_this_period_micro']) }}</div>
                        <div class="pm-summary__note">{{ __('locale.usage_billing.usage_this_month_help') }}</div>
                    </div>
                    @if ($aiUsage !== null && $aiUsage->businessRows === [])
                        <div class="pm-summary__cell" id="usage-billing-ai-usage">
                            <div class="pm-summary__label">{{ __('locale.usage_billing.ai_usage.title') }}</div>
                            <div class="pm-summary__note mt-1" style="color:var(--color-text-primary);font-size:.8125rem" role="status" data-role="ai-usage-state" data-state="{{ $aiUsage->state->value }}">{{ $aiUsage->sentence }}</div>
                            @if ($aiUsage->trialLine !== null)
                                <div class="pm-summary__note mt-1" data-role="ai-usage-trial-line">{{ $aiUsage->trialLine }}</div>
                            @endif
                        </div>
                    @endif

                    @if ($status === 'suspended' || (int) $wallet['debt_balance_micro'] > 0 || (int) $wallet['reserved_balance_micro'] > 0)
                        <div class="pm-summary__foot">
                            @if ($status === 'suspended')
                                <p>{{ __('locale.usage_billing.suspended_help') }}</p>
                            @elseif ((int) $wallet['debt_balance_micro'] > 0)
                                <p>{{ __('locale.usage_billing.amount_owed') }}: <span class="text-numeric">{{ $money($wallet['debt_balance_micro']) }}</span>. {{ __('locale.usage_billing.amount_owed_help') }}</p>
                            @endif
                            @if ((int) $wallet['reserved_balance_micro'] > 0)
                                <p>{{ __('locale.usage_billing.held_for_work_in_progress') }}: <span class="text-numeric">{{ $money($wallet['reserved_balance_micro']) }}</span>. {{ __('locale.usage_billing.held_help') }}</p>
                            @endif
                        </div>
                    @endif
                </div>
            @endif
        </div>

        {{-- Payment-method warning: funds can still be used without a card, but top-ups need one. --}}
        @if ($wallet !== null && $actorIsPayer && $dashboard->providerConfigured && $dashboard->paymentMethod === null)
            <div class="sr-callout sr-callout--warn mb-3" role="status" data-role="payment-method-warning">
                <x-ds-icon name="credit-card" size="16" />
                <p><strong>Add a payment method</strong> to top up or use automatic top-up. {{ __('locale.usage_billing.add_funds.needs_payment_method') }}</p>
            </div>
        @endif

            {{-- 2b. AI usage — Unified Business Home & COO contract §11.3 (slice AI-2).
                 A state and its sentence only: no tokens, amounts, percentage,
                 provider or model. $aiUsage is null for an actor outside the
                 billing authority, and for an account with no included AI. --}}
            @if ($aiUsage !== null && ($aiUsage->businessRows !== [] || $wallet === null))
                <div class="mb-3">
                    <x-card id="usage-billing-ai-usage" class="mb-0" :title="__('locale.usage_billing.ai_usage.title')">
                        <p class="mb-0" role="status" data-role="ai-usage-state" data-state="{{ $aiUsage->state->value }}">{{ $aiUsage->sentence }}</p>
                        @if ($aiUsage->trialLine !== null)
                            <p class="text-caption mt-50 mb-0" data-role="ai-usage-trial-line">{{ $aiUsage->trialLine }}</p>
                        @endif

                        @if ($aiUsage->businessRows !== [])
                            <h3 class="text-section-heading h5 mt-2 mb-1" id="usage-billing-ai-usage-businesses">{{ __('locale.usage_billing.ai_usage.businesses_title') }}</h3>
                            <ul class="list-unstyled mb-0" aria-labelledby="usage-billing-ai-usage-businesses" data-role="ai-usage-business-rows">
                                @foreach ($aiUsage->businessRows as $row)
                                    <li class="d-flex flex-wrap align-items-center justify-content-between gap-1 py-50 border-bottom" data-role="ai-usage-business-row" data-business-uid="{{ $row->businessUid }}" data-state="{{ $row->state->value }}">
                                        <span class="text-break">{{ $row->businessName }}</span>
                                        <x-badge :variant="$row->state === \App\Library\Ai\Enums\AiUsageState::Normal ? 'success' : 'warning'">{{ $row->label }}</x-badge>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </x-card>
                </div>
            @endif

        @php($hasSide = ($wallet !== null && $managesLimits) || $managesBillingContact)
        <div class="pm-split @unless ($hasSide) pm-split--single @endunless" style="@unless ($hasSide) grid-template-columns:minmax(0,1fr) @endunless">
            <div class="pm-stack">
                {{-- 3. Funding — only the payer ever sees add-funds / automatic top-up (T-PAYER-4) --}}
                @if ($actorIsPayer)
                    <x-card id="usage-billing-funding" :title="__('locale.usage_billing.add_funds.title')" class="mb-0">
                        @if (! $dashboard->providerConfigured)
                            <div class="sr-callout sr-callout--warn mb-0" data-role="funding-not-available"><x-ds-icon name="clock" size="16" /><p><strong>Not available yet.</strong> {{ __('locale.usage_billing.add_funds.not_configured') }}</p></div>
                        @elseif ($wallet === null)
                            <p class="mb-0">{{ __('locale.usage_billing.not_set_up') }}</p>
                        @else
                            @include('customer.business.usage-billing.partials.payment-method', ['dashboard' => $dashboard, 'workspaceUid' => $workspaceUid, 'businessUid' => $businessUid])

                            <hr>

                            <p class="mb-25" id="usage-billing-add-funds-help">{{ __('locale.usage_billing.add_funds.help') }}</p>
                            <p class="text-caption mb-25">{{ __('locale.usage_billing.add_funds.who_pays', ['payer' => $responsibility['who_pays'] === 'agency' ? __('locale.usage_billing.responsibility.agency_short') : __('locale.usage_billing.responsibility.business_short')]) }}</p>
                            <p class="text-caption mb-1" id="usage-billing-add-funds-minimum">{{ __('locale.usage_billing.add_funds.minimum', ['amount' => $money($minimumTopUpMicro)]) }}</p>
                            @if ((int) $wallet['available_balance_micro'] > 0)
                                <p class="text-caption mb-1" data-role="existing-balance-note">{{ __('locale.usage_billing.add_funds.existing_balance', ['amount' => $money($wallet['available_balance_micro'])]) }}</p>
                            @endif

                            @if ($dashboard->paymentMethod === null)
                                <p class="text-caption mb-1" data-role="add-funds-needs-method">{{ __('locale.usage_billing.add_funds.needs_payment_method') }}</p>
                            @endif

                            <form method="POST" action="{{ route('customer.workspaces.businesses.usage-billing.top-up.initiate', [$workspaceUid, $businessUid]) }}" id="usage-billing-top-up-form" novalidate>
                                @csrf
                                @if (! empty($autoRechargePresetsMicro))
                                    <div class="pm-presets mb-2" role="group" aria-label="Quick amounts" data-role="top-up-presets">
                                        @foreach ($autoRechargePresetsMicro as $preset)
                                            <button type="button" class="btn btn-sm btn-outline-secondary" data-fill-amount="{{ $decimal((string) $preset) }}">{{ $money((string) $preset) }}</button>
                                        @endforeach
                                    </div>
                                @endif
                                <div class="pm-inline">
                                    <div class="pm-field">
                                        <label for="usage-billing-top-up-amount">{{ __('locale.usage_billing.add_funds.amount_label') }} ({{ $currency }})</label>
                                        <input type="text" inputmode="decimal" class="form-control transition-fast" id="usage-billing-top-up-amount" name="amount" value="{{ old('amount', $decimal($minimumTopUpMicro)) }}" aria-describedby="usage-billing-add-funds-help usage-billing-add-funds-minimum" required>
                                    </div>
                                    <x-button type="submit" variant="primary">{{ __('locale.usage_billing.add_funds.button') }}</x-button>
                                </div>
                            </form>
                        @endif
                    </x-card>
                    <x-card id="usage-billing-auto-top-up" :title="__('locale.usage_billing.auto_top_up.title')" class="mb-0">
                        @if (! $dashboard->providerConfigured)
                            <div class="sr-callout sr-callout--warn mb-0" data-role="funding-not-available"><x-ds-icon name="clock" size="16" /><p><strong>Not available yet.</strong> {{ __('locale.usage_billing.add_funds.not_configured') }}</p></div>
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
                                <div class="pm-toggle-row mb-2">
                                    <div class="pm-toggle-row__text"><strong>{{ __('locale.usage_billing.auto_top_up.enable_label') }}</strong></div>
                                    <label class="sr-switch" for="usage-billing-auto-recharge-enabled">
                                        <input type="checkbox" role="switch" name="auto_recharge_enabled" id="usage-billing-auto-recharge-enabled" value="1" @checked(old('auto_recharge_enabled', $dashboard->autoRecharge['enabled'])) aria-describedby="usage-billing-auto-top-up-help">
                                        <span class="visually-hidden">{{ __('locale.usage_billing.auto_top_up.enable_label') }}</span>
                                    </label>
                                </div>

                                <fieldset class="mb-1">
                                    <legend class="form-label text-label fs-6">{{ __('locale.usage_billing.auto_top_up.amount_label') }}</legend>
                                    <p class="text-caption mb-50" id="usage-billing-auto-recharge-amount-help">{{ __('locale.usage_billing.auto_top_up.amount_help') }}</p>
                                    @if (! $dashboard->autoRecharge['enabled'])
                                        <p class="text-caption mb-50" data-role="auto-top-up-suggestion">{{ __('locale.usage_billing.auto_top_up.suggested', ['amount' => $money($autoRechargeSuggestedPresetMicro)]) }}</p>
                                    @endif
                                    <div class="pm-presets" data-role="auto-recharge-presets">
                                        @foreach ($autoRechargePresetsMicro as $preset)
                                            <label class="pm-preset" for="usage-billing-auto-recharge-amount-{{ $preset }}">
                                                <input type="radio" name="auto_recharge_amount_micro" id="usage-billing-auto-recharge-amount-{{ $preset }}" value="{{ $preset }}" @checked($selectedPreset === (string) $preset) aria-describedby="usage-billing-auto-recharge-amount-help">
                                                <span>{{ $money($preset) }}</span>
                                            </label>
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
                                        <p class="text-caption mb-0" id="usage-billing-auto-recharge-cap-help">{{ __('locale.usage_billing.auto_top_up.monthly_limit_help', ['maximum' => $money($businessMonthlyAutoRechargeMaximumMicro)]) }}</p>
                                    </div>
                                </div>

                                <p class="text-caption mt-1 mb-50" data-role="auto-top-up-frequency-help">{{ __('locale.usage_billing.auto_top_up.frequency_help') }}</p>
                                <p class="text-caption mb-1">{{ __('locale.usage_billing.auto_top_up.charge_note') }}</p>
                                <x-button type="submit" variant="secondary">{{ __('locale.usage_billing.auto_top_up.button') }}</x-button>
                            </form>
                        @endif
                    </x-card>
                @endif

                <div class="pm-panel" id="usage-billing-history">
                    <div class="pm-panel__head">
                        <h3 class="pm-panel__title">Activity</h3>
                        <ul class="nav nav-pills" role="tablist" style="gap:4px">
                            <li class="nav-item" role="presentation"><button class="nav-link active py-1 px-2" id="usage-billing-tab-usage" data-bs-toggle="tab" data-bs-target="#usage-billing-pane-usage" type="button" role="tab" aria-controls="usage-billing-pane-usage" aria-selected="true" style="font-size:.8125rem">Usage</button></li>
                            <li class="nav-item" role="presentation"><button class="nav-link py-1 px-2" id="usage-billing-tab-funding" data-bs-toggle="tab" data-bs-target="#usage-billing-pane-funding" type="button" role="tab" aria-controls="usage-billing-pane-funding" aria-selected="false" style="font-size:.8125rem">Funding</button></li>
                        </ul>
                    </div>
                    <div class="tab-content">
                        <div class="tab-pane active" id="usage-billing-pane-usage" role="tabpanel" aria-labelledby="usage-billing-tab-usage">
                            <div id="usage-billing-ledger">
<h4 class="sr-card__sub" style="padding:var(--space-4) var(--space-5) 0;margin:0">{{ __('locale.usage_billing.activity.title') }}</h4>
                                @if ($dashboard->ledger->isEmpty())
                                    <div class="sr-empty" style="background:transparent">
                                        <span class="sr-tile"><x-ds-icon name="receipt" size="18" /></span>
                                        <strong>{{ __('locale.usage_billing.activity.empty') }}</strong>
                                        <p>{{ __('locale.usage_billing.activity.title') }}</p>
                                    </div>
                                @else
                                    <div>
                                        <div class="pm-tablewrap">
                                            <table class="pm-table" data-role="ledger-table">
                                                <thead><tr>
                                                    @foreach (__('locale.usage_billing.activity.headers') as $i => $header)
                                                        <th scope="col" @if ($i === 3) class="is-num" style="text-align:right" @endif>{{ $header }}</th>
                                                    @endforeach
                                                </tr></thead>
                                                <tbody>
                                                @foreach ($dashboard->ledger as $entry)
                                                    @php
                                                        // Presentation only: each ledger entry type is named for what it is.
                                                        // A hold and its release move funds aside and back; neither is a payment or a refund.
                                                        [$kindLabel, $kindVariant, $kindNote, $amountClass] = match ($entry->entryType) {
                                                            'paid_top_up', 'auto_recharge' => ['Wallet deposit', 'success', null, 'pm-amt--in'],
                                                            'promotional_credit' => ['Promotional credit', 'accent', null, 'pm-amt--in'],
                                                            'manual_credit' => ['Manual credit', 'accent', null, 'pm-amt--in'],
                                                            'reservation' => ['Hold', '', 'Set aside while work is in progress — not a charge.', 'pm-amt--hold'],
                                                            'reservation_release' => ['Hold released', '', 'Returned to your available balance — not a refund.', 'pm-amt--hold'],
                                                            'usage_charge', 'usage_overage_charge' => ['Charged', '', null, 'pm-amt--out'],
                                                            'refund' => ['Refund', 'success', null, 'pm-amt--in'],
                                                            'dispute_chargeback' => ['Disputed', 'danger', null, 'pm-amt--out'],
                                                            'usage_charge_reversal', 'correction_reversal' => ['Correction', 'warn', null, 'pm-amt--out'],
                                                            default => [ucfirst(str_replace('_', ' ', $entry->entryType)), '', null, 'pm-amt--out'],
                                                        };
                                                    @endphp
                                                    <tr data-entry-type="{{ $entry->entryType }}">
                                                        <td class="is-date">{{ $entry->createdAt->format('Y-m-d H:i') }}</td>
                                                        <td>
                                                            {{ $entryLabel($entry->entryType) }}
                                                            <span class="sr-chip {{ $kindVariant !== '' ? 'sr-chip--' . $kindVariant : '' }} ms-1">{{ $kindLabel }}</span>
                                                            @if ($kindNote !== null)<span class="pm-table__sub">{{ $kindNote }}</span>@endif
                                                        </td>
                                                        <td>{{ $capabilityLabel($entry->featureKey) }}</td>
                                                        <td class="is-num {{ $amountClass }}">{{ $money($entry->signedAmountMicro) }}</td>
                                                    </tr>
                                                @endforeach
                                                </tbody>
                                            </table>
                                        </div>

                                        <p class="pm-hint px-3 mt-2 mb-0" data-role="ledger-note">Holds set funds aside while paid work is in progress. Releasing a hold returns that amount to your available balance; it is not a refund or a new payment.</p>
                                        <div class="mt-2 px-3 pb-2">
                                            <x-pagination :paginator="$dashboard->ledger" />
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="tab-pane" id="usage-billing-pane-funding" role="tabpanel" aria-labelledby="usage-billing-tab-funding">
                            <div id="usage-billing-funding-history">
<h4 class="sr-card__sub" style="padding:var(--space-4) var(--space-5) 0;margin:0">{{ __('locale.usage_billing.activity.funding_title') }}</h4>
                                @if ($dashboard->fundingHistory->isEmpty())
                                    <div class="sr-empty" style="background:transparent">
                                        <span class="sr-tile"><x-ds-icon name="wallet" size="18" /></span>
                                        <strong>{{ __('locale.usage_billing.activity.funding_empty') }}</strong>
                                        <p>{{ __('locale.usage_billing.activity.funding_title') }}</p>
                                    </div>
                                @else
                                    <div>
                                        <div class="pm-tablewrap">
                                            <table class="pm-table" data-role="funding-table">
                                                <thead><tr>
                                                    @foreach (__('locale.usage_billing.activity.funding_headers') as $i => $header)
                                                        <th scope="col" @if ($i === 3) style="text-align:right" @endif>{{ $header }}</th>
                                                    @endforeach
                                                </tr></thead>
                                                <tbody>
                                                @foreach ($dashboard->fundingHistory as $attempt)
                                                <tr data-role="funding-attempt" data-result="{{ $attempt['state'] }}">
                                                    <td class="is-date">{{ $attempt['created_at']->format('Y-m-d H:i') }}</td>
                                                    <td>{{ $purposeLabel($attempt['purpose']) }}</td>
                                                    <td>{{ $attempt['payment_method_display'] }}</td>
                                                    <td class="is-num">{{ $money($attempt['amount_micro']) }}</td>
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
                                                </tbody>
                                            </table>
                                        </div>

                                        <div class="mt-2 px-3 pb-2">
                                            <x-pagination :paginator="$dashboard->fundingHistory" />
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            @if ($hasSide)
                <div class="pm-stack" data-role="billing-side">
                    {{-- 4. Spending controls --}}
                    @if ($wallet !== null && $managesLimits)
                        <x-card id="usage-billing-spending" :title="__('locale.usage_billing.spending.title')" class="mb-0">
                            <form method="POST" action="{{ route('customer.workspaces.businesses.usage-billing.spend-cap', [$workspaceUid, $businessUid]) }}" novalidate>
                                @csrf
                                <input type="hidden" name="control" value="business_spend_cap">
                                <p class="text-caption mb-2" id="usage-billing-spend-cap-help">{{ __('locale.usage_billing.spending.monthly_limit_help') }}</p>
                                <p class="mb-2" data-role="spend-cap-state" style="font-size:.8125rem">
                                    @if ($wallet['monthly_spend_cap_micro'] === null)
                                        <span class="sr-chip">{{ __('locale.usage_billing.spending.no_limit') }}</span>
                                    @else
                                        <span class="text-numeric">{{ $money($wallet['monthly_spend_cap_micro']) }}</span> &mdash; {{ __('locale.usage_billing.spending.remaining', ['amount' => $money($wallet['remaining_headroom_micro'])]) }}
                                    @endif
                                </p>
                                <label class="sr-label" for="usage-billing-spend-cap">{{ __('locale.usage_billing.spending.monthly_limit_label') }} ({{ $currency }})</label>
                                <input type="text" inputmode="decimal" class="form-control transition-fast mb-3" id="usage-billing-spend-cap" name="monthly_spend_cap" value="{{ old('monthly_spend_cap', $decimal($wallet['monthly_spend_cap_micro'])) }}" aria-describedby="usage-billing-spend-cap-help">
                                <x-button type="submit" variant="secondary" size="sm">{{ __('locale.usage_billing.spending.button') }}</x-button>
                            </form>
                        </x-card>
                        <x-card id="usage-billing-feature-limits" :title="__('locale.usage_billing.capabilities.title')" class="mb-0">
                            <p class="text-caption mb-2" id="usage-billing-capability-help">{{ __('locale.usage_billing.capabilities.help') }}</p>

                            @if (empty($dashboard->featureLimits))
                                <p class="text-caption">{{ __('locale.usage_billing.capabilities.none') }}</p>
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
                                <form method="POST" id="usage-billing-feature-limit-form" data-base-action="{{ route('customer.workspaces.businesses.usage-billing.show', [$workspaceUid, $businessUid]) }}/feature-limits/" novalidate>
                                    @csrf
                                    <label class="sr-label" for="usage-billing-capability">{{ __('locale.usage_billing.capabilities.select_label') }}</label>
                                    <select class="form-select form-control transition-fast mb-2" id="usage-billing-capability" name="capability" aria-describedby="usage-billing-capability-help" required>
                                        @foreach ($capabilities as $key => $capability)
                                            <option value="{{ $key }}">{{ $capability['label'] }}@if($capability['help']) — {{ $capability['help'] }}@endif</option>
                                        @endforeach
                                    </select>
                                    <label class="sr-label" for="usage-billing-feature-limit">{{ __('locale.usage_billing.capabilities.limit_label') }} ({{ $currency }})</label>
                                    <input type="text" inputmode="decimal" class="form-control transition-fast" id="usage-billing-feature-limit" name="monthly_limit" aria-describedby="usage-billing-feature-limit-help">
                                    <p class="text-caption mt-1 mb-3" id="usage-billing-feature-limit-help">{{ __('locale.usage_billing.capabilities.limit_help') }}</p>
                                    <x-button type="submit" variant="secondary" size="sm">{{ __('locale.usage_billing.capabilities.button') }}</x-button>
                                </form>
                            @endif
                        </x-card>
                    <x-card id="usage-billing-pause" class="mb-0">
                        <div class="d-flex align-items-start gap-3 mb-3">
                            <span class="sr-tile" style="background:#fbeae7;color:#a03626"><x-ds-icon name="pause" size="18" /></span>
                            <div>
                                <h3 class="sr-card__title" id="usage-billing-pause-title">{{ __('locale.usage_billing.spending.pause_title') }}</h3>
                                <p class="sr-card__sub mb-0" id="usage-billing-pause-help">{{ __('locale.usage_billing.spending.pause_help') }}</p>
                            </div>
                        </div>
                        <form method="POST" action="{{ route('customer.workspaces.businesses.usage-billing.spend-cap', [$workspaceUid, $businessUid]) }}" novalidate aria-labelledby="usage-billing-pause-title" aria-describedby="usage-billing-pause-help" data-role="pause-form">
                            @csrf
                            <input type="hidden" name="control" value="business_pause">
                            @if ($paidActivityPaused)
                                <input type="hidden" name="paused" value="0">
                                <div class="sr-confirm">
                                    <p class="mb-0 fw-bolder" role="status" data-role="pause-state">{{ __('locale.usage_billing.status_paused') }}</p>
                                    <x-button type="submit" variant="primary">{{ __('locale.usage_billing.spending.resume_button') }}</x-button>
                                </div>
                            @else
                                <input type="hidden" name="paused" value="1">
                                <div class="sr-confirm sr-confirm--boxed">
                                    <div class="form-check mb-0">
                                        <input class="form-check-input" type="checkbox" name="confirm_pause" id="usage-billing-pause-confirm" value="1">
                                        <label class="form-check-label" for="usage-billing-pause-confirm">{{ __('locale.usage_billing.spending.pause_confirm') }}</label>
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-sr-danger-outline">{{ __('locale.usage_billing.spending.pause_button') }}</button>
                                </div>
                            @endif
                        </form>
                    </x-card>
                        @if ($managesAgency && $workspaceControls !== null)
                        <x-card id="usage-billing-agency" class="mb-0" :title="__('locale.usage_billing.spending.agency_title')">
                            <h3 class="visually-hidden" id="usage-billing-agency-title">{{ __('locale.usage_billing.spending.agency_title') }}</h3>
                            <p class="text-caption mb-2" id="usage-billing-agency-help">{{ __('locale.usage_billing.spending.agency_help') }}</p>
                            <p class="text-caption mb-2" data-role="agency-spent">{{ __('locale.usage_billing.spending.agency_spent', ['amount' => $money($workspaceControls['workspace_paid_spend_this_period_micro'])]) }}</p>
                            @if ($workspaceControls['paid_activity_paused'])
                                <x-alert variant="warning" icon="alert-circle" class="mb-2" role="status" data-role="agency-paused">{{ __('locale.usage_billing.spending.agency_paused_notice') }}</x-alert>
                            @endif
                            <form method="POST" action="{{ route('customer.workspaces.businesses.usage-billing.spend-cap', [$workspaceUid, $businessUid]) }}" novalidate aria-labelledby="usage-billing-agency-title" data-role="agency-controls-form">
                                @csrf
                                <input type="hidden" name="control" value="workspace_controls">
                                <div class="sr-grid-2" style="gap:var(--space-4)">
                                    <div class="sr-field">
                                        <label for="usage-billing-agency-spend-cap">{{ __('locale.usage_billing.spending.agency_monthly_limit_label') }} ({{ $currency }})</label>
                                        <input type="text" inputmode="decimal" class="form-control transition-fast" id="usage-billing-agency-spend-cap" name="workspace_monthly_spend_cap" value="{{ old('workspace_monthly_spend_cap', $decimal($workspaceControls['monthly_aggregate_spend_cap_micro'])) }}" aria-describedby="usage-billing-agency-spend-cap-help">
                                        <p class="sr-hint" id="usage-billing-agency-spend-cap-help">{{ __('locale.usage_billing.spending.agency_monthly_limit_help') }}</p>
                                    </div>
                                    <div class="sr-field">
                                        <label for="usage-billing-agency-recharge-cap">{{ __('locale.usage_billing.spending.agency_recharge_limit_label') }} ({{ $currency }})</label>
                                        <input type="text" inputmode="decimal" class="form-control transition-fast" id="usage-billing-agency-recharge-cap" name="workspace_monthly_recharge_cap" value="{{ old('workspace_monthly_recharge_cap', $decimal($workspaceControls['monthly_aggregate_recharge_cap_micro'])) }}" aria-describedby="usage-billing-agency-recharge-cap-help">
                                        <p class="sr-hint" id="usage-billing-agency-recharge-cap-help">{{ __('locale.usage_billing.spending.agency_recharge_limit_help', ['maximum' => $money($workspaceMonthlyAutoRechargeMaximumMicro)]) }}</p>
                                    </div>
                                </div>
                                <input type="hidden" name="workspace_paused" value="0">
                                <div class="form-check my-3">
                                    <input class="form-check-input" type="checkbox" name="workspace_paused" id="usage-billing-agency-paused" value="1" @checked($workspaceControls['paid_activity_paused'])>
                                    <label class="form-check-label" for="usage-billing-agency-paused">{{ __('locale.usage_billing.spending.agency_pause_label') }}</label>
                                </div>
                                <x-button type="submit" variant="secondary" size="sm">{{ __('locale.usage_billing.spending.agency_button') }}</x-button>
                            </form>
                        </x-card>
                        @endif
                    @endif
                    {{-- 6. Billing contact --}}
                    @if ($managesBillingContact)
                    <x-card id="usage-billing-contact" class="mb-0">
                        <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                            <div>
                                <h3 class="sr-card__title">Billing contact</h3>
                                <p class="sr-card__sub mb-0">Who gets receipts and billing notices for this business.</p>
                            </div>
                            @if ($dashboard->billingContact === null)
                                <span class="sr-chip">Not set</span>
                            @endif
                        </div>

                        @if ($dashboard->billingContact !== null)
                            <dl class="sr-dl mb-3">
                                <div style="display:block"><dt class="sr-eyebrow mb-0">Name</dt><dd class="mb-1" style="color:var(--color-text-primary)">{{ $dashboard->billingContact['name'] }}</dd></div>
                                <div style="display:block"><dt class="sr-eyebrow mb-0">Email</dt><dd class="mb-1" style="color:var(--color-text-primary)">{{ $dashboard->billingContact['email'] }}</dd></div>
                                <div style="display:block"><dt class="sr-eyebrow mb-0">Notifications</dt><dd class="mb-0" style="color:var(--color-text-primary)">{{ $dashboard->billingContact['notification_opt_in'] ? 'Enabled' : 'Disabled' }}</dd></div>
                            </dl>
                        @endif

                        <form method="POST" action="{{ route('customer.workspaces.businesses.usage-billing.billing-contact', [$workspaceUid, $businessUid]) }}" novalidate>
                            @csrf
                            <div class="sr-grid-2" style="gap:var(--space-4)">
                                <div class="sr-field">
                                    <label for="usage-billing-contact-name">Contact name</label>
                                    <input type="text" class="form-control transition-fast" id="usage-billing-contact-name" name="contact_name" value="{{ old('contact_name', $dashboard->billingContact['name'] ?? '') }}">
                                </div>
                                <div class="sr-field">
                                    <label for="usage-billing-contact-email">Contact email</label>
                                    <input type="email" class="form-control transition-fast" id="usage-billing-contact-email" name="contact_email" value="{{ old('contact_email', $dashboard->billingContact['email'] ?? '') }}">
                                </div>
                            </div>

                            <div class="sr-confirm mt-3">
                                <div class="form-check mb-0">
                                    <input type="hidden" name="notification_opt_in" value="0">
                                    <input class="form-check-input" type="checkbox" name="notification_opt_in" id="usage-billing-contact-notify" value="1" @checked(old('notification_opt_in', $dashboard->billingContact['notification_opt_in'] ?? true))>
                                    <label class="form-check-label" for="usage-billing-contact-notify">Send billing notifications to this contact</label>
                                </div>
                                <x-button type="submit" variant="primary">Update billing contact</x-button>
                            </div>
                        </form>
                    </x-card>
                    @endif
                </div>
            @endif
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

            // Quick amounts only fill the amount box; nothing is submitted by choosing one.
            document.querySelectorAll('[data-fill-amount]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var input = document.getElementById('usage-billing-top-up-amount');
                    if (input) { input.value = button.getAttribute('data-fill-amount'); input.focus(); }
                });
            });

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
