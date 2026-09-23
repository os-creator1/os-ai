{{--
    Contract 18, Sub-slice 18G — Technical / Website SEO audit.

    REPORT-ONLY. Every line on this page comes from one of exactly two
    sources: the closed SeoAuditRuleRegistry (a fixed template plus validated
    scalar facts), or a page's own name from the audited immutable snapshot,
    used only as a label. There is no scraped text, no provider text and no
    model-generated text anywhere, and there is no auto-fix button: a finding
    links to the existing Website page editor, where the customer edits and
    publishes through Website's own authorization (§8.7, §12).

    Indexability is shown as a STATUS, above the findings and visually apart
    from them. The platform-path `noindex`, the absence of canonical tags and
    the absence of JSON-LD are properties of the platform today (§8.7
    G-2/G-3) — never presented here as the customer's mistakes or as todo
    items.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'SEO — Website check')

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center flex-wrap">
            <h4 class="mb-0">Website check</h4>
            <span class="text-caption">{{ $business->name }}</span>
        </div>
    </div>

    @if (session('message'))
        <div class="alert alert-{{ session('status') === 'error' ? 'danger' : 'success' }}" role="alert">
            <div class="alert-body">{{ session('message') }}</div>
        </div>
    @endif

    {{-- Indexability: a status about the platform, never a finding. --}}
    <div class="card mb-2">
        <div class="card-body">
            <h5 class="card-title mb-50">{{ $page->indexability->label() }}</h5>
            <p class="card-text mb-0">{{ $page->indexability->detail() }}</p>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap mb-1">
                <div>
                    <h5 class="mb-25">What we checked</h5>
                    @if ($page->hasRun())
                        <span class="text-caption">
                            {{ $page->latestRun->page_count }} page(s) checked
                            {{ $page->latestRun->created_at?->diffForHumans() }}
                        </span>
                    @else
                        <span class="text-caption">No check has run yet.</span>
                    @endif
                </div>

                @if ($canManage)
                    <form method="POST"
                          action="{{ route('customer.workspaces.businesses.seo.audit.rerun', [$workspaceUid, $businessUid]) }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-primary">Check again</button>
                    </form>
                @endif
            </div>

            @if (! $page->hasRun())
                <p class="mb-0">Publish your website and we will check it automatically.</p>
            @elseif ($page->findings === [])
                <p class="mb-0">We found nothing to fix on your published pages.</p>
            @else
                <div class="mb-1">
                    <span class="badge bg-light-danger me-50">{{ $page->latestRun->critical_count }} critical</span>
                    <span class="badge bg-light-warning me-50">{{ $page->latestRun->warning_count }} needs attention</span>
                    <span class="badge bg-light-info">{{ $page->latestRun->info_count }} suggestions</span>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th scope="col">Page</th>
                                <th scope="col">Finding</th>
                                <th scope="col">What it means</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($page->findings as $finding)
                                <tr>
                                    <td>
                                        @if ($finding->isSiteLevel())
                                            <span class="text-caption">Whole site</span>
                                        @else
                                            {{ $finding->pageName ?? 'A page' }}
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge bg-light-{{ $finding->severity->value === 'critical' ? 'danger' : ($finding->severity->value === 'warning' ? 'warning' : 'info') }}">
                                            {{ $finding->severity->label() }}
                                        </span>
                                        <div>{{ $finding->title }}</div>
                                    </td>
                                    <td>{{ $finding->description }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    @if (count($page->history) > 1)
        <div class="card">
            <div class="card-body">
                <h5 class="mb-1">Earlier checks</h5>
                <ul class="list-unstyled mb-0">
                    @foreach ($page->history as $run)
                        <li class="mb-25">
                            <span class="text-caption">{{ $run->created_at?->diffForHumans() }}</span>
                            — {{ $run->page_count }} page(s),
                            {{ $run->totalFindings() }} finding(s)
                            @if ($run->status->value === 'failed')
                                <span class="badge bg-light-secondary">could not be completed</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif
@endsection
