{{--
    White Label -> Landing Page: the guided website editor.

    Ten screens (left), the questions for the current screen (middle) and a live preview of the saved draft (right).
    Every control saves in place (JSON) and refreshes the preview iframe; nothing reloads the page. Which screen you are
    on is saved server-side, so a reload or a Back/Next never loses where you were. All validation and authority is
    enforced by the server; disabled controls for non-owners are presentation only.
--}}
@php
    $e = $editor;
    $v = fn (string $path, $default = null) => $e['values'][$path] ?? $default;
    $ctaField = fn (string $path, string $label) => ['path' => $path, 'label' => $label, 'type' => 'choice', 'options' => $e['ctaTargets'], 'value' => $v($path, 'pricing'), 'default' => 'pricing', 'overridden' => array_key_exists($path, $e['values'])];
    $statusBadge = $e['isLive'] ? ($e['unpublishedChanges'] ? ['warning', 'Published · unpublished changes'] : ['success', 'Published']) : (($e['status'] ?? '') === 'unpublished' ? ['neutral', 'Unpublished'] : ['neutral', 'Draft']);
    $checkByStep = collect($e['checklist'])->groupBy('step')->map(fn ($items) => $items->contains('status', 'fail') ? 'fail' : ($items->contains('status', 'warn') ? 'warn' : 'pass'));
    $urls = [
        'save' => route('customer.workspaces.agency.site.save', $agencyWorkspace->uid),
        'step' => route('customer.workspaces.agency.site.step', $agencyWorkspace->uid),
        'reset' => route('customer.workspaces.agency.site.reset-group', $agencyWorkspace->uid),
        'booking' => route('customer.workspaces.agency.site.booking-type', $agencyWorkspace->uid),
        'publish' => route('customer.workspaces.agency.site.publish', $agencyWorkspace->uid),
        'unpublish' => route('customer.workspaces.agency.site.unpublish', $agencyWorkspace->uid),
        'preview' => route('customer.workspaces.agency.site.preview', [$agencyWorkspace->uid, 'home']),
    ];
@endphp

