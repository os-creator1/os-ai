{{--
    The actions for one opportunity. "Create AI draft" is a POST form that the OWNER submits — nothing here
    ever calls the AI on render. An opportunity that already has an article links to it instead.

    Expects: $opp (opportunity array), $workspaceUid, $businessUid. Optional: $small (bool).
--}}
@php
    $small = $small ?? false;
    $btn = $small ? 'btn-sm' : '';
    $route = fn (string $name, array $extra = []) => route('customer.workspaces.businesses.seo.content.' . $name, array_merge([$workspaceUid, $businessUid], $extra));
@endphp
<div class="d-flex flex-wrap gap-50 align-items-center" data-role="opportunity-actions" data-key="{{ $opp['key'] }}">
    @if(! empty($opp['article_uid']))
        <a class="btn {{ $btn }} btn-outline-primary" href="{{ $route('articles.edit', [$opp['article_uid']]) }}" data-role="open-article">
            {{ ($opp['status'] ?? '') === 'published' ? 'View article' : 'Open draft' }}
        </a>
    @else
        @can('manage_seo')
            <form method="POST" action="{{ $route('opportunities.draft') }}" data-role="opportunity-ai-form">
                @csrf
                <input type="hidden" name="key" value="{{ $opp['key'] }}">
                <button class="btn {{ $btn }} btn-primary" type="submit" data-role="create-ai-draft">Create AI draft</button>
            </form>
            <form method="POST" action="{{ $route('opportunities.start') }}" data-role="opportunity-start-form">
                @csrf
                <input type="hidden" name="key" value="{{ $opp['key'] }}">
                <button class="btn {{ $btn }} btn-outline-secondary" type="submit" data-role="write-myself">Write it myself</button>
            </form>
        @endcan
    @endif
</div>
