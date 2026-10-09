{{--
    SEO → Content → Autopilot → Content Profile. The short first-enable questions: only what MotionGrove cannot already
    know. Everything on the Website, Packages, Locations and Business Profile is used as it is and never asked again.
    Every answer is optional. Escaped Blade output only.

    Expects: $workspaceUid, $businessUid, $business, $profile (common_questions/emphasis/avoid_topics),
             $differentiators (existing, from the Business Profile), $gaps, $completed.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Content Autopilot profile')

@section('content')
    @php($route = fn (string $name, array $extra = []) => route('customer.workspaces.businesses.seo.content.' . $name, array_merge([$workspaceUid, $businessUid], $extra)))

    <div class="row mb-1">
        <div class="col-12">
            <h4 class="mb-25">Content Autopilot profile</h4>
            <p class="text-caption mb-0">A few quick answers help Autopilot write like you. Everything is optional, and you can change it any time.</p>
        </div>
    </div>

    <x-flash-alert class="mb-2" />

    <x-card :padded="true" class="mb-2" data-role="already-known">
        <h5 class="mb-50">What we already know</h5>
        <p class="mb-0 text-caption">
            Your services, packages and prices, the areas you serve, your website's questions and answers and your Business Profile are used
            as they are. You will not be asked for any of it again.
        </p>
    </x-card>

    <form method="POST" action="{{ $route('autopilot.profile.save') }}" data-role="autopilot-profile-form">
        @csrf

        <x-card :padded="true" class="mb-2">
            @if(in_array('differentiators', $gaps, true))
                <div class="mb-2" data-field="differentiators">
                    <label class="form-label" for="f-differentiators">What makes you different?</label>
                    <textarea id="f-differentiators" class="form-control @error('differentiators') is-invalid @enderror" name="differentiators" rows="3" maxlength="1000" placeholder="One per line, for example: same-day setup, locally owned">{{ old('differentiators') }}</textarea>
                    @error('differentiators')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
            @else
                <div class="mb-2" data-field="differentiators-known">
                    <div class="form-label">What makes you different</div>
                    <p class="mb-0 text-caption">{{ implode(' · ', $differentiators) }}. This comes from your Business Profile.</p>
                </div>
            @endif

            <div class="mb-2" data-field="common_questions">
                <label class="form-label" for="f-questions">What do customers ask you most?</label>
                <textarea id="f-questions" class="form-control @error('common_questions') is-invalid @enderror" name="common_questions" rows="4" maxlength="2000" placeholder="One question per line">{{ old('common_questions', implode("\n", $profile['common_questions'])) }}</textarea>
                @error('common_questions')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>

            <div class="mb-2" data-field="emphasis">
                <label class="form-label" for="f-emphasis">Anything you would like us to emphasise?</label>
                <textarea id="f-emphasis" class="form-control @error('emphasis') is-invalid @enderror" name="emphasis" rows="3" maxlength="1000" placeholder="One per line, for example: wedding packages, corporate events">{{ old('emphasis', implode("\n", $profile['emphasis'])) }}</textarea>
                @error('emphasis')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>

            <div class="mb-0" data-field="avoid_topics">
                <label class="form-label" for="f-avoid">Topics we should never write about</label>
                <textarea id="f-avoid" class="form-control @error('avoid_topics') is-invalid @enderror" name="avoid_topics" rows="3" maxlength="1500" placeholder="One per line">{{ old('avoid_topics', implode("\n", $profile['avoid_topics'])) }}</textarea>
                @error('avoid_topics')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                <div class="text-caption mt-50">Claims you never want made are set in your Business Profile and are always respected.</div>
            </div>
        </x-card>

        <button type="submit" class="btn btn-primary" data-role="save-profile">{{ $completed ? 'Save' : 'Save and continue' }}</button>
        <a class="btn btn-outline-secondary ms-50" href="{{ $route('autopilot') }}">Back to Content</a>
    </form>
@endsection
