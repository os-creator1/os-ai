@extends('layouts/contentLayoutMaster')

@section('title', $form->name.' — Analytics')

@section('content')
    @php
        // Design: FormAnalytics.dc.html. Every number comes from FormAnalyticsReader, which
        // is bounded to the Locations this actor may reach — never a total that would
        // disclose another Location's responses, and nothing invented (no views, no
        // conversion rate, no attribution).
        $scope = [$workspace->uid, $business->uid];
        $formScope = array_merge($scope, [$form->uid]);
        $days = \App\Library\Forms\FormAnalyticsReader::DAYS;
        $max = max(1, max($stats['by_day'] ?: [0]));
        $q = $stats['questionnaire'];
        $rate = $q && $q['completion_rate'] !== null ? ' · '.$q['completion_rate'].'% completion' : '';
        $hasResponses = $stats['submissions'] > 0;
        $tiles = [
            ['All responses', $stats['submissions'], 'stat-total'],
            ['Last '.$days.' days', $stats['last_30_days'], 'stat-recent'],
            ['Linked to a contact', $stats['with_contact'], 'stat-contact'],
            ['Became an opportunity', $stats['with_opportunity'], 'stat-opportunity'],
        ];
    @endphp

    @include('customer.business.forms._assets')
    @include('customer.business.forms._messages')

    <div class="mgf" data-role="forms-analytics">
        @include('customer.business.forms._builder_header', ['tab' => 'analytics'])

        <div class="mgf-stats">
            @foreach ($tiles as [$label, $value, $role])
                <div class="mgf-stat"><span>{{ $label }}</span><b data-role="{{ $role }}">{{ $value }}</b></div>
            @endforeach
        </div>

        @if (! $hasResponses)
            <section class="mgf-card mgf-empty" style="max-width:1000px;padding:40px 22px" data-role="forms-analytics-empty">
                <span class="mgf-empty-ic" style="width:56px;height:56px;border-radius:16px">@include('customer.business.forms._icon', ['n' => 'chart', 'style' => 'width:26px;height:26px'])</span>
                <b style="font-size:17px">Your chart starts with the first response</b>
                <span style="font-size:14px;color:var(--mgf-mute2);max-width:420px">Responses per day will show here, with a count for each location below.</span>
                @if ($shareUrl)
                    <button type="button" class="mgf-btn is-outline" data-copy="{{ $shareUrl }}">@include('customer.business.forms._icon', ['n' => 'copy']) Copy form link</button>
                @else
                    <a class="mgf-btn is-outline" href="{{ route('customer.workspaces.businesses.forms.edit', $formScope) }}#settings">Choose where to offer it</a>
                @endif
            </section>
        @else
            <section class="mgf-card" style="max-width:1000px" data-role="forms-analytics-chart">
                <h2>Responses per day</h2>
                <div style="display:flex;align-items:flex-end;gap:3px;height:120px" aria-label="Responses per day, last {{ $days }} days" role="img">
                    @foreach ($stats['by_day'] as $day => $n)
                        <div title="{{ \Illuminate\Support\Carbon::parse($day)->format('M j') }}: {{ $n }}" style="flex:1;background:var(--mgf-brand);opacity:{{ $n ? 1 : .15 }};height:{{ $n ? max(6, (int) round($n / $max * 100)) : 4 }}%;border-radius:3px"></div>
                    @endforeach
                </div>
                <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--mgf-faint)">
                    <span>{{ \Illuminate\Support\Carbon::parse(array_key_first($stats['by_day']))->format('M j') }}</span>
                    <span>{{ \Illuminate\Support\Carbon::parse(array_key_last($stats['by_day']))->format('M j') }}</span>
                </div>
            </section>
        @endif

        <section class="mgf-card" style="max-width:1000px;gap:0;padding:0;overflow:hidden" data-role="forms-analytics-locations">
            <div style="padding:16px 20px;font-weight:700">By location</div>
            @forelse ($stats['by_location'] as $row)
                <div style="display:flex;justify-content:space-between;padding:14px 20px;border-top:1px solid var(--mgf-line3);font-size:14px"><span>{{ $row['name'] }}</span><b>{{ $row['count'] }}</b></div>
            @empty
                <div style="padding:14px 20px;border-top:1px solid var(--mgf-line3);font-size:14px;color:var(--mgf-mute)">No locations you can access.</div>
            @endforelse
        </section>

        @if ($q)
            <section class="mgf-card" style="max-width:1000px" data-role="stat-questionnaire">
                <h2>Questionnaire</h2>
                <p class="mb-0">{{ $q['started'] }} started · {{ $q['finished'] }} finished{{ $rate }}</p>
            </section>
        @endif

        <span class="mgf-note" style="max-width:1000px" data-role="forms-analytics-note">Only finished responses{{ $q ? ' and questionnaire starts' : '' }} are counted. Page views and starts aren't tracked, so we don't show a conversion rate.</span>
    </div>
@endsection

@section('page-script')
    @include('customer.business.forms._copy_script')
@endsection
