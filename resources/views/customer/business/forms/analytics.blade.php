@extends('layouts/contentLayoutMaster')

@section('title', $form->name.' — Analytics')

@section('content')
    @php
        $scope = [$workspace->uid, $business->uid];
        $max = max(1, max($stats['by_day'] ?: [0]));
        $q = $stats['questionnaire'];
        $rate = $q && $q['completion_rate'] !== null ? ' · '.$q['completion_rate'].'% completion' : '';
    @endphp

    <link rel="stylesheet" href="{{ asset('css/forms/form-builder.css') }}?v={{ filemtime(public_path('css/forms/form-builder.css')) }}">
    @include('customer.business.forms._messages')

    <div class="fb">
        @include('customer.business.forms._builder_header', ['tab' => 'analytics'])

        <div class="fb-settings" data-role="forms-analytics">
            <section>
                <h5>Responses</h5>
                <div class="row text-center">
                    <div class="col-6 col-md-3 mb-1"><div class="h3 mb-0" data-role="stat-total">{{ $stats['submissions'] }}</div><small class="text-muted">All time</small></div>
                    <div class="col-6 col-md-3 mb-1"><div class="h3 mb-0" data-role="stat-recent">{{ $stats['last_30_days'] }}</div><small class="text-muted">Last {{ \App\Library\Forms\FormAnalyticsReader::DAYS }} days</small></div>
                    <div class="col-6 col-md-3 mb-1"><div class="h3 mb-0">{{ $stats['with_contact'] }}</div><small class="text-muted">Tied to a contact</small></div>
                    <div class="col-6 col-md-3 mb-1"><div class="h3 mb-0">{{ $stats['with_opportunity'] }}</div><small class="text-muted">Became an opportunity</small></div>
                </div>
                <div class="d-flex align-items-end mt-1" style="gap:2px;height:70px" aria-label="Responses per day, last {{ \App\Library\Forms\FormAnalyticsReader::DAYS }} days" role="img">
                    @foreach ($stats['by_day'] as $day => $n)
                        <div title="{{ $day }}: {{ $n }}" style="flex:1;background:var(--fb-accent);opacity:{{ $n ? 1 : .15 }};height:{{ $n ? max(6, (int) round($n / $max * 100)) : 4 }}%;border-radius:2px"></div>
                    @endforeach
                </div>
            </section>

            <section>
                <h5>By location</h5>
                @forelse ($stats['by_location'] as $row)
                    <div class="d-flex justify-content-between"><span>{{ $row['name'] }}</span><strong>{{ $row['count'] }}</strong></div>
                @empty
                    <p class="mb-0 text-muted">No locations you can access.</p>
                @endforelse
            </section>

            @if ($q)
                <section data-role="stat-questionnaire">
                    <h5>Questionnaire</h5>
                    <p class="mb-0">{{ $q['started'] }} started · {{ $q['finished'] }} finished{{ $rate }}</p>
                </section>
            @endif

            <section>
                <h5>Not measured yet</h5>
                <p class="mb-0 text-muted">
                    Page views and — for a one-page form — how many people started it aren't recorded, so a view count or conversion rate would be a guess and isn't shown.
                    Only completed responses{{ $q ? ' and questionnaire starts' : '' }} are counted.
                </p>
            </section>
        </div>
    </div>
@endsection
