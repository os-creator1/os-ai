{{--
    Website V1 final — "SEO & Website Health": a short list of real checks
    (WebsiteHealthChecker), plain sentences, one fix link each. The deep
    technical Site Audit stays an advanced, separate view.
--}}
@if (! empty($health))
    <x-card class="mb-3" data-testid="website-health">
        <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2 mb-2">
            <h6 class="mb-0">SEO &amp; Website Health</h6>
            <span class="text-caption" data-testid="health-summary">
                {{ $health['summary']['ok'] }} good @if ($health['summary']['warn'] > 0)&middot; {{ $health['summary']['warn'] }} need attention @endif @if ($health['summary']['fail'] > 0)&middot; {{ $health['summary']['fail'] }} to fix now @endif
            </span>
        </div>
        <p class="text-caption mb-3">These checks look at your real pages and business details. They don't predict rankings — they show what is and isn't in place.</p>

        <ul class="list-unstyled mb-0">
            @foreach ($health['checks'] as $check)
                <li class="d-flex gap-2 align-items-start py-2 border-top" data-health-check="{{ $check['key'] }}" data-health-status="{{ $check['status'] }}">
                    <span class="badge flex-shrink-0 {{ $check['status'] === 'ok' ? 'bg-success' : ($check['status'] === 'warn' ? 'bg-warning text-dark' : 'bg-danger') }}" style="min-width:7rem;">
                        {{ $check['status'] === 'ok' ? 'Good' : ($check['status'] === 'warn' ? 'Needs attention' : 'Action') }}
                    </span>
                    <div class="flex-grow-1">
                        <div class="fw-bold">{{ $check['title'] }}</div>
                        <div class="text-caption">{{ $check['detail'] }}</div>
                        @if (! empty($check['items']))
                            <details class="mt-1">
                                <summary class="small">Show details</summary>
                                <ul class="small mb-0 mt-1">
                                    @foreach (array_slice($check['items'], 0, 20) as $item)
                                        <li>{{ $item }}</li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                        @if ($check['action'])
                            <a class="small" href="{{ $check['action']['url'] }}">{{ $check['action']['label'] }}</a>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        @if (! empty($seoAuditUrl))
            <details class="mt-3">
                <summary class="small">Advanced: full technical site audit</summary>
                <p class="text-caption mt-2 mb-1">A deeper, report-only audit of your published website (headings, links, structured data and more) lives in the SEO module.</p>
                <a class="small" href="{{ $seoAuditUrl }}">Open the technical site audit</a>
            </details>
        @endif
    </x-card>
@endif
