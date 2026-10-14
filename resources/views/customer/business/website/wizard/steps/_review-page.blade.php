{{--
    The pre-generation Review page: one dashboard to check the saved answers, choose a design, see which photos are still
    needed and generate the draft. A presentation layer only — saving goes through the existing wizard autosave
    (Edit links return here with ?return=review), the look form (website.look.update) and the existing generate route.
--}}
@php
    $stepUrl = fn (string $key) => route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $key]) . '?return=review';
    $guided = $guidedReview !== null && $review !== null;
    $hasLook = ! empty($templateCards);
    $selectedDesign = collect($templateCards ?? [])->firstWhere('selected', true)['design'] ?? null;
    $checklist = $guided ? $guidedReview['checklist'] : [];
    $photosMissing = count(array_filter($checklist, fn ($item) => ! $item['done']));
    $generateData = ['workspaceUid' => $workspaceUid, 'businessUid' => $businessUid, 'answersRevision' => $answersRevision, 'lastAttemptFailed' => $lastAttemptFailed];

    $number = 0;
    $nextNumber = function () use (&$number) {
        return ++$number;
    };

    $navItems = [];
    if ($guided) {
        $navItems['rv-glance'] = 'At a glance';
    }
    if ($hasLook) {
        $navItems['rv-look'] = 'Look';
    }
    foreach (['rv-about' => ['About your business', 'About your business'], 'rv-services' => ['Services & packages', 'Services & packages'], 'rv-content' => ['Website content', 'Website content'], 'rv-contact' => ['Contact & other', 'Contact & other']] as $sectionId => [$heading, $navLabel]) {
        if ($guided || ! empty($answerSummary[$heading]) || ($sectionId === 'rv-content' && $plan)) {
            $navItems[$sectionId] = $navLabel;
        }
    }
@endphp

