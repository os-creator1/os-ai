{{--
    Google Ads Module V1 — Budget (google_ads_module only).

    Two different things are shown and kept apart: the Business's MONTHLY
    TARGET (our planning value, set in Settings) and each campaign's DAILY
    BUDGET (what Google Ads actually spends from). Pacing uses calm wording
    and neutral/amber/green only — being ahead or behind pace is information,
    not an alarm. Absent data is an em dash, never 0.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Ads budget')

@php
    use App\Library\GoogleAds\GoogleAdsMoney;
    use App\Library\GoogleAds\Reporting\GoogleAdsCplStatus;
    use App\Library\GoogleAds\Reporting\GoogleAdsPacingStatus;

    $ready = $adsState === 'ready' && $budgetOverview !== null;
    $currency = $account?->currency_code;
    $money = static fn (?int $micros): string => GoogleAdsMoney::format($micros, $currency);

    $pacingLabel = static fn (GoogleAdsPacingStatus $status): string => match ($status) {
        GoogleAdsPacingStatus::OnPace => 'On pace',
        GoogleAdsPacingStatus::Ahead => 'Ahead of pace',
        GoogleAdsPacingStatus::Behind => 'Behind pace',
        GoogleAdsPacingStatus::NoTarget => 'No target set',
        GoogleAdsPacingStatus::InsufficientData => 'Not enough data yet',
    };
    $pacingVariant = static fn (GoogleAdsPacingStatus $status): string => match ($status) {
        GoogleAdsPacingStatus::OnPace => 'success',
        GoogleAdsPacingStatus::Ahead => 'warning',
        default => 'neutral',
    };
    $pacingExplanation = static fn (GoogleAdsPacingStatus $status): string => match ($status) {
        GoogleAdsPacingStatus::OnPace => 'Spending is in line with your monthly target for this point in the month.',
        GoogleAdsPacingStatus::Ahead => 'You have spent more than your target would suggest by now. At this rate you would finish the month above your target.',
        GoogleAdsPacingStatus::Behind => 'You have spent less than your target would suggest by now. At this rate you would finish the month below your target.',
        GoogleAdsPacingStatus::NoTarget => 'Set a monthly budget target in Settings to see how your spending compares.',
        GoogleAdsPacingStatus::InsufficientData => 'We need a few more days of spending data before we can say whether you are on pace.',
    };
    $cplLabel = static fn (?GoogleAdsCplStatus $status): string => match ($status) {
        GoogleAdsCplStatus::Better => 'Better than your target',
        GoogleAdsCplStatus::OnTarget => 'On target',
        GoogleAdsCplStatus::Worse => 'Above your target',
        GoogleAdsCplStatus::NoTarget => 'No target set',
        default => 'Not enough data yet',
    };
    $cplVariant = static fn (?GoogleAdsCplStatus $status): string => match ($status) {
        GoogleAdsCplStatus::Better, GoogleAdsCplStatus::OnTarget => 'success',
        GoogleAdsCplStatus::Worse => 'warning',
        default => 'neutral',
    };
@endphp

@section('content')
    @include('customer.business.ads._header', ['title' => 'Budget', 'subtitle' => 'How your spending compares with the monthly target you set.'])

    <x-flash-alert class="mb-2" />

    @if(! $ready)
        @include('customer.business.ads._empty-state')
    @else
        @php
            $pacing = $budgetOverview->pacing;
            $overPercent = $pacing->spendProportion === null ? null : min(100, (int) round($pacing->spendProportion * 100));
            $elapsedPercent = $pacing->elapsedProportion === null ? null : (int) round($pacing->elapsedProportion * 100);
        @endphp

        <x-card :padded="true" class="mb-2" data-section="pacing">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-1 mb-1">
                <h2 class="text-section-heading mb-0">{{ $budgetOverview->monthPeriod->from->format('F Y') }}</h2>
                <x-badge :variant="$pacingVariant($pacing->status)" data-role="pacing-status" data-status="{{ $pacing->status->value }}">{{ $pacingLabel($pacing->status) }}</x-badge>
            </div>
            <p class="text-caption mb-2" data-role="pacing-explanation">{{ $pacingExplanation($pacing->status) }}</p>

            <div class="row">
                <div class="col-6 col-lg-3 mb-2">
                    <p class="text-label mb-0">Monthly target</p>
                    <p class="h3 mb-0" data-role="budget-target">{{ $money($pacing->monthlyTargetMicros) }}</p>
                </div>
                <div class="col-6 col-lg-3 mb-2">
                    <p class="text-label mb-0">Spent so far</p>
                    <p class="h3 mb-0" data-role="budget-spent">{{ $money($pacing->spentMicros) }}</p>
                </div>
                <div class="col-6 col-lg-3 mb-2">
                    <p class="text-label mb-0">Projected month-end</p>
                    <p class="h3 mb-0" data-role="budget-projected">{{ $money($pacing->projectedMicros) }}</p>
                    @if($pacing->projectedMicros !== null && $pacing->projectionLowConfidence)
                        <p class="text-caption text-muted mb-0" data-role="budget-projected-note">Early estimate, based on only a few days of data.</p>
                    @endif
                </div>
                <div class="col-6 col-lg-3 mb-2">
                    <p class="text-label mb-0">Month elapsed</p>
                    <p class="h3 mb-0">{{ $elapsedPercent === null ? '—' : $elapsedPercent . '%' }}</p>
                </div>
            </div>

            @if($overPercent !== null)
                <div class="progress mt-1" style="height: 8px;" role="progressbar" aria-label="Share of the monthly target spent" aria-valuenow="{{ $overPercent }}" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar" style="width: {{ $overPercent }}%;"></div>
                </div>
                <p class="text-caption text-muted mt-50 mb-0" data-role="budget-progress-note">
                    {{ (int) round($pacing->spendProportion * 100) }}% of your target spent{{ $elapsedPercent !== null ? ', with ' . $elapsedPercent . '% of the month elapsed' : '' }}.
                </p>
            @endif

            <p class="mt-2 mb-0">
                <a href="{{ route('customer.workspaces.businesses.ads.settings', [$workspaceUid, $businessUid]) }}" data-role="edit-targets">
                    {{ $pacing->monthlyTargetMicros === null ? 'Set a monthly target' : 'Edit your targets' }}
                </a>
            </p>
        </x-card>

        <x-card :padded="true" class="mb-2" data-section="cpl">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-1 mb-1">
                <h2 class="text-section-heading mb-0">Cost per conversion</h2>
                <x-badge :variant="$cplVariant($budgetOverview->cplStatus)" data-role="cpl-status">{{ $cplLabel($budgetOverview->cplStatus) }}</x-badge>
            </div>
            <div class="row">
                <div class="col-6 mb-1">
                    <p class="text-label mb-0">Target</p>
                    <p class="h3 mb-0" data-role="budget-target-cpl">{{ $money($budgetOverview->targetCplMicros) }}</p>
                </div>
                <div class="col-6 mb-1">
                    <p class="text-label mb-0">Current ({{ $budgetOverview->cplPeriod->days() }} days)</p>
                    <p class="h3 mb-0" data-role="budget-current-cpl">{{ $money($budgetOverview->currentCplMicros) }}</p>
                </div>
            </div>
            <p class="text-caption text-muted mb-0">Cost per Google conversion: spend divided by the conversions counted in your Google Ads account. A dash means there are no conversions to divide by yet.</p>
        </x-card>

        <x-card :padded="true" class="mb-2" data-section="how-it-works">
            <h2 class="text-section-heading mb-1">Two different budgets</h2>
            <p class="mb-1">
                <strong>Daily budgets</strong> live in Google Ads. Each campaign has its own, and Google decides how to spend it day to day.
                We show them below but never change them.
            </p>
            <p class="mb-0">
                <strong>Your monthly target</strong> is a planning figure you set here, in Settings. It is not sent to Google. We compare your real spending with it so you can see whether you are on pace.
            </p>
        </x-card>

        <x-card :padded="true" data-section="campaign-budgets">
            <h2 class="text-section-heading mb-1">Campaign daily budgets</h2>
            @if(count($budgetOverview->campaigns) === 0)
                <p class="text-caption mb-0" data-role="no-campaign-budgets">No campaigns to show yet. They will appear after your next update.</p>
            @else
                <x-table :headers="['Campaign', 'Status', 'Daily budget', 'Shared budget', 'Spent this month']" data-role="campaign-budgets-table">
                    @foreach($budgetOverview->campaigns as $campaign)
                        <tr data-role="campaign-budget-row">
                            <td>{{ $campaign->name }}</td>
                            <td>
                                @if($campaign->status->value === 'ENABLED')
                                    <x-badge variant="success">Enabled</x-badge>
                                @elseif($campaign->status->value === 'PAUSED')
                                    <x-badge variant="neutral">Paused</x-badge>
                                @else
                                    <x-badge variant="neutral">{{ ucfirst(strtolower($campaign->status->value)) }}</x-badge>
                                @endif
                            </td>
                            <td class="text-numeric">{{ $money($campaign->dailyBudgetMicros) }}</td>
                            <td>
                                @if($campaign->budgetShared === null)
                                    —
                                @elseif($campaign->budgetShared)
                                    <x-badge variant="accent">Shared</x-badge>
                                @else
                                    No
                                @endif
                            </td>
                            <td class="text-numeric">{{ $money($campaign->spendMicros()) }}</td>
                        </tr>
                    @endforeach
                </x-table>
                <p class="text-caption text-muted mb-0 mt-1">A shared budget is one daily amount that several campaigns draw from.</p>
            @endif
        </x-card>
    @endif
@endsection
