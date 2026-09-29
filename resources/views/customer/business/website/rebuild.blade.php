@extends('layouts/contentLayoutMaster')

@section('title', 'Rebuild website')

@section('content')
    <div class="row mb-3">
        <div class="col-12">
            <h4 class="mb-1">Rebuild website from a template</h4>
            <p class="text-caption mb-0">Regenerates every page as a fresh DRAFT from your current saved business facts and a template you choose.</p>
        </div>
    </div>

    <x-flash-alert class="mb-3" />

    @if ($errors->any())
        <x-alert variant="danger" class="mb-3">{{ $errors->first() }}</x-alert>
    @endif

    <x-alert variant="{{ $isPublished ? 'accent' : 'neutral' }}" class="mb-3">
        @if ($isPublished)
            Your site is currently <strong>published and live</strong>. Rebuilding only replaces your {{ $currentPageCount }} current DRAFT page(s) — your published site keeps serving visitors exactly as it is now, completely unaffected, until you come back here and explicitly click Publish on the new draft. The version that is live right now stays available to roll back to afterward.
        @else
            Your site has {{ $currentPageCount }} draft page(s) and has never been published, so nothing is currently live to protect — rebuilding simply replaces this draft.
        @endif
    </x-alert>

    <form method="POST" action="{{ route('customer.workspaces.businesses.website.rebuild', [$workspaceUid, $businessUid]) }}">
        @csrf
        <fieldset>
            <legend class="h5 mb-2">Choose a template</legend>
            <div class="row">
                @foreach ($templates as $template)
                    <div class="col-md-3 mb-3">
                        <label class="d-block h-100 border rounded p-3" for="rebuild-template-{{ $template->key }}" style="cursor:pointer;">
                            <input class="form-check-input me-1" type="radio" name="template_key" id="rebuild-template-{{ $template->key }}" value="{{ $template->key }}" @checked($website->template_key === $template->key || ($loop->first && $website->template_key === null))>
                            <strong>{{ $template->display_name }}</strong>
                            <span class="text-caption d-block">{{ $template->description }}</span>
                        </label>
                    </div>
                @endforeach
            </div>
        </fieldset>

        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="confirm_rebuild" value="1" id="confirm-rebuild">
            <label class="form-check-label" for="confirm-rebuild">
                I understand this replaces every current draft page{{ $isPublished ? ' (my published site will not change until I publish this new draft)' : '' }}.
            </label>
        </div>

        <x-button type="submit" variant="primary">Rebuild draft</x-button>
        <x-button type="button" variant="ghost" onclick="window.history.back()">Cancel</x-button>
    </form>
@endsection
