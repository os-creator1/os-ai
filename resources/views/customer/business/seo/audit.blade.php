{{--
    Contract 18, Sub-slice 18G — Technical / Website SEO audit.

    REPORT-ONLY. Every line on this page comes from one of exactly two
    sources: the closed SeoAuditRuleRegistry (a fixed template plus validated
    scalar facts), or a page's own name from the audited immutable snapshot,
    used only as a label. There is no scraped text, no provider text and no
    model-generated text anywhere, and there is no auto-fix button: a finding
    links to the existing Website page editor, where the customer edits and
    publishes through Website's own authorization (§8.7, §12).

    Whether search engines can find the site is shown as a STATUS, above the
    findings and visually apart from them, in plain words (Good / Needs
    attention / Action) — never presented here as the customer's mistake.

    A check that FAILED is never shown as "nothing to fix": that sentence is
    reserved for a completed check of the version that is published now. The
    run shown is the one for the published revision, so after a rollback it
    never describes another version's pages.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'SEO — Website check')

@php
    use App\Library\Seo\SeoAuditPage;

    $state = $page->state();
@endphp

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

    {{-- Indexability: a status about how the site is set up, never a finding. --}}
    @include('customer.business.seo._indexability', ['indexability' => $page->indexability, 'workspaceUid' => $workspaceUid, 'businessUid' => $businessUid])

    <div class="card" data-section="audit-result" data-state="{{ $state }}">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap mb-1">
                <div>
                    <h5 class="mb-25">What we checked</h5>
                    @if ($page->hasRun())
                        <span class="text-caption" data-role="audit-checked-at">
                            @if ($state === SeoAuditPage::STATE_FAILED)
                                Last attempt {{ $page->latestRun->created_at?->diffForHumans() }}
                            @else
                                {{ $page->latestRun->page_count }} {{ $page->latestRun->page_count === 1 ? 'page' : 'pages' }} checked
                                {{ $page->latestRun->created_at?->diffForHumans() }}
                            @endif
                        </span>
                    @elseif ($state === SeoAuditPage::STATE_NOT_CHECKED)
                        <span class="text-caption" data-role="audit-checked-at">Not checked yet for the current version.</span>
                    @else
                        <span class="text-caption" data-role="audit-checked-at">No check has run yet.</span>
                    @endif
                </div>

                {{-- Only offered where a check can actually be started: a published website, and someone who may manage SEO. --}}
                @if ($canManage && $page->hasPublishedWebsite)
                    <form method="POST"
                          action="{{ route('customer.workspaces.businesses.seo.audit.rerun', [$workspaceUid, $businessUid]) }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-primary" data-role="audit-rerun">Check again</button>
                    </form>
                @endif
            </div>

            @if ($state === SeoAuditPage::STATE_NO_WEBSITE)
                <p class="mb-0" data-role="audit-message">You have no published website yet. Publish your website and we will check it automatically.</p>
            @elseif ($state === SeoAuditPage::STATE_NOT_CHECKED)
                <p class="mb-0" data-role="audit-message">
                    This version of your website has not been checked yet.
                    @if ($canManage)
                        Select Check again to start a check; it also runs automatically each time you publish.
                    @else
                        It is checked automatically each time you publish, or someone who can manage SEO can start a check.
                    @endif
                </p>
            @elseif ($state === SeoAuditPage::STATE_FAILED)
                <p class="mb-0" data-role="audit-message">
                    We couldn't check your site this time — try again.
                    @if ($canManage)
                        If it keeps happening, publish your website again.
                    @else
                        Someone who can manage SEO can try again.
                    @endif
                </p>
            @elseif ($state === SeoAuditPage::STATE_CLEAN)
                <p class="mb-0" data-role="audit-message">We found nothing to fix on your published pages.</p>
            @else
                <div class="mb-1">
                    <span class="badge bg-light-danger me-50">{{ $page->latestRun->critical_count }} action</span>
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
                            @if ($run->status->value === 'failed')
                                — could not be completed
                            @else
                                — {{ $run->page_count }} {{ $run->page_count === 1 ? 'page' : 'pages' }},
                                {{ $run->totalFindings() }} {{ $run->totalFindings() === 1 ? 'finding' : 'findings' }}
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif
@endsection
