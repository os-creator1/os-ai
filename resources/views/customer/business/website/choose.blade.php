{{--
    External Website Audit Mode V1 — the FIRST Website screen for a Business that has not chosen.

    Website means "MotionGrove understands and improves your website", not "you
    must host it with us". Three clear choices, none forced:

      1. build one with MotionGrove      -> the existing guided website creation
      2. use my existing website         -> connect it; MotionGrove audits and monitors it
      3. do this later                   -> nothing is set up

    Every choice is a plain POST form (works without JavaScript).
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Website')

@section('content')
    <div class="row justify-content-center">
        <div class="col-xl-10">
            <h1 class="h3 mb-1">Your website</h1>
            <p class="text-muted mb-2">How would you like to work with your website? You can change your mind later.</p>

            <x-flash-alert class="mb-2" />

            @if(($mode ?? null) === 'none')
                <x-alert variant="neutral" icon="info" role="status" class="mb-2" data-role="chose-later">You chose to decide later. Pick an option whenever you are ready.</x-alert>
            @endif

            <div class="row g-2" data-role="website-choices">
                <div class="col-md-4 d-flex">
                    <x-card class="w-100" data-choice="hosted">
                        <h2 class="h5">Build with MotionGrove</h2>
                        <p class="text-muted">Create a complete website using your Business information.</p>
                        <form method="POST" action="{{ route('customer.workspaces.businesses.website.mode.choose', [$workspaceUid, $businessUid]) }}">
                            @csrf
                            <input type="hidden" name="mode" value="hosted">
                            <button type="submit" class="btn btn-primary" data-role="choose-hosted">Build my website</button>
                        </form>
                    </x-card>
                </div>

                <div class="col-md-4 d-flex">
                    <x-card class="w-100" data-choice="external">
                        <h2 class="h5">Use my existing website</h2>
                        <p class="text-muted">Keep your current website. MotionGrove will audit it, monitor it, and tell you what to improve.</p>
                        <form method="POST" action="{{ route('customer.workspaces.businesses.website.mode.choose', [$workspaceUid, $businessUid]) }}">
                            @csrf
                            <input type="hidden" name="mode" value="external">
                            <label class="form-label" for="choose-website-url">Your website address</label>
                            <input class="form-control mb-1" type="text" inputmode="url" id="choose-website-url" name="website_url" placeholder="example.com"
                                   value="{{ old('website_url', $business->website_url) }}" autocomplete="url" maxlength="2048">
                            <button type="submit" class="btn btn-outline-primary" data-role="choose-external">Connect existing website</button>
                        </form>
                    </x-card>
                </div>

                <div class="col-md-4 d-flex">
                    <x-card class="w-100" data-choice="later">
                        <h2 class="h5">Do this later</h2>
                        <p class="text-muted">No setup now. Nothing changes until you decide.</p>
                        <form method="POST" action="{{ route('customer.workspaces.businesses.website.mode.choose', [$workspaceUid, $businessUid]) }}">
                            @csrf
                            <input type="hidden" name="mode" value="none">
                            <button type="submit" class="btn btn-flat-secondary" data-role="choose-later">Do this later</button>
                        </form>
                    </x-card>
                </div>
            </div>
        </div>
    </div>
@endsection
