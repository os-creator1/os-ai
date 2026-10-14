{{--
    The four answer sections of the Review page for a guided (Photo Booth) setup: compact rows of SAVED values, each with
    an Edit link that opens the exact questionnaire screen and returns here after saving. Nothing here is asked again,
    defaulted or generated. Inherits $review, $guidedReview, $stepUrl, $nextNumber from _review-page.
--}}
@php
    $about = $review['about'];
    $services = $review['services'];
    $content = $review['content'];
    $contact = $review['contact'];
    $biz = $guidedReview['business'];
@endphp

{{-- About your business --}}
<section class="rv-card" id="rv-about" data-testid="review-about" aria-labelledby="rv-about-title">
    <div class="rv-card-head">
        <span class="rv-num" aria-hidden="true">{{ $nextNumber() }}</span>
        <h2 class="rv-title" id="rv-about-title">About your business</h2>
    </div>
    <div class="rv-card-body">
        <div class="rv-row" data-summary-block>
            <div class="rv-row-label">Business</div>
            <div class="rv-row-value">{{ $biz['name'] }}@if ($contact['phone'] || $contact['email'])<div class="rv-muted small">{{ collect([$contact['phone'], $contact['email']])->filter()->implode(' · ') }}</div>@endif</div>
            <a class="rv-edit" href="{{ $stepUrl('business_name') }}" aria-label="Edit business details">Edit</a>
        </div>
        <div class="rv-row" data-summary-block>
            <div class="rv-row-label">Where you're based</div>
            <div class="rv-row-value">{{ $about['based'] ?: '—' }}</div>
            <a class="rv-edit" href="{{ $stepUrl('base_location') }}" aria-label="Edit where you're based">Edit</a>
        </div>
        <div class="rv-row" data-summary-block>
            <div class="rv-row-label">Where you work</div>
            <div class="rv-row-value">
                @if ($about['areas'] === [])
                    <span class="rv-muted">No service areas yet</span>
                @else
                    <div class="rv-chips">
                        @foreach (array_slice($about['areas'], 0, 8) as $area)<span class="rv-chip">{{ $area }}</span>@endforeach
                        @if (count($about['areas']) > 8)<span class="rv-chip rv-muted">+{{ count($about['areas']) - 8 }} more</span>@endif
                    </div>
                @endif
            </div>
            <a class="rv-edit" href="{{ $stepUrl('service_area_cities') }}" aria-label="Edit where you work">Edit</a>
        </div>
        <div class="rv-row" data-summary-block>
            <div class="rv-row-label">Style</div>
            <div class="rv-row-value">{{ $about['style'] ?: '—' }}</div>
            <a class="rv-edit" href="{{ $stepUrl('brand_personality') }}" aria-label="Edit your style">Edit</a>
        </div>
        <div class="rv-row" data-summary-block>
            <div class="rv-row-label">What makes you special</div>
            <div class="rv-row-value">
                @if ($about['advantages'] === [])
                    <span class="rv-muted">Nothing selected</span>
                @else
                    <div class="rv-chips">@foreach ($about['advantages'] as $advantage)<span class="rv-chip">{{ $advantage }}</span>@endforeach</div>
                @endif
            </div>
            <a class="rv-edit" href="{{ $stepUrl('advantages') }}" aria-label="Edit what makes you special">Edit</a>
        </div>
        <div class="rv-row" data-summary-block>
            <div class="rv-row-label">Customer reviews</div>
            <div class="rv-row-value">
                @forelse (array_slice($about['reviews'], 0, 1) as $quote)
                    <div>&ldquo;{{ \Illuminate\Support\Str::limit($quote['quote'], 140) }}&rdquo;</div>
                    @if ($quote['author'])<div class="rv-muted small">{{ $quote['author'] }}</div>@endif
                    @if (count($about['reviews']) > 1)<div class="rv-muted small">+{{ count($about['reviews']) - 1 }} more</div>@endif
                @empty
                    <span class="rv-muted">No reviews added &mdash; that section is left out</span>
                @endforelse
            </div>
            <a class="rv-edit" href="{{ $stepUrl('testimonials') }}" aria-label="Edit customer reviews">Edit</a>
        </div>
        <div class="rv-row" data-summary-block>
            <div class="rv-row-label">About text</div>
            <div class="rv-row-value">
                @if ($about['story'] === '')
                    <span class="rv-muted">Not written yet</span>
                @elseif (mb_strlen($about['story']) <= 180)
                    {{ $about['story'] }}
                @else
                    <details class="rv-more">
                        <summary><span class="rv-story">{{ \Illuminate\Support\Str::limit($about['story'], 170) }}</span><span class="d-block mt-1">Show all</span></summary>
                        <div class="mt-2" style="white-space: pre-line;">{{ $about['story'] }}</div>
                    </details>
                @endif
            </div>
            <a class="rv-edit" href="{{ $stepUrl('about_story') }}" aria-label="Edit about text">Edit</a>
        </div>
    </div>
</section>

