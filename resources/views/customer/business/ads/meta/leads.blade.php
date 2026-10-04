{{--
    Meta Ads Module V1 (contract 24 §10) — Leads.

    TWO MEASUREMENTS, kept visibly apart:
      (a) Meta-reported results: Meta's own count of the result type you chose,
          in the AD ACCOUNT currency.
      (b) Business OS outcomes: leads this platform recorded whose first visit
          carried a Meta campaign tag, with CRM opportunity value in the
          BUSINESS currency.
    Amounts from (a) and (b) are never summed or compared. Meta Pixel, the
    Conversions API and click-ID capture are NOT enabled, so nothing here can
    name a Meta campaign, ad set or ad. Names, tags and landing pages are
    customer-supplied: escaped.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Meta Ads leads')

@php
    use App\Library\Crm\CrmMoney;
    use App\Library\MetaAds\MetaAdsDisplay as D;

    $ready = $metaState === 'ready' && $period !== null;
    $adsCurrency = $account?->currency_code;
    $businessCurrency = $business->currency_code ?: null;
    $surface = ['public_form' => 'Form', 'website_form' => 'Website form', 'booking' => 'Booking'];
@endphp

@section('content')
    @include('customer.business.ads._header', [
        'title' => 'Leads',
        'subtitle' => 'Meta\'s result count and the leads recorded in this platform are two different measurements.',
        'provider' => 'meta',
    ])

    <x-flash-alert class="mb-2" />

    @if(! $ready)
        @include('customer.business.ads.meta._empty-state')
    @else
        @include('customer.business.ads._period-selector', ['periodRoute' => 'meta.leads.index'])

        <x-card :padded="true" class="mb-2" data-role="explainer">
            <h2 class="text-section-heading mb-1">Why these numbers can differ</h2>
            <ul class="mb-0 ps-2">
                <li class="mb-50"><strong>Meta-reported results</strong> are the actions Meta counts for the result type you chose. Meta decides what counts and when.</li>
                <li class="mb-50"><strong>Business OS leads</strong> are the contacts recorded here from your forms, website forms and bookings. A lead shows a Meta tag only if the visitor arrived on a link tagged with a Meta source (such as facebook or instagram).</li>
                <li class="mb-50" data-role="tracking-notice">The Meta Pixel, the Conversions API and Meta click-ID capture are <strong>not enabled</strong>. They would track visitors, and this platform does not yet have a visitor-consent mechanism to do that safely.</li>
                <li>A tag is not proof of a paid click, and it never tells us which Meta campaign, ad set or ad produced a lead, so we never guess one.</li>
            </ul>
        </x-card>

        <x-card :padded="true" class="mb-2" data-role="meta-results">
            <h2 class="text-section-heading mb-1">Meta-reported results</h2>
            <p class="text-caption text-muted mb-1">Meta's own count, in {{ $adsCurrency ?: 'your ad account currency' }}. These are not leads recorded in this platform.</p>
            @if($overview->resultTypeUnset)
                @include('customer.business.ads.meta._result-type-prompt')
            @elseif(! $overview->hasData)
                <p class="text-caption mb-0" data-role="no-meta-results">No ad data for this period yet.</p>
            @else
                <div class="d-flex flex-wrap gap-3" data-role="meta-results-summary">
                    <div><span class="text-label d-block">Results ({{ $overview->resultTypeLabel }})</span><span class="h3" data-role="meta-results-value">{{ D::count($overview->results) }}</span></div>
                    <div><span class="text-label d-block">Spend</span><span class="h3">{{ D::money($overview->spendMicros, $adsCurrency) }}</span></div>
                    <div><span class="text-label d-block">Cost per result</span><span class="h3">{{ D::money($overview->costPerResultMicros, $adsCurrency) }}</span></div>
                </div>
            @endif
        </x-card>

        <x-card :padded="true" class="mb-2" data-role="business-outcomes">
            <h2 class="text-section-heading mb-1">Business OS outcomes</h2>
            <p class="text-caption text-muted mb-1">Leads recorded in this platform ({{ $period->from->format('M j') }} &ndash; {{ $period->to->format('M j, Y') }}) whose first visit carried a Meta campaign tag. Opportunity values are in your business currency{{ $businessCurrency ? ' (' . $businessCurrency . ')' : '' }} and are never added to or compared with ad spend.</p>

            <div class="d-flex flex-wrap gap-1 mb-2" data-role="lead-summary">
                <x-badge variant="neutral" data-role="summary-meta-tagged">{{ number_format($summary['meta_tagged']) }} {{ $summary['meta_tagged'] === 1 ? 'lead' : 'leads' }} with a Meta tag</x-badge>
                <x-badge variant="neutral" data-role="summary-all">{{ number_format($summary['leads']) }} {{ $summary['leads'] === 1 ? 'lead' : 'leads' }} in total</x-badge>
            </div>

            @if($leads->total() === 0)
                <p class="text-caption mb-0" data-role="no-leads">No leads with a Meta tag were recorded in this period.</p>
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
                                <tr data-role="lead-row">
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
                                    <td><x-badge variant="neutral" data-role="lead-level">Campaign tags only</x-badge></td>
                                    <td data-role="lead-tags">
                                        @if($tags !== null)
                                            @if($tags['utm_source'])<span class="d-block">source tag: {{ $tags['utm_source'] }}</span>@endif
                                            @if($tags['utm_medium'])<span class="d-block">medium tag: {{ $tags['utm_medium'] }}</span>@endif
                                            @if($tags['utm_campaign'])<span class="d-block">campaign tag: {{ $tags['utm_campaign'] }}</span>@endif
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