<div id="wl-site" data-role="landing-editor" data-urls='@json($urls)' data-step="{{ $e['step'] }}" data-can-edit="{{ $canEdit ? '1' : '0' }}" data-csrf="{{ csrf_token() }}" data-pages='@json($e['pages'])'>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2" data-role="landing-status">
        <x-badge :variant="$statusBadge[0]"><span data-role="site-status">{{ $statusBadge[1] }}</span></x-badge>
        <span class="text-caption" id="wl-save-state" role="status" aria-live="polite" data-role="save-state">{{ $canEdit ? 'All changes saved' : 'Read only — only the agency owner can edit' }}</span>
        <div class="ms-auto d-flex gap-1">
            <a class="btn btn-outline-secondary btn-sm" href="{{ $urls['preview'] }}" target="_blank" rel="noopener" data-role="preview-website">Preview website</a>
            @if ($canEdit)
                <button type="button" class="btn btn-outline-secondary btn-sm" id="wl-save-draft" data-role="save-draft">Save draft</button>
                <button type="button" class="btn btn-primary btn-sm" id="wl-publish" data-role="publish">{{ $e['isLive'] ? 'Update website' : 'Publish website' }}</button>
                @if ($e['isLive'])<button type="button" class="btn btn-outline-danger btn-sm" id="wl-unpublish" data-role="unpublish">Unpublish</button>@endif
            @endif
        </div>
    </div>
    <div id="wl-publish-result" class="mb-2" role="alert" style="display:none"></div>

    <div class="d-lg-none mb-2" role="tablist">
        <button type="button" class="btn btn-sm btn-primary" data-mobile-tab="edit">Edit</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-mobile-tab="preview">Preview</button>
    </div>

    <div class="row g-2">
        {{-- Navigation --}}
        <nav class="col-12 col-lg-2" aria-label="Website setup screens" data-pane="edit">
            <ol class="list-unstyled mb-0" data-role="step-nav">
                @foreach ($e['steps'] as $n => $label)
                    <li>
                        <button type="button" class="btn btn-sm w-100 text-start mb-1 {{ $n === $e['step'] ? 'btn-primary' : 'btn-outline-secondary' }}" data-step-button="{{ $n }}" @if ($n === $e['step']) aria-current="step" @endif>
                            {{ $n }}. {{ $label }}
                            @if (($checkByStep[$n] ?? null) === 'fail')<span class="text-danger" aria-label="needs attention">●</span>@elseif (($checkByStep[$n] ?? null) === 'warn')<span class="text-warning" aria-label="check">●</span>@endif
                        </button>
                    </li>
                @endforeach
            </ol>
        </nav>

        {{-- Questions --}}
        <div class="col-12 col-lg-5" data-pane="edit">
            {{-- ============ 1 · Business & website --}}
            <section data-step-panel="1" {{ $e['step'] === 1 ? '' : 'hidden' }}>
                <x-card title="Business and website setup">
                    <p class="text-caption">Your website name, logo and colours come from the <a href="{{ route('customer.workspaces.agency.white-label.show', $agencyWorkspace->uid) }}">Branding tab</a>: currently <strong>{{ $e['brand']['name'] }}</strong>{{ $e['brand']['saved'] ? '' : ' (not saved yet)' }}. A complete starter website already exists and updates as you answer.</p>
                    @include('customer.agency.white-label._group', ['group' => $e['groups']['setup'], 'canEdit' => $canEdit])
                    <p class="text-caption mb-0" data-role="domain-note">Your site is hosted at <a href="{{ $e['hostedUrl'] }}" target="_blank" rel="noopener">{{ $e['hostedUrl'] }}</a> once published. A custom marketing domain is not part of this release.</p>
                </x-card>
            </section>

            {{-- ============ 2 · Home --}}
            <section data-step-panel="2" {{ $e['step'] === 2 ? '' : 'hidden' }}>
                <x-card title="Home page — 15 sections">
                    <p class="text-caption">Switch sections off, move them, or open one to edit its words. Header and footer always show. Nothing invents customer numbers, awards or results.</p>
                    <ul class="list-unstyled mb-0" data-scope="home" data-home-list>
                        @foreach ($e['homeSections'] as $section)
                            @php $g = $e['groups'][$section['key']] ?? null; @endphp
                            <li class="border rounded mb-1" data-key="{{ $section['key'] }}" data-default-hidden="{{ in_array($section['key'], \App\Library\AgencySite\SiteSchema::HOME_HIDDEN_BY_DEFAULT, true) ? '1' : '0' }}" data-hideable="{{ $section['hideable'] ? '1' : '0' }}">
                                <div class="d-flex align-items-center gap-1 p-1">
                                    @if ($section['hideable'])
                                        <input type="checkbox" class="form-check-input mt-0" data-toggle-visible id="vis-{{ $section['key'] }}" @checked($section['visible']) @disabled(! $canEdit) aria-label="Show {{ $section['label'] }}">
                                    @else
                                        <span class="text-muted small" style="width:1.25rem">✓</span>
                                    @endif
                                    <button type="button" class="btn btn-link text-start flex-grow-1 p-0" data-open-section>{{ $section['label'] }} @unless ($section['visible'])<span class="text-muted small">(hidden)</span>@endunless</button>
                                    @if ($canEdit && $section['movable'])
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-up aria-label="Move {{ $section['label'] }} up">↑</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-down aria-label="Move {{ $section['label'] }} down">↓</button>
                                    @endif
                                </div>
                                @if ($g)
                                    <div class="p-2 border-top" data-section-body hidden>
                                        @includeWhen($section['key'] === 'footer', 'customer.agency.white-label._legal-note')
                                        @include('customer.agency.white-label._group', ['group' => $g, 'canEdit' => $canEdit])
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </x-card>
            </section>

            {{-- ============ 3 · Product --}}
            <section data-step-panel="3" {{ $e['step'] === 3 ? '' : 'hidden' }}>
                <x-card title="Product page">
                    <p class="text-caption">These are the capabilities of the platform your customers use. Renaming or hiding a feature here only changes your marketing page; what each plan can actually access is set by the plan itself.</p>
                    @include('customer.agency.white-label._group', ['group' => $e['groups']['product'], 'canEdit' => $canEdit])
                    <h3 class="h6 mt-2">Features</h3>
                    <ul class="list-unstyled" data-scope="product" data-product-list>
                        @foreach ($e['productFeatures'] as $feature)
                            <li class="border rounded mb-1 p-2" data-key="{{ $feature['key'] }}">
                                <div class="d-flex align-items-center gap-1 mb-1">
                                    <input type="checkbox" class="form-check-input mt-0" data-toggle-visible @checked($feature['visible']) @disabled(! $canEdit) aria-label="Show {{ $feature['nav'] }}">
                                    <strong class="flex-grow-1">{{ $feature['nav'] }}</strong>
                                    <label class="small"><input type="radio" name="featured-feature" data-featured value="{{ $feature['key'] }}" @checked($feature['featured']) @disabled(! $canEdit)> shown first</label>
                                    @if ($canEdit)
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-up aria-label="Move up">↑</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-down aria-label="Move down">↓</button>
                                    @endif
                                </div>
                                @foreach ([['nav', 'Public name', 'text', 30], ['chip', 'Label above the heading', 'text', 80], ['title', 'Heading', 'text', 120], ['lead', 'Description', 'textarea', 400]] as [$part, $partLabel, $partType, $partMax])
                                    @include('customer.agency.white-label._field', ['canEdit' => $canEdit, 'field' => ['path' => 'product_features.'.$feature['key'].'.'.$part, 'label' => $partLabel, 'type' => $partType, 'max' => $partMax, 'value' => $feature[$part], 'default' => null, 'overridden' => array_key_exists('product_features.'.$feature['key'].'.'.$part, $e['values'])]])
                                @endforeach
                                @include('customer.agency.white-label._field', ['canEdit' => $canEdit, 'field' => ['path' => 'product_features.'.$feature['key'].'.bullets', 'label' => 'Bullet points', 'type' => 'strings', 'max' => 120, 'items' => 6, 'value' => $feature['bullets'], 'default' => null, 'overridden' => array_key_exists('product_features.'.$feature['key'].'.bullets', $e['values'])]])
                            </li>
                        @endforeach
                    </ul>
                    <div data-scope="fields" data-group="product-cta">@include('customer.agency.white-label._field', ['canEdit' => $canEdit, 'field' => $ctaField('x.cta.product', 'Where should the closing button lead?')])</div>
                </x-card>
            </section>

            {{-- ============ 4 · Solutions --}}
            <section data-step-panel="4" {{ $e['step'] === 4 ? '' : 'hidden' }}>
                <x-card title="Solutions page">
                    <div class="form-check mb-2" data-scope="pages-hidden">
                        <input class="form-check-input" type="checkbox" id="show-solutions" data-page-toggle="solutions" @checked($e['industryPageVisible']) @disabled(! $canEdit)>
                        <label class="form-check-label" for="show-solutions">Show the Solutions page</label>
                    </div>
                    @include('customer.agency.white-label._group', ['group' => $e['groups']['solutions'], 'canEdit' => $canEdit])
                    <div data-scope="fields" data-group="solutions-cta">@include('customer.agency.white-label._field', ['canEdit' => $canEdit, 'field' => $ctaField('x.cta.solutions', 'Where should the buttons on each industry lead?')])</div>
                </x-card>
            </section>

            {{-- ============ 5 · How it works --}}
            <section data-step-panel="5" {{ $e['step'] === 5 ? '' : 'hidden' }}>
                <x-card title="How it works">
                    <p class="text-caption">Only promise what you will really deliver. The starter wording describes the software, not any service from you.</p>
                    @include('customer.agency.white-label._group', ['group' => $e['groups']['how-it-works'], 'canEdit' => $canEdit])
                    <div data-scope="fields" data-group="how-cta">@include('customer.agency.white-label._field', ['canEdit' => $canEdit, 'field' => $ctaField('x.cta.how', 'Where should the final button lead?')])</div>
                </x-card>
            </section>

            {{-- ============ 6 · Customers --}}
            <section data-step-panel="6" {{ $e['step'] === 6 ? '' : 'hidden' }}>
                <x-card title="Customer stories">
                    <p class="text-caption" data-role="testimonial-rule">Only real stories you have permission to publish. Nothing shows until you tick “I have permission”. With none approved, the Customers page and the home section stay hidden.</p>
                    @include('customer.agency.white-label._group', ['group' => $e['groups']['customers'], 'canEdit' => $canEdit])
                    <h3 class="h6 mt-2">Testimonials</h3>
                    <div data-scope="testimonials" data-testimonials>
                        @foreach ($e['testimonials'] as $t)
                            @include('customer.agency.white-label._testimonial', ['t' => $t, 'canEdit' => $canEdit])
                        @endforeach
                    </div>
                    @if ($canEdit)<button type="button" class="btn btn-sm btn-outline-secondary" data-add-testimonial>Add testimonial</button>@endif
                    <template id="wl-testimonial-template">@include('customer.agency.white-label._testimonial', ['t' => ['customer_name' => '', 'business_name' => '', 'industry' => '', 'quote' => '', 'video' => '', 'featured' => false, 'approved' => false, 'visible' => true], 'canEdit' => true])</template>
                </x-card>
            </section>

            {{-- ============ 7 · Pricing --}}
            <section data-step-panel="7" {{ $e['step'] === 7 ? '' : 'hidden' }}>
                <x-card title="Pricing page">
                    @include('customer.agency.white-label._group', ['group' => $e['groups']['pricing'], 'canEdit' => $canEdit])
                    <h3 class="h6 mt-2">Your plans</h3>
                    <p class="text-caption">Prices, billing interval, currency and what each plan unlocks come from the plan itself and cannot be changed here. <a href="{{ route('customer.workspaces.agency.saas.stripe', $agencyWorkspace->uid) }}">Manage plans and Stripe</a>. Setup fees, location allowances and usage limits are not supported by plans yet.</p>
                    <div data-scope="plans" data-plans>
                        @forelse ($e['plans'] as $plan)
                            @php $pr = $plan['presentation']; @endphp
                            <div class="border rounded p-2 mb-1" data-plan="{{ $plan['uid'] }}">
                                <div class="d-flex align-items-center gap-1">
                                    <input type="checkbox" class="form-check-input mt-0" data-plan-visible @checked($pr['visible'] ?? true) @disabled(! $canEdit || ! $plan['published']) aria-label="Show {{ $plan['name'] }}">
                                    <strong class="flex-grow-1">{{ $plan['name'] }}</strong>
                                    @if ($canEdit)
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-up aria-label="Move up">↑</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-down aria-label="Move down">↓</button>
                                    @endif
                                </div>
                                <div class="small text-muted mb-1">
                                    {{ $plan['price'] !== null ? $plan['currency'].' '.$plan['price'].' / '.($plan['cycle'] === 'yearly' ? 'year' : 'month') : 'No price yet' }} · {{ ucfirst($plan['tier']) }} features ·
                                    @if (! $plan['published']) <span class="text-warning">Not published — publish it to show it here</span>
                                    @elseif ($plan['sellable']) <span class="text-success">Can be bought online</span>
                                    @else <span class="text-danger">Cannot be bought yet (Stripe price not verified)</span> @endif
                                </div>
                                <div class="row g-1">
                                    <div class="col-6"><label class="small mb-0">Badge</label><input class="form-control form-control-sm" maxlength="24" data-plan-badge value="{{ $pr['badge'] ?? '' }}" placeholder="{{ ($pr['featured'] ?? false) ? 'Most popular' : '' }}" @disabled(! $canEdit)></div>
                                    <div class="col-6"><label class="small mb-0">Button text</label><input class="form-control form-control-sm" maxlength="30" data-plan-cta value="{{ $pr['cta'] ?? '' }}" placeholder="Get started" @disabled(! $canEdit)></div>
                                    <div class="col-12"><label class="small mb-0">One-line description</label><input class="form-control form-control-sm" maxlength="120" data-plan-tagline value="{{ $pr['tagline'] ?? '' }}" @disabled(! $canEdit)></div>
                                    <div class="col-12"><label class="small mb-0">Included features (one per line — blank uses what the plan really includes)</label><textarea class="form-control form-control-sm" rows="3" data-plan-features placeholder="{{ implode("\n", $plan['default_features']) }}" @disabled(! $canEdit)>{{ implode("\n", $pr['features'] ?? []) }}</textarea></div>
                                    <div class="col-12"><label class="small"><input type="checkbox" data-plan-featured @checked($pr['featured'] ?? false) @disabled(! $canEdit)> Highlight as the popular plan</label></div>
                                </div>
                            </div>
                        @empty
                            <p class="text-caption" data-role="no-plans">You have no plans yet. <a href="{{ route('customer.workspaces.agency.saas.stripe', $agencyWorkspace->uid) }}">Create one</a>, bind its Stripe price and publish it.</p>
                        @endforelse
                    </div>
                </x-card>
            </section>

            {{-- ============ 8 · Book a call --}}
            <section data-step-panel="8" {{ $e['step'] === 8 ? '' : 'hidden' }}>
                <x-card title="Book a call">
                    <div id="wl-booking-status" data-role="booking-status">@include('customer.agency.white-label._booking-status', ['booking' => $e['booking']])</div>
                    @if (! $e['booking']['hasBusiness'] || ! $e['booking']['entitled'])
                        <p class="text-warning mb-0" data-role="booking-unavailable">{{ ! $e['booking']['hasBusiness'] ? 'Your agency has no business set up yet, so there is no calendar to book into.' : 'Calendar is not included in your plan.' }}</p>
                    @else
                        <h3 class="h6">Use an existing booking type</h3>
                        <div data-scope="booking" id="wl-booking-options">
                            @forelse ($e['booking']['options'] as $option)
                                <label class="d-block border rounded p-2 mb-1">
                                    <input type="radio" name="booking-type" value="{{ $option['uuid'] }}" data-booking-option @checked($e['booking']['selected'] === $option['uuid']) @disabled(! $canEdit)>
                                    <strong>{{ $option['name'] }}</strong> · {{ $option['duration'] }} min @if ($option['location'])· {{ $option['location'] }}@endif
                                    @if (! $option['active']) <span class="text-warning small">(inactive)</span>@elseif (! $option['ready']) <span class="text-warning small">({{ $option['reason'] }})</span>@else <span class="text-success small">(ready)</span>@endif
                                </label>
                            @empty
                                <p class="text-caption" data-role="no-booking-types">No booking types yet. Create one below.</p>
                            @endforelse
                        </div>
                        @if ($canEdit)
                            <h3 class="h6 mt-2">Or create one here</h3>
                            <form id="wl-booking-form" data-role="booking-create" novalidate>
                                <div class="row g-1">
                                    <div class="col-8"><label class="small mb-0" for="bk-name">Name</label><input id="bk-name" name="name" class="form-control form-control-sm" maxlength="120" value="Intro call" required></div>
                                    <div class="col-4"><label class="small mb-0" for="bk-duration">Minutes</label><input id="bk-duration" name="duration_minutes" type="number" min="5" max="480" class="form-control form-control-sm" value="30" required></div>
                                    <div class="col-6"><label class="small mb-0" for="bk-loc">Meeting location</label>
                                        <select id="bk-loc" name="meeting_location_type" class="form-select form-select-sm"><option value="">Not specified</option><option value="phone">Phone call</option><option value="in_person">In person</option><option value="custom">Custom (link or address)</option></select></div>
                                    <div class="col-6"><label class="small mb-0" for="bk-custom">Link / address (if custom)</label><input id="bk-custom" name="meeting_location_custom" class="form-control form-control-sm" maxlength="500"></div>
                                    <div class="col-12"><label class="small mb-0" for="bk-instr">Instructions for the visitor (optional)</label><input id="bk-instr" name="meeting_instructions" class="form-control form-control-sm" maxlength="2000"></div>
                                    <div class="col-12"><label class="small"><input type="checkbox" name="default_hours" value="1" checked> Make me bookable Monday–Friday, 9:00–17:00 (change this in your calendar anytime)</label></div>
                                </div>
                                <button type="submit" class="btn btn-sm btn-primary mt-1">Create and select</button>
                                <p class="text-caption mb-0">Reminders, confirmations, extra questions and detailed availability are managed in the booking type's own settings{{ $e['booking']['editUrl'] ? '' : ' after it is created' }}.</p>
                            </form>
                        @endif
                    @endif
                </x-card>
            </section>

            {{-- ============ 9 · Signup & payment --}}
            <section data-step-panel="9" {{ $e['step'] === 9 ? '' : 'hidden' }}>
                <x-card title="Signup and payment">
                    <ul class="mb-2" data-role="signup-readiness">
                        <li>Stripe account: <strong id="wl-stripe-state">{{ $e['stripe']['canCharge'] ? 'ready to take payments' : ($e['stripe']['connected'] ? 'connected, not ready to charge yet' : 'not connected') }}</strong>
                            @if (! $e['stripe']['canCharge']) — <a href="{{ route('customer.workspaces.agency.saas.stripe', $agencyWorkspace->uid) }}">connect or finish Stripe</a>@endif</li>
                        <li>Plans that can be bought online: <strong>{{ $e['stripe']['sellable'] }}</strong></li>
                    </ul>
                    <p class="text-caption">How a customer joins: they choose a plan on your site, create their account and business, and pay on Stripe through <em>your</em> Stripe account. The account is linked to you as a managed customer, and the plan's features are switched on only after Stripe confirms the payment. Failed or unfinished payments unlock nothing, and retrying never creates a second account.</p>
                    @forelse ($e['signupUrls'] as $uid => $url)
                        <p class="small mb-1">Signup link: <code>{{ $url }}</code></p>
                    @empty
                        <p class="text-caption mb-0">Signup links appear here for every plan shown on your site.</p>
                    @endforelse
                    <div data-scope="fields" data-group="signup-legal">@include('customer.agency.white-label._field', ['canEdit' => $canEdit, 'field' => ['path' => 'x.disclosure', 'label' => 'Disclosure shown on the signup page (optional)', 'type' => 'textarea', 'max' => 400, 'value' => $v('x.disclosure', ''), 'default' => '', 'overridden' => array_key_exists('x.disclosure', $e['values'])]])</div>
                    <p class="text-caption mb-0">Privacy policy and terms links are set in the Footer section of the Home screen and are required before you can publish a paid funnel.</p>
                </x-card>
            </section>

            {{-- ============ 10 · Review --}}
            <section data-step-panel="10" {{ $e['step'] === 10 ? '' : 'hidden' }}>
                <x-card title="Review, SEO and publishing">
                    <ul class="list-unstyled" id="wl-checklist" data-role="checklist">
                        @foreach ($e['checklist'] as $item)
                            @include('customer.agency.white-label._check', ['item' => $item])
                        @endforeach
                    </ul>
                    <h3 class="h6">Check every page</h3>
                    <div class="d-flex flex-wrap gap-1 mb-2">
                        @foreach ($e['pages'] as $p)<a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="{{ $p['url'] }}">{{ $p['key'] }}</a>@endforeach
                    </div>
                    @include('customer.agency.white-label._group', ['group' => $e['groups']['seo'], 'canEdit' => $canEdit])
                </x-card>
            </section>

            <div class="d-flex justify-content-between mt-1">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-step-prev>Back</button>
                <button type="button" class="btn btn-primary btn-sm" data-step-next>Next</button>
            </div>
        </div>

        {{-- Preview --}}
        <div class="col-12 col-lg-5" data-pane="preview">
            <x-card title="Live preview">
                <div class="d-flex flex-wrap gap-1 mb-1">
                    <select id="wl-preview-page" class="form-select form-select-sm" style="width:auto" aria-label="Preview page">
                        @foreach ($e['pages'] as $p)<option value="{{ $p['url'] }}">{{ ucfirst(str_replace('-', ' ', $p['key'])) }}</option>@endforeach
                    </select>
                    <div class="btn-group btn-group-sm" role="group" aria-label="Device">
                        <button type="button" class="btn btn-primary" data-device="desktop">Desktop</button>
                        <button type="button" class="btn btn-outline-secondary" data-device="mobile">Mobile</button>
                    </div>
                </div>
                <div style="overflow:auto;background:#f3f4f6;border-radius:8px;padding:8px">
                    <iframe id="wl-preview" title="Website preview" src="{{ $urls['preview'] }}" style="width:100%;height:640px;border:0;background:#fff;display:block;margin:0 auto" sandbox="allow-same-origin allow-scripts allow-popups"></iframe>
                </div>
                <p class="text-caption mb-0">This shows your saved draft. Links inside the preview work like the live site; signup and booking only complete once published.</p>
            </x-card>
        </div>
    </div>
</div>
@include('customer.agency.white-label._landing-script')