{{-- Services & packages --}}
<section class="rv-card" id="rv-services" data-testid="review-services" aria-labelledby="rv-services-title">
    <div class="rv-card-head">
        <span class="rv-num" aria-hidden="true">{{ $nextNumber() }}</span>
        <h2 class="rv-title" id="rv-services-title">Services &amp; packages</h2>
    </div>
    <div class="rv-card-body">
        <div class="rv-row" data-summary-block>
            <div class="rv-row-label">Booth experiences</div>
            <div class="rv-row-value">
                @if ($services['booths'] === [])
                    <span class="rv-muted">No booth experiences yet</span>
                @else
                    <div class="rv-tiles">
                        @foreach ($services['booths'] as $booth)
                            <div class="rv-tile" data-booth-tile>
                                @if ($booth['thumb'])
                                    <img src="{{ $booth['thumb'] }}" alt="{{ $booth['name'] }}" loading="lazy">
                                @else
                                    <div class="rv-tile-ph">Photo needed</div>
                                @endif
                                <div class="rv-tile-name">{{ $booth['name'] }}</div>
                                @if (! $booth['visible'])<div class="rv-muted">Hidden on website</div>@endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
            <a class="rv-edit" href="{{ $stepUrl('booth_services') }}" aria-label="Edit booth experiences">Edit</a>
        </div>
        <div class="rv-row" data-summary-block>
            <div class="rv-row-label">Event types</div>
            <div class="rv-row-value">
                @if ($services['events'] === [])
                    <span class="rv-muted">None selected</span>
                @else
                    <div class="rv-chips">@foreach ($services['events'] as $event)<span class="rv-chip">{{ $event }}</span>@endforeach</div>
                @endif
            </div>
            <a class="rv-edit" href="{{ $stepUrl('event_types') }}" aria-label="Edit event types">Edit</a>
        </div>
        <div class="rv-row" data-summary-block>
            <div class="rv-row-label">Packages</div>
            <div class="rv-row-value">
                @if ($services['packages'] === [])
                    <span class="rv-muted">{{ $services['pricing'] === 'Custom quote' ? 'Custom quote — no packages shown' : 'No packages selected' }}</span>
                @else
                    <table class="rv-table" data-testid="review-packages">
                        @foreach ($services['packages'] as $package)
                            <tr><td>{{ $package['name'] }}</td><td>{{ $package['price'] }}</td><td class="rv-muted">{{ $package['features'] }}</td></tr>
                        @endforeach
                    </table>
                @endif
                @if ($services['package_notice'])<div class="rv-notice" data-summary-notice>{{ $services['package_notice'] }}</div>@endif
            </div>
            <a class="rv-edit" href="{{ $stepUrl('packages') }}" aria-label="Edit packages">Edit</a>
        </div>
        <div class="rv-row" data-summary-block>
            <div class="rv-row-label">Extras</div>
            <div class="rv-row-value">
                @if ($services['addons'] === [])
                    <span class="rv-muted">None</span>
                @else
                    <div class="rv-chips">@foreach ($services['addons'] as $addon)<span class="rv-chip">{{ $addon }}</span>@endforeach</div>
                @endif
            </div>
            <a class="rv-edit" href="{{ $stepUrl('addons') }}" aria-label="Edit extras">Edit</a>
        </div>
        <div class="rv-row" data-summary-block>
            <div class="rv-row-label">Backdrops</div>
            <div class="rv-row-value">
                @if (! $services['offers_backdrops'])
                    <span class="rv-muted">You don't offer different backdrops</span>
                @elseif ($services['backdrops'] === [])
                    <span class="rv-muted">No backdrops added yet</span>
                @else
                    <div class="rv-tiles">
                        @foreach ($services['backdrops'] as $backdrop)
                            <div class="rv-tile" data-backdrop-tile>
                                @if ($backdrop['thumb'])
                                    <img src="{{ $backdrop['thumb'] }}" alt="{{ $backdrop['name'] }}" loading="lazy">
                                @else
                                    <div class="rv-tile-ph">Photo needed</div>
                                @endif
                                <div class="rv-tile-name">{{ $backdrop['name'] }}</div>
                                <div class="rv-muted">{{ $backdrop['available'] ? 'Available' : 'Not available' }}@if ($backdrop['category']) &middot; {{ $backdrop['category'] }}@endif</div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
            <a class="rv-edit" href="{{ $stepUrl($services['offers_backdrops'] ? 'backdrops' : 'offers_backdrops') }}" aria-label="Edit backdrops">Edit</a>
        </div>
    </div>
</section>

