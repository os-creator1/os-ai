@extends('layouts/contentLayoutMaster')

@section('title', 'Choose your Google profile')

@section('content')
    <section id="business-onboarding-google">
        <div class="row justify-content-center">
            <div class="col-12 col-lg-8">
                <x-card title="Choose your business">
                    @if ($errors->any())
                        <x-alert variant="danger">
                            <ul class="mb-0">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </x-alert>
                    @endif

                    @if (count($offers) === 0)
                        <h5>No business profiles found</h5>
                        <p class="text-muted">
                            This Google account does not manage any Business Profile that MotionGrove can read.
                            Try a different Google account, or set up your business manually. It only takes a minute.
                        </p>
                    @else
                        <h5>Which business is yours?</h5>
                        <p class="text-muted">
                            These are the profiles your Google account manages. Pick the right one. We never choose for you.
                        </p>

                        <ul class="list-unstyled mb-0" data-role="google-profiles">
                            @foreach ($offers as $offer)
                                <li class="border rounded p-1 mb-1">
                                    <form method="POST" action="{{ route('customer.onboarding.google.select') }}" class="d-flex flex-wrap align-items-center justify-content-between gap-1">
                                        @csrf
                                        <input type="hidden" name="candidate_token" value="{{ $offer['token'] }}">
                                        <div class="min-w-0">
                                            <strong>{{ $offer['candidate']->displayTitle() }}</strong>
                                            @if ($offer['candidate']->localityHint)
                                                <div class="text-muted small">{{ $offer['candidate']->localityHint }}</div>
                                            @endif
                                        </div>
                                        <x-button type="submit" variant="primary" size="sm">Use this profile</x-button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <div class="d-flex flex-wrap gap-1 mt-2">
                        <a class="btn btn-outline-secondary" href="{{ route('customer.onboarding.show', ['step' => 'business', 'manual' => 1]) }}">Set up manually</a>
                        <form method="POST" action="{{ route('customer.onboarding.google.reset') }}">
                            @csrf
                            <x-button type="submit" variant="outline" data-role="use-another-account">Use a different Google account</x-button>
                        </form>
                    </div>
                </x-card>
            </div>
        </div>
    </section>
@endsection
