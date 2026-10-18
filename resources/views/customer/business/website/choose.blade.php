{{--
    External Website Audit Mode V1 — the FIRST Website screen for a Business that has not chosen.

    Website means "MotionGrove understands and improves your website", not "you
    must host it with us". Two clear choices, none forced:

      1. build one with MotionGrove      -> the existing guided website creation
      2. use my existing website         -> connect it; MotionGrove audits and monitors it

    There is deliberately no "do this later" action: leaving this screen alone
    already sets nothing up. (A Business that chose "later" before this screen
    lost that action still lands here, and sees the note below.)

    Every choice is a plain POST form (works without JavaScript).
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Website')

@section('page-style')
    <style>
        .website-choices{max-width:56rem}
        .website-choices .choice-card{height:100%;display:flex;flex-direction:column}
        .website-choices .choice-icon{display:inline-flex;align-items:center;justify-content:center;width:2.5rem;height:2.5rem;border-radius:.6rem;margin-bottom:1rem}
        .website-choices .choice-icon.is-build{background:var(--color-primary-soft-bg,#f4e6e4);color:var(--color-primary,#b5524c)}
        .website-choices .choice-icon.is-existing{background:#e6eefb;color:#2b5fb8}
        .website-choices .choice-points{list-style:none;margin:.5rem 0 1.25rem;padding:0;font-size:.85rem}
        .website-choices .choice-points li{display:flex;gap:.5rem;align-items:flex-start;margin-bottom:.35rem}
        .website-choices .choice-points .ds-icon{flex:none;margin-top:.15rem;color:#3d8b5f}
        .website-choices .choice-action{margin-top:auto}
        .website-choices .choice-url-row{display:flex;gap:.5rem;align-items:stretch;flex-wrap:wrap}
        .website-choices .choice-url-row input{flex:1 1 11rem;min-width:0}
        @media (max-width:575.98px){.website-choices .choice-url-row .btn{width:100%}}
    </style>
@endsection

@section('content')
    <div class="website-choices-page">
        <h1 class="h3 mb-1">Your website</h1>
        <p class="text-muted mb-2">How would you like to work with your website? You can change your mind later.</p>

        <x-flash-alert class="mb-2" />

        @if(($mode ?? null) === 'none')
            <x-alert variant="neutral" icon="info" role="status" class="mb-2" data-role="chose-later">You chose to decide later. Pick an option whenever you are ready.</x-alert>
        @endif

        <div class="row g-2 website-choices" data-role="website-choices">
            <div class="col-md-6 d-flex">
                <x-card class="w-100 choice-card" data-choice="hosted">
                    <span class="choice-icon is-build" aria-hidden="true"><x-ds-icon name="layout-template" size="20" /></span>
                    <h2 class="h5">Build with MotionGrove</h2>
                    <p class="text-muted">Create a complete website using your Business information.</p>
                    <ul class="choice-points">
                        <li><x-ds-icon name="check" size="14" aria-hidden="true" /> Pages filled in from your business details</li>
                        <li><x-ds-icon name="check" size="14" aria-hidden="true" /> Edit and publish it here</li>
                    </ul>
                    <form method="POST" class="choice-action" action="{{ route('customer.workspaces.businesses.website.mode.choose', [$workspaceUid, $businessUid]) }}">
                        @csrf
                        <input type="hidden" name="mode" value="hosted">
                        <button type="submit" class="btn btn-primary w-100" data-role="choose-hosted">Build my website <x-ds-icon name="arrow-right" size="16" aria-hidden="true" /></button>
                    </form>
                </x-card>
            </div>

            <div class="col-md-6 d-flex">
                <x-card class="w-100 choice-card" data-choice="external">
                    <span class="choice-icon is-existing" aria-hidden="true"><x-ds-icon name="link" size="20" /></span>
                    <h2 class="h5">Use my existing website</h2>
                    <p class="text-muted">Keep your current website. MotionGrove will audit it, monitor it, and tell you what to improve.</p>
                    <ul class="choice-points">
                        <li><x-ds-icon name="check" size="14" aria-hidden="true" /> Site audit of your pages</li>
                        <li><x-ds-icon name="check" size="14" aria-hidden="true" /> Ongoing monitoring and suggestions</li>
                    </ul>
                    <form method="POST" class="choice-action" action="{{ route('customer.workspaces.businesses.website.mode.choose', [$workspaceUid, $businessUid]) }}">
                        @csrf
                        <input type="hidden" name="mode" value="external">
                        <label class="form-label" for="choose-website-url">Your website address</label>
                        <div class="choice-url-row">
                            <input class="form-control" type="text" inputmode="url" id="choose-website-url" name="website_url" placeholder="https://www.yourbusiness.com"
                                   value="{{ old('website_url', $business->website_url) }}" autocomplete="url" maxlength="2048">
                            <button type="submit" class="btn btn-outline-primary" data-role="choose-external">Connect existing website</button>
                        </div>
                    </form>
                </x-card>
            </div>
        </div>
    </div>
@endsection
