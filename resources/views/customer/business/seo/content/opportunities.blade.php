{{--
    SEO → Content → Opportunities. Topics worth an article, computed from the Business's own services, packages,
    locations and niche strategy. There is NO search-volume, cost-per-click or competition figure anywhere: we do
    not have that data, so we do not show it. "Create AI draft" is a POST the owner submits; opening this page
    never calls the AI. Escaped Blade output only.
--}}
@extends(request()->query('fragment') === '1' ? 'customer.business.seo.content._fragment' : 'customer.business.seo.content._frame')

@section('title', 'Content opportunities')
@section('content-active', 'opportunities')
@section('content-subtitle', 'Topics that support your service pages and answer what customers ask before they book.')

@section('content-section')
    @if(count($opportunities) === 0)
        <x-card :padded="true" data-role="opportunities-empty">
            <h5 class="mb-50">Publish your website to see topic ideas</h5>
            <p class="text-caption mb-0">Topic ideas are built from your published pages, packages and service areas. Once your website is published they appear here.</p>
        </x-card>
    @else
        <p class="text-caption" data-role="opportunities-note">AI drafts are written from your own services, packages and prices, and always arrive as a draft for you to read and correct. Nothing is published until you publish it.</p>

        <div class="row g-1" data-role="opportunities">
            @foreach($opportunities as $opp)
                <div class="col-12 col-xl-6" data-opportunity="{{ $opp['key'] }}">
                    <x-card :padded="true" class="h-100">
                        <div class="d-flex justify-content-between align-items-start gap-1 mb-50">
                            <h5 class="mb-0">{{ $opp['title'] }}</h5>
                            @include('customer.business.seo.content._status_badge', ['state' => $opp['status'] === 'draft' ? 'draft' : $opp['status']])
                        </div>
                        @if($opp['status'] === 'draft')<span class="visually-hidden">Draft exists</span>@endif

                        <dl class="row mb-1 small">
                            <dt class="col-sm-4">Search intent</dt>
                            <dd class="col-sm-8">{{ \App\Enums\Seo\ArticleIntent::tryFrom($opp['search_intent'])?->label() ?? '—' }}</dd>
                            <dt class="col-sm-4">Target topic</dt>
                            <dd class="col-sm-8">{{ $opp['primary_topic'] }}</dd>
                            <dt class="col-sm-4">Supports</dt>
                            <dd class="col-sm-8">{{ $opp['supports_page_title'] ?? '—' }}</dd>
                            <dt class="col-sm-4">Why it matters</dt>
                            <dd class="col-sm-8">{{ $opp['why'] }}</dd>
                            @if(count($opp['internal_links']) > 0)
                                <dt class="col-sm-4">Links to</dt>
                                <dd class="col-sm-8">{{ collect($opp['internal_links'])->pluck('title')->implode(', ') }}</dd>
                            @endif
                        </dl>

                        @include('customer.business.seo.content._opportunity_actions', ['opp' => $opp])
                    </x-card>
                </div>
            @endforeach
        </div>
    @endif
@endsection
