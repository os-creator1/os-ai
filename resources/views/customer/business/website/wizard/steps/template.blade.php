@extends('layouts/contentLayoutMaster')

@section('title', 'Choose a style')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-8">
            @include('customer.business.website.wizard._progress', ['progress' => $progress])

            <x-flash-alert class="mb-3" />

            <h4 class="mb-1">Choose a style</h4>
            <p class="text-caption mb-3">Every template builds the same complete site structure from your business facts — only the look changes. You can adjust it after your site is generated.</p>

            <form method="POST" action="{{ route('customer.workspaces.businesses.website.setup.template', [$workspaceUid, $businessUid]) }}">
                @csrf
                <div class="row">
                    @foreach ($templates as $template)
                        <div class="col-md-3 mb-3">
                            <label class="website-starter-choice d-block h-100" for="website-template-{{ $template->key }}">
                                <input class="form-check-input me-1" type="radio" name="template_key" id="website-template-{{ $template->key }}" value="{{ $template->key }}" @checked($loop->first) required>
                                <strong>{{ $template->display_name }}</strong>
                                <span class="website-starter-preview website-starter-preview-{{ $template->key }}" aria-hidden="true">
                                    <span class="website-starter-preview-top"></span>
                                    <span class="website-starter-preview-title"></span>
                                    <span class="website-starter-preview-line"></span>
                                    <span class="website-starter-preview-button"></span>
                                    <span class="website-starter-preview-cards"><i></i><i></i><i></i></span>
                                </span>
                                <span class="text-caption d-block">{{ $template->description }}</span>
                            </label>
                        </div>
                    @endforeach
                </div>

                <x-button type="submit" variant="primary">Continue</x-button>
            </form>
        </div>
    </div>
@endsection