<div class="rv" data-testid="review-page">
    <div class="mb-3">
        <a class="btn btn-link p-0 d-inline-flex align-items-center gap-1 text-label mb-2" data-testid="review-back" href="{{ route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $previousStepKey]) }}">
            <x-ds-icon name="arrow-left" size="16" aria-hidden="true" /> Back
        </a>
        <ol class="rv-steps" aria-label="Setup progress">
            <li class="is-done"><span class="rv-step-dot" aria-hidden="true">&#10003;</span> Answer questions</li>
            <li class="is-current" aria-current="step"><span class="rv-step-dot" aria-hidden="true">2</span> Review &amp; choose a look</li>
            <li><span class="rv-step-dot" aria-hidden="true">3</span> Generate draft</li>
        </ol>
        <h1 class="h4 mb-1">Review your answers</h1>
        <p class="text-caption mb-0">You're almost there. Review your details, pick a design, and we'll build your website.</p>
    </div>

    <x-flash-alert class="mb-3" />

    @if ($lastAttemptFailed)
        <div class="rv-alert rv-alert--danger mb-3" role="alert" data-testid="generation-failed-notice">
            <span>Your website wasn't generated this time. Your answers are saved &mdash; nothing was lost.</span>
            @include('customer.business.website.wizard.steps._review-generate', $generateData + ['testid' => 'generate-website-retry'])
        </div>
    @elseif (! $generationAvailable)
        <div class="rv-alert rv-alert--info mb-3" role="alert" data-testid="generation-unavailable-notice">
            Website generation isn't available in this environment right now. You can still review and save your answers, and generate once it is.
        </div>
    @endif

    <div class="rv-layout">
        <div class="rv-main">
            @if ($guided)
                @php
                    $c = $guidedReview['counts'];
                @endphp
                <section class="rv-card" id="rv-glance" data-testid="guided-review" aria-labelledby="rv-glance-title">
                    <div class="rv-card-head">
                        <span class="rv-num" aria-hidden="true">{{ $nextNumber() }}</span>
                        <h2 class="rv-title" id="rv-glance-title">Your website at a glance</h2>
                    </div>
                    <div class="rv-card-body">
                        <dl class="rv-glance">
                            <div>
                                <dt>Business</dt>
                                <dd>{{ $guidedReview['business']['name'] }}@if ($guidedReview['business']['base'])<div class="rv-muted small">{{ $guidedReview['business']['base'] }}</div>@endif</dd>
                            </div>
                            <div>
                                <dt>Contact</dt>
                                <dd>{{ collect([$guidedReview['business']['phone'], $guidedReview['business']['email']])->filter()->implode(' · ') ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt>Pricing</dt>
                                <dd>{{ $review['contact']['pricing'] ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt>Booking button</dt>
                                <dd>{{ $guidedReview['booking'] ?? '—' }}</dd>
                            </div>
                        </dl>
                        <div class="rv-metrics">
                            <div class="rv-metric"><strong data-count="booths">{{ $c['booths'] }}</strong><span>{{ \Illuminate\Support\Str::plural('Booth', $c['booths']) }}</span></div>
                            <div class="rv-metric"><strong data-count="events">{{ $c['events'] }}</strong><span>Event types</span></div>
                            <div class="rv-metric"><strong data-count="packages">{{ $c['packages'] }}</strong><span>{{ \Illuminate\Support\Str::plural('Package', $c['packages']) }}</span></div>
                            <div class="rv-metric"><strong data-count="backdrops">{{ $c['backdrops'] }}</strong><span>{{ \Illuminate\Support\Str::plural('Backdrop', $c['backdrops']) }}</span></div>
                            <div class="rv-metric"><strong data-count="reviews">{{ $review['reviews_count'] }}</strong><span>Review {{ \Illuminate\Support\Str::plural('quote', $review['reviews_count']) }}</span></div>
                            <div class="rv-metric"><strong data-count="photos">{{ $c['photos'] }}</strong><span>Photos uploaded</span></div>
                        </div>
                    </div>
                </section>
            @endif

            @if ($hasLook)
                @include('customer.business.website.wizard.steps._review-look', ['number' => $nextNumber()])
            @endif

            @if ($guided)
                @include('customer.business.website.wizard.steps._review-sections-guided')
            @else
                @include('customer.business.website.wizard.steps._review-sections-generic')
            @endif

            <div class="rv-footer">
                @if ($previousStepKey)
                    <a class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center" href="{{ route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $previousStepKey]) }}">Back and edit</a>
                @else
                    <span></span>
                @endif
                @include('customer.business.website.wizard.steps._review-generate', $generateData + ['arrow' => true, 'testid' => 'generate-website-bottom'])
            </div>
        </div>

        <aside class="rv-aside" aria-label="Generation readiness and page navigation">
            <div class="rv-side-card rv-side-ready" data-testid="ready-card">
                <h2>{{ $generationAvailable ? 'Ready to generate' : 'Generation unavailable' }}</h2>
                <p class="small rv-muted mb-0">{{ $generationAvailable ? 'You can generate your first draft now. Nothing is published until you choose to publish it.' : 'You can still review and save your answers.' }}</p>
                <ul class="rv-checks">
                    <li><span class="rv-ok" aria-hidden="true">&#10003;</span> Answers complete</li>
                    @if ($selectedDesign)
                        <li><span class="rv-ok" aria-hidden="true">&#10003;</span> Look chosen: {{ $selectedDesign->label }}</li>
                    @endif
                    @if ($guided)
                        @if ($photosMissing > 0)
                            <li data-testid="ready-photos"><span class="rv-warn" aria-hidden="true">!</span> {{ $photosMissing }} {{ \Illuminate\Support\Str::plural('photo', $photosMissing) }} needed to publish (not to generate)</li>
                        @else
                            <li data-testid="ready-photos"><span class="rv-ok" aria-hidden="true">&#10003;</span> Photos ready to publish</li>
                        @endif
                    @endif
                    @if ($hasLook && empty($privacyNoticeUrl))
                        <li><span class="rv-warn" aria-hidden="true">!</span> No privacy notice link yet</li>
                    @endif
                </ul>
                @include('customer.business.website.wizard.steps._review-generate', $generateData + ['block' => true, 'testid' => 'generate-website-side'])
            </div>

            @if ($guided)
                <div class="rv-side-card rv-side-card--warm rv-side-photos" data-testid="photo-requirements">
                    <h2>Photos needed to publish</h2>
                    <p class="small rv-muted mb-2">
                        {{ $photosMissing > 0 ? 'Generating a draft never needs them; publishing does. Add a real photo for each item.' : 'Your homepage, every booth and every backdrop have a real photo.' }}
                    </p>
                    @foreach ($checklist as $item)
                        <div class="rv-photo" data-photo-item data-done="{{ $item['done'] ? '1' : '0' }}">
                            @if ($item['thumb'])
                                <img class="rv-photo-thumb" src="{{ $item['thumb'] }}" alt="">
                            @else
                                <span class="rv-photo-thumb" aria-hidden="true"></span>
                            @endif
                            <span class="rv-photo-title">{{ $item['title'] }}</span>
                            @if ($item['done'])
                                <span class="rv-photo-done rv-ok"><span aria-hidden="true">&#10003;</span> Added</span>
                            @else
                                <a class="rv-photo-add" href="{{ $stepUrl($item['step']) }}" aria-label="Add photo: {{ $item['title'] }}">Add photo</a>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            <nav class="rv-nav" aria-label="On this page">
                <h2>On this page</h2>
                @foreach ($navItems as $targetId => $navLabel)
                    <a href="#{{ $targetId }}" data-rv-nav="{{ $targetId }}">{{ $navLabel }}</a>
                @endforeach
            </nav>
        </aside>
    </div>
</div>

<script>
    (function () {
        var root = document.querySelector('.rv');
        if (!root) { return; }
        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        // Template previews: scale the 1280px page down to the card width.
        var frames = root.querySelectorAll('[data-template-iframe]');
        function fitAll() { frames.forEach(function (frame) { frame.style.transform = 'scale(' + (frame.parentElement.clientWidth / 1280) + ')'; }); }
        fitAll();
        window.addEventListener('resize', fitAll);

        // Saving the look reloads the page; put the customer back where they were instead of at the top.
        var KEY = 'review-scroll:' + location.pathname;
        var look = document.getElementById('look-form');
        if (look) {
            look.addEventListener('submit', function () { try { sessionStorage.setItem(KEY, String(window.scrollY)); } catch (e) {} });
        }
        try {
            var saved = sessionStorage.getItem(KEY);
            if (saved !== null) { sessionStorage.removeItem(KEY); window.scrollTo(0, parseInt(saved, 10) || 0); }
        } catch (e) {}

        // "On this page": smooth scroll without a reload, plus the section currently in view.
        var links = Array.prototype.slice.call(root.querySelectorAll('[data-rv-nav]'));
        links.forEach(function (link) {
            link.addEventListener('click', function (event) {
                var target = document.getElementById(link.getAttribute('data-rv-nav'));
                if (!target) { return; }
                event.preventDefault();
                target.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
                target.setAttribute('tabindex', '-1');
                target.focus({ preventScroll: true });
                setActive(target.id);
            });
        });

        function setActive(id) {
            links.forEach(function (link) {
                var on = link.getAttribute('data-rv-nav') === id;
                link.classList.toggle('is-active', on);
                if (on) { link.setAttribute('aria-current', 'location'); } else { link.removeAttribute('aria-current'); }
            });
        }

        var sections = links.map(function (link) { return document.getElementById(link.getAttribute('data-rv-nav')); }).filter(Boolean);
        function spy() {
            var current = sections.length ? sections[0].id : null;
            sections.forEach(function (section) { if (section.getBoundingClientRect().top <= 140) { current = section.id; } });
            if (current) { setActive(current); }
        }
        spy();
        window.addEventListener('scroll', spy, { passive: true });
    })();
</script>
