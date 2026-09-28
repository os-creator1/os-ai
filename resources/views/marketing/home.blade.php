@php
    $heroHeadline = $settings->hero_headline ?: 'Run your local service business from one place.';
    $heroSubheadline = $settings->hero_subheadline ?: 'Publish your business website, capture every inquiry, reply from one inbox, book the job, send the proposal, and get paid — without switching tools.';
@endphp
<x-layouts.marketing :description="$heroSubheadline">
    <x-slot:nav>
        <a href="#workflow">{{ __('How it works') }}</a>
        <a href="#plans">{{ __('Plans') }}</a>
        <a href="#faq">{{ __('FAQ') }}</a>
    </x-slot:nav>

    <section class="marketing-hero">
        <div class="marketing-shell marketing-hero__inner">
            <h1 class="marketing-hero__title">{{ $heroHeadline }}</h1>
            <p class="marketing-hero__subtitle">{{ $heroSubheadline }}</p>
            <div class="marketing-hero__actions">
                <a href="#plans" class="marketing-btn marketing-btn--ghost">{{ __('See plans') }}</a>
                @if (config('account.can_register'))
                    <a href="{{ route('register') }}" class="marketing-btn marketing-btn--primary marketing-btn--large">{{ __('Create account') }}</a>
                @endif
            </div>
        </div>
    </section>

    <section id="workflow" class="marketing-section marketing-section--alt">
        <div class="marketing-shell">
            <div class="marketing-section__header">
                <p class="marketing-section__eyebrow">{{ __('How it works') }}</p>
                <h2 class="marketing-section__title">{{ __('The same workflow, start to finish') }}</h2>
                <p class="marketing-section__lede">{{ __('One connected path from a visitor finding your business to getting paid for the job — no separate tools to reconcile.') }}</p>
            </div>
            @include('marketing.sections.workflow')
        </div>
    </section>

    <section class="marketing-section">
        <div class="marketing-shell">
            <div class="marketing-section__header">
                <p class="marketing-section__eyebrow">{{ __('What you get') }}</p>
                <h2 class="marketing-section__title">{{ __('Everything a local service business runs on') }}</h2>
                <p class="marketing-section__lede">{{ __('The exact capabilities included on your plan — nothing here is a future promise.') }}</p>
            </div>
            @include('marketing.sections.product-overview')
        </div>
    </section>

    <section class="marketing-section marketing-section--alt">
        <div class="marketing-shell">
            <div class="marketing-section__header">
                <p class="marketing-section__eyebrow">{{ __('Example: Photo Booth rentals') }}</p>
                <h2 class="marketing-section__title">{{ __('See it for a real kind of business') }}</h2>
                <p class="marketing-section__lede">{{ __('Photo Booth is one of the local-business niches the platform is built for today. The same workflow applies across other local-service niches.') }}</p>
            </div>
            @include('marketing.sections.photo-booth-example')
        </div>
    </section>

    <section id="plans" class="marketing-section">
        <div class="marketing-shell">
            <div class="marketing-section__header">
                <p class="marketing-section__eyebrow">{{ __('Pricing') }}</p>
                <h2 class="marketing-section__title">{{ __('Plans, plainly priced') }}</h2>
                <p class="marketing-section__lede">{{ __('Every price, currency, billing cycle, trial, and capability below comes straight from the plan catalog you see at signup — nothing is different here.') }}</p>
            </div>
            @include('marketing.sections.plans')
        </div>
    </section>

    @if ($testimonials->isNotEmpty())
        <section class="marketing-section marketing-section--alt">
            <div class="marketing-shell">
                <div class="marketing-section__header">
                    <p class="marketing-section__eyebrow">{{ __('In their words') }}</p>
                    <h2 class="marketing-section__title">{{ __('Feedback from an earlier business') }}</h2>
                    <p class="marketing-section__lede">{{ __('These are not reviews of this software — they are feedback the founder received running an earlier, related business.') }}</p>
                </div>
                @include('marketing.sections.testimonials')
            </div>
        </section>
    @endif

    <section id="faq" class="marketing-section">
        <div class="marketing-shell">
            <div class="marketing-section__header">
                <p class="marketing-section__eyebrow">{{ __('FAQ') }}</p>
                <h2 class="marketing-section__title">{{ __('Questions, answered plainly') }}</h2>
            </div>
            @include('marketing.sections.faq')
        </div>
    </section>

    <section class="marketing-section marketing-section--alt">
        <div class="marketing-shell">
            @include('marketing.sections.final-cta')
        </div>
    </section>
</x-layouts.marketing>
