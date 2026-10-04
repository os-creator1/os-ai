@extends('layouts/contentLayoutMaster')

@section('title', 'Feature Management')

@section('content')
    <section id="admin-platform-features">
        @include('admin.partials.flash')
        <div class="card">
            <div class="card-header">
                <div>
                    <h4 class="card-title mb-25">Feature Management</h4>
                    <p class="mb-0 text-muted">What each plan includes. Change packaging on the plan itself; a feature that is "Coming soon" is not built yet and has no effect for customers.</p>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                    <tr>
                        <th>Feature</th>
                        <th>Status</th>
                        @foreach ($plans as $p)
                            <th class="text-center"><a href="{{ route('admin.platform-plans.edit', $p['catalog']->tier->value) }}">{{ $p['catalog']->display_name }}</a></th>
                        @endforeach
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($groups as $group => $items)
                        <tr class="table-light"><td colspan="{{ 2 + count($plans) }}"><strong>{{ $group }}</strong></td></tr>
                        @foreach ($items as $key => $label)
                            <tr>
                                <td>{{ $label }} <span class="small text-muted">{{ $key }}</span></td>
                                <td>
                                    @if ($available[$key] ?? false)
                                        <span class="badge badge-light-success">Live</span>
                                    @else
                                        <span class="badge badge-light-secondary">Coming soon</span>
                                    @endif
                                </td>
                                @foreach ($plans as $p)
                                    <td class="text-center">{!! in_array($key, $p['keys'], true) ? '<span class="text-success" aria-label="Included">&#10003;</span>' : '<span class="text-muted" aria-label="Not included">&mdash;</span>' !!}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>
@endsection
