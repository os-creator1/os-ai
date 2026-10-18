{{--
    Google Ads Module V1 contract §10/§11 — Leads & conversions.

    TWO MEASUREMENTS, kept visibly apart:
      (a) Google conversions: Google's own count by campaign, in the Ads
          ACCOUNT currency.
      (b) Business OS outcomes: the leads this platform recorded, with what we
          truly know about where they came from, and CRM opportunity value in
          the BUSINESS currency.
    Amounts from (a) and (b) are never summed or compared. Nothing here claims
    which Google campaign or keyword produced a lead: a click ID proves a
    Google click, not the campaign. Names, tags and landing pages are
    customer-supplied: escaped.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Ads leads and conversions')

@php
    use App\Library\Crm\CrmMoney;
    use App\Library\GoogleAds\Attribution\LeadAttributionLevel;
    use App\Library\GoogleAds\GoogleAdsDisplay as D;

    $ready = $adsState === 'ready' && $period !== null;
    $adsCurrency = $account?->currency_code;
    $businessCurrency = $business->currency_code ?: null;
    $prefix = 'customer.workspaces.businesses.ads.';

    $levelVariant = static fn (LeadAttributionLevel $level): string => match ($level) {
        LeadAttributionLevel::GoogleClick => 'accent',
        default => 'neutral',
    };
    $surface = ['public_form' => 'Form', 'website_form' => 'Website form', 'booking' => 'Booking'];
    $hasValue = $ready && collect($googleCampaigns)->contains(fn ($row) => $row->totals->conversionValue() !== null);
@endphp

@section('content')
    @include('customer.business.ads._header', ['title' => 'Leads & conversions', 'subtitle' => 'Google\'s conversion count and the leads recorded in this platform are two different measurements.'])

    <x-flash-alert class="mb-2" />

    @if(! $ready)
        @include('customer.business.ads._empty-state')
    @else
        @include('customer.business.ads._period-selector', ['periodRoute' => 'leads.index'])

        <x-card :padded="true" class="mb-2" data-role="explainer">
            <h2 class="text-section-heading mb-1">Why these numbers can differ</h2>
            <ul class="mb-0 ps-2">
                <li class="mb-50"><strong>Google conversions</strong> are the actions you count inside Google Ads (calls, form submissions, purchases). Google decides what counts and when.</li>
                <li class="mb-50"><strong>Business OS leads</strong> are the contacts recorded here from your forms, website forms and bookings. A lead only shows where it came from if the visitor arrived with a tracking tag or Google click ID.</li>
                <li class="mb-50">A Google click ID proves the visitor came from a Google Ads click. It does <em>not</em> tell us which campaign or keyword, so we never guess one.</li>
                <li>Matching leads to specific campaigns needs offline conversion import, which is not available yet.</li>
            </ul>
        </x-card>

        <x-card :padded="true" class="mb-2" data-role="google-conversions">
            <h2 class="text-section-heading mb-1">Google conversions</h2>
            <p class="text-caption text-muted mb-1">Google's own conversion count by campaign, in {{ $adsCurrency ?: 'your Google Ads account currency' }}. These are not leads recorded in this platform.</p>
            @if(count($googleCampaigns) === 0)
                <p class="text-caption mb-0" data-role="no-google-conversions">No campaign data for this period yet.</p>
            @else
                <x-table :headers="array_values(array_filter(['Campaign', 'Google conversions', $hasValue ? 'Conversion value (Google)' : null, 'Spend', 'Cost per conversion']))" data-role="google-conversions-table">
                    @foreach($googleCampaigns as $row)
                        <tr data-role="google-conversion-row">
                            <td><a href="{{ route($prefix . 'campaigns.show', [$workspaceUid, $businessUid, $row->uid, 'period' => $period->key]) }}">{{ $row->name }}</a></td>
                            <td class="text-numeric">{{ D::count($row->totals->conversionsDisplay()) }}</td>
                            @if($hasValue)
                                <td class="text-numeric">{{ D::decimalMoney($row->totals->conversionValue(), $adsCurrency) }}</td>
                            @endif
                            <td class="text-numeric">{{ D::money($row->spendMicros(), $adsCurrency) }}</td>
                            <td class="text-numeric">{{ D::money($row->cplMicros(), $adsCurrency) }}</td>
                        </tr>
                    @endforeach
                </x-table>
            @endif
        </x-card>

        <x-card :padded="true" class="mb-2" data-role="business-outcomes">
            <h2 class="text-section-heading mb-1">Business OS outcomes</h2>
            <p class="text-caption text-muted mb-1">Leads recorded in this platform ({{ $period->from->format('M j') }} &ndash; {{ $period->to->format('M j, Y') }}). Opportunity values are in your business currency{{ $businessCurrency ? ' (' . $businessCurrency . ')' : '' }} and are never added to or compared with ad spend.</p>

            <div class="d-flex flex-wrap gap-1 mb-2" data-role="lead-summary">
                <x-badge variant="neutral" data-role="summary-leads">{{ number_format($summary['leads']) }} {{ $summary['leads'] === 1 ? 'lead' : 'leads' }}</x-badge>
                <x-badge variant="accent" data-role="summary-google-click">{{ number_format($summary['google_click']) }} with a Google click ID</x-badge>
                <x-badge variant="neutral" data-role="summary-campaign-tags">{{ number_format($summary['campaign_tags']) }} with campaign tags only</x-badge>
                <x-badge variant="neutral" data-role="summary-not-captured">{{ number_format($summary['not_captured']) }} source not captured</x-badge>
            </div>

            @if($leads->total() === 0)
                <p class="text-caption mb-0" data-role="no-leads">No leads were recorded in this period.</p>
            @else
                <div class="table-responsive" data-role="leads-table-wrap">
                    <table class="table ds-table align-middle" data-role="leads-table">
                        <thead>
                            <tr>
                                @foreach(['Lead', 'How they arrived', 'Source tags', 'Entry surface', 'Landing page', 'CRM stage', 'Booked?', 'Opportunity value'] as $heading)
                                    <th class="text-label text-uppercase text-muted text-nowrap">{{ $heading }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($leads as $lead)
                                @php
                                    $tags = $lead['first_touch'] ?? null;
                                    $contactName = $lead['contact']['name'] ?: ($lead['contact']['phone'] ?: 'Contact');
                                    $contactUid = $lead['contact']['uid'] ?? null;
                                    $opportunity = $lead['opportunity'];
                                @endphp
                                <tr data-role="lead-row" data-level="{{ $lead['level']->value }}">
                                    <td>
                                        @if($contactUid && \Illuminate\Support\Facades\Route::has('customer.workspaces.businesses.people.show'))
                                            <a href="{{ route('customer.workspaces.businesses.people.show', [$workspaceUid, $businessUid, $contactUid]) }}" data-role="lead-contact">{{ $contactName }}</a>
                                        @else
                                            <span data-role="lead-contact">{{ $contactName }}</span>
                                        @endif
                                        @if($lead['contact']['name'] && $lead['contact']['phone'])
                                            <span class="d-block text-caption text-muted">{{ $lead['contact']['phone'] }}</span>
                                        @endif
                                    </td>
                                    <td><x-badge :variant="$levelVariant($lead['level'])" data-role="lead-level">{{ $lead['level']->label() }}</x-badge></td>
                                    <td data-role="lead-tags">
                                        @if($tags !== null && ($tags['utm_campaign'] || $tags['utm_term']))
                                            @if($tags['utm_campaign'])<span class="d-block">campaign tag: {{ $tags['utm_campaign'] }}</span>@endif
                                            @if($tags['utm_term'])<span class="d-block">term tag: {{ $tags['utm_term'] }}</span>@endif
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>{{ $surface[$lead['entry_surface']] ?? '—' }}</td>
                                    <td class="text-break">{{ $lead['landing_page'] ?: '—' }}</td>
                                    <td>{{ $opportunity['stage'] ?? '—' }}</td>
                                    <td data-role="lead-booked">{{ $lead['booked'] ? 'Yes' : 'No' }}</td>
                                    <td class="text-numeric" data-role="lead-value">{{ $opportunity === null ? '—' : (CrmMoney::format($opportunity['value_minor'], $opportunity['currency']) ?? '—') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-1" data-role="pagination">
                    <x-pagination :paginator="$leads" />
                </div>
            @endif
        </x-card>
    @endif
@endsection
