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
@section('page-heading', 'own')

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
        <x-alert :variant="session('status') === 'error' ? 'danger' : 'success'" :dismissible="true" data-role="flash-message">{{ session('message') }}</x-alert>
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

            @if (! empty($publishedPending))
                <div class="mb-1" data-role="audit-fix-published-pending">
                    @foreach ($publishedPending as $item)
                        <p class="text-caption mb-25">Published, awaiting a fresh audit: {{ $item['title'] }} now has a {{ $item['what'] }} in the current published revision. The finding is not yet verified resolved.</p>
                    @endforeach
                </div>
            @endif

            @if (! empty($resolved))
                <div class="mb-1" data-role="audit-resolved">
                    @foreach ($resolved as $item)
                        <p class="text-caption mb-25">Verified resolved by a fresh audit: {{ $item['title'] }} now has a {{ $item['what'] }}.</p>
                    @endforeach
                </div>
            @endif

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
                                    <td>
                                        {{ $finding->description }}
                                        @if (isset($draftFixes[$loop->index]))
                                            @php $fix = $draftFixes[$loop->index]; @endphp
                                            <div class="mt-50" data-role="audit-draft-fix">
                                                @if ($fix['live'])
                                                    <span class="text-caption text-muted" data-role="audit-fix-live">Published: your live website now has this. Run the audit again to confirm the finding is resolved.</span>
                                                @elseif ($fix['draftFixed'])
                                                    <span class="text-caption text-muted" data-role="audit-fix-drafted">Updated in your draft. Publish to make it live.</span>
                                                @endif
                                                <a href="{{ $fix['url'] }}" data-role="audit-draft-fix-link">{{ $fix['label'] }}</a>
                                                @if (($fix['proposal'] ?? null) !== null && ! empty($auditRunUid) && ($fix['draftToken'] ?? '') !== '')
                                                    <form method="POST" action="{{ route('customer.workspaces.businesses.seo.audit.title.apply', [$workspaceUid, $businessUid, $fix['pageUid']]) }}" class="mt-50" data-role="audit-title-proposal">
                                                        @csrf
                                                        <input type="hidden" name="audit_run" value="{{ $auditRunUid }}">
                                                        <input type="hidden" name="draft_token" value="{{ $fix['draftToken'] }}">
                                                        <label class="text-caption d-block" for="title-{{ $fix['pageUid'] }}">Proposed title for "{{ $fix['pageTitle'] }}" (edit it if you like)</label>
                                                        <input type="text" class="form-control form-control-sm mb-50" id="title-{{ $fix['pageUid'] }}" name="seo_title" maxlength="70" value="{{ $fix['proposal'] }}">
                                                        <button type="submit" class="btn btn-sm btn-primary">Approve and save to draft</button>
                                                        <span class="text-caption text-muted">Saves to your draft only. Publishing is a separate step.</span>
                                                    </form>
                                                @endif
                                                @if (($fix['descriptionForm'] ?? false) && ! empty($auditRunUid) && ($fix['draftToken'] ?? '') !== '')
                                                    <form method="POST" action="{{ route('customer.workspaces.businesses.seo.audit.description.apply', [$workspaceUid, $businessUid, $fix['pageUid']]) }}" class="mt-50" data-role="audit-description-form">
                                                        @csrf
                                                        <input type="hidden" name="audit_run" value="{{ $auditRunUid }}">
                                                        <input type="hidden" name="draft_token" value="{{ $fix['draftToken'] }}">
                                                        <label class="text-caption d-block" for="desc-{{ $fix['pageUid'] }}">Meta description for "{{ $fix['pageTitle'] }}" (70-160 characters)</label>
                                                        @if (! empty($fix['replacesDescription']))
                                                            <p class="text-caption mb-25" data-role="audit-current-description"><strong>Current description ({{ mb_strlen($fix['currentDescription']) }} characters, too short):</strong> {{ $fix['currentDescription'] }}</p>
                                                        @endif
                                                        <textarea class="form-control form-control-sm mb-50" rows="3" id="desc-{{ $fix['pageUid'] }}" name="meta_description" minlength="70" maxlength="160">{{ $fix['currentDescription'] ?? '' }}</textarea>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-role="audit-suggest-description" data-url="{{ $fix['suggestUrl'] }}" data-target="desc-{{ $fix['pageUid'] }}">Suggest with AI (optional)</button>
                                                        <button type="submit" class="btn btn-sm btn-primary">Approve and save to draft</button>
                                                        <span class="text-caption text-muted">Nothing is written until you approve. Saves to your draft only; publishing is a separate step.</span>
                                                        <span class="text-caption text-danger d-block" data-role="audit-suggest-error" hidden role="alert"></span>
                                                    </form>
                                                @endif
                                            </div>
                                        @endif
                                    </td>
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
<script>
// Optional suggestion: runs only on an explicit click, through the existing read-only Website endpoint. It fills the field for review; nothing is saved.
document.querySelectorAll('[data-role="audit-suggest-description"]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var form = btn.closest('form'), err = form.querySelector('[data-role="audit-suggest-error"]'), field = document.getElementById(btn.dataset.target);
        btn.disabled = true; err.hidden = true;
        fetch(btn.dataset.url, { method: 'POST', headers: { 'X-CSRF-TOKEN': form.querySelector('input[name="_token"]').value, 'Accept': 'application/json' } })
            .then(function (r) { return r.json().catch(function () { return {}; }); })
            .then(function (d) {
                if (d && d.ok && d.description) { field.value = d.description; }
                else { err.textContent = (d && d.message) || 'A suggestion is not available right now. You can write the description yourself.'; err.hidden = false; }
            })
            .catch(function () { err.textContent = 'A suggestion is not available right now. You can write the description yourself.'; err.hidden = false; })
            .then(function () { btn.disabled = false; });
    });
});
</script>
@endsection