{{-- Website content --}}
<section class="rv-card" id="rv-content" data-testid="review-content" aria-labelledby="rv-content-title">
    <div class="rv-card-head">
        <span class="rv-num" aria-hidden="true">{{ $nextNumber() }}</span>
        <h2 class="rv-title" id="rv-content-title">Website content</h2>
    </div>
    <div class="rv-card-body">
        <div class="rv-row" data-summary-block>
            <div class="rv-row-label">Event photos</div>
            <div class="rv-row-value">
                @if ($content['gallery'] === [])
                    <span class="rv-muted">No event photos yet</span>
                @else
                    <div class="rv-tiles">
                        @foreach ($content['gallery'] as $photo)
                            <div class="rv-tile"><img src="{{ $photo['thumb'] }}" alt="{{ $photo['label'] }}" loading="lazy"></div>
                        @endforeach
                    </div>
                    @if ($content['gallery_total'] > count($content['gallery']))<div class="rv-muted small">+{{ $content['gallery_total'] - count($content['gallery']) }} more</div>@endif
                @endif
            </div>
            <a class="rv-edit" href="{{ $stepUrl('gallery') }}" aria-label="Edit event photos">Edit</a>
        </div>
        <div class="rv-row" data-summary-block>
            <div class="rv-row-label">Questions to answer</div>
            <div class="rv-row-value">
                @if ($content['faqs'] === [])
                    <span class="rv-muted">No questions added</span>
                @else
                    <ol class="rv-faq">
                        @foreach (array_slice($content['faqs'], 0, 5) as $question)<li>{{ $question }}</li>@endforeach
                    </ol>
                    @if (count($content['faqs']) > 5)<div class="rv-muted small">+{{ count($content['faqs']) - 5 }} more</div>@endif
                @endif
            </div>
            <a class="rv-edit" href="{{ $stepUrl('faq_items') }}" aria-label="Edit website questions">Edit</a>
        </div>
        @if ($plan)
            <div class="rv-row" data-testid="website-plan">
                <div class="rv-row-label">Website plan</div>
                <div class="rv-row-value">
                    <div data-testid="plan-count">{{ $plan['budget']['planned'] }} pages will be built <span class="rv-muted">(a website is built with at most {{ $plan['budget']['max'] }})</span></div>
                    <details class="rv-more mt-1">
                        <summary>See the pages</summary>
                        @include('customer.business.website.wizard.steps._plan')
                    </details>
                </div>
                <span></span>
            </div>
        @endif
    </div>
</section>

{{-- Contact & other --}}
<section class="rv-card" id="rv-contact" data-testid="review-contact" aria-labelledby="rv-contact-title">
    <div class="rv-card-head">
        <span class="rv-num" aria-hidden="true">{{ $nextNumber() }}</span>
        <h2 class="rv-title" id="rv-contact-title">Contact &amp; other</h2>
    </div>
    <div class="rv-card-body">
        <div class="rv-row" data-summary-block>
            <div class="rv-row-label">Pricing</div>
            <div class="rv-row-value">{{ $contact['pricing'] ?: '—' }}</div>
            <a class="rv-edit" href="{{ $stepUrl('pricing_strategy') }}" aria-label="Edit pricing">Edit</a>
        </div>
        <div class="rv-row" data-summary-block>
            <div class="rv-row-label">Different backdrops</div>
            <div class="rv-row-value">{{ $contact['offers_backdrops'] ? 'Yes' : 'No' }}</div>
            <a class="rv-edit" href="{{ $stepUrl('offers_backdrops') }}" aria-label="Edit different backdrops">Edit</a>
        </div>
        <div class="rv-row" data-summary-block>
            <div class="rv-row-label">Booking button</div>
            <div class="rv-row-value">{{ $contact['booking'] ?: '—' }}</div>
            <a class="rv-edit" href="{{ $stepUrl('primary_cta') }}" aria-label="Edit booking button">Edit</a>
        </div>
        <div class="rv-row" data-summary-block>
            <div class="rv-row-label">Quote form asks for</div>
            <div class="rv-row-value">
                @if ($contact['form_fields'] === [])
                    <span class="rv-muted">Nothing selected</span>
                @else
                    <div class="rv-chips">@foreach ($contact['form_fields'] as $field)<span class="rv-chip">{{ $field }}</span>@endforeach</div>
                @endif
            </div>
            <a class="rv-edit" href="{{ $stepUrl('contact_form_fields') }}" aria-label="Edit quote form fields">Edit</a>
        </div>
        <div class="rv-row" data-summary-block>
            <div class="rv-row-label">Contact methods</div>
            <div class="rv-row-value">
                @if ($contact['phone'] === '' && $contact['email'] === '')
                    <span class="rv-muted">None</span>
                @else
                    @if ($contact['phone'])<div>{{ $contact['phone'] }}</div>@endif
                    @if ($contact['email'])<div>{{ $contact['email'] }}</div>@endif
                @endif
            </div>
            <a class="rv-edit" href="{{ $stepUrl('business_name') }}" aria-label="Edit contact methods">Edit</a>
        </div>
        <div class="rv-row" data-summary-block>
            <div class="rv-row-label">Photo rights</div>
            <div class="rv-row-value">
                @if ($contact['rights_confirmed'])
                    <span class="rv-ok">&#10003;</span> I have the right to use every photo I upload, and each photo accurately shows the booth, backdrop or event it is attached to.
                @else
                    <span class="rv-muted">Not confirmed yet</span>
                @endif
            </div>
            <a class="rv-edit" href="{{ $stepUrl('photo_rights') }}" aria-label="Edit photo rights confirmation">Edit</a>
        </div>
    </div>
</section>
