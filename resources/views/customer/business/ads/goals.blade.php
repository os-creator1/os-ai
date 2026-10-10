{{--
    Acquisition Purpose V1 — Ads > Goals & economics.

    Where the owner says what their ads are for (a goal), how a goal connects to
    the pipeline, form and page that already exist, what a good result costs them
    and which campaigns serve it. Nothing here edits a campaign at a provider.

    "I don't know yet" is a first-class answer: it is stored as unknown, never as
    zero, and MotionGrove then says what it cannot yet claim (for example that the
    ads are profitable). Suggestions are shown labelled as suggestions and are
    never used as targets until the owner types them in.

    All strings are escaped; money is in the business currency.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Goals & economics')

@section('content')
    @include('customer.business.ads._header', [
        'title' => 'Goals & economics',
        'subtitle' => 'Tell MotionGrove what your ads are for and what a good result costs you, so it can tell you what to do next.',
        'showFreshness' => false,
    ])

    <x-flash-alert class="mb-2" />

    @if(count($rows) === 0)
        <x-card :padded="true" class="mb-2" data-role="no-goals">
            <p class="text-section-heading mb-1">No goals yet</p>
            <p class="mb-1">A goal is the thing your ads are trying to win: a new student, a hired teacher, a class booking. Add one below, then assign your campaigns to it.</p>
        </x-card>
    @endif

    @foreach($rows as $row)
        @php
            $purpose = $row['purpose'];
            $profile = $row['profile'];
            $formId = 'goal-' . $purpose->uid;
        @endphp

        <x-card :padded="true" class="mb-2" data-role="goal" data-goal="{{ $purpose->purpose_key }}">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-1 mb-1">
                <div>
                    <h2 class="h4 mb-0" data-role="goal-name">{{ $purpose->name }}</h2>
                    <p class="text-caption text-muted mb-0">The outcome this goal ends in: {{ $purpose->outcome_type }}</p>
                </div>
                <x-badge :variant="$purpose->is_active ? 'success' : 'neutral'">{{ $purpose->is_active ? 'Active' : 'Paused' }}</x-badge>
            </div>

            {{-- 1. How it connects --}}
            <p class="text-section-heading mt-2 mb-1">How it connects</p>
            <form method="POST" action="{{ route('customer.workspaces.businesses.ads.goals.links', [$workspaceUid, $businessUid, $purpose->uid]) }}" data-role="links-form">
                @csrf
                <div class="row g-1">
                    <div class="col-md-6">
                        <label class="form-label" for="{{ $formId }}-pipeline">Pipeline its leads arrive in</label>
                        <select class="form-select" id="{{ $formId }}-pipeline" name="pipeline_id" @disabled(! $canManage)>
                            <option value="">Not connected</option>
                            @foreach($pipelines as $pipeline)
                                <option value="{{ $pipeline->id }}" @selected($purpose->crm_pipeline_id === $pipeline->id)>{{ $pipeline->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="{{ $formId }}-form">Form that collects them</label>
                        <select class="form-select" id="{{ $formId }}-form" name="form_id" @disabled(! $canManage)>
                            <option value="">None</option>
                            @foreach($forms as $form)
                                <option value="{{ $form->id }}" @selected($purpose->form_id === $form->id)>{{ $form->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="{{ $formId }}-dest">Where the ad sends people</label>
                        <select class="form-select" id="{{ $formId }}-dest" name="destination_type" @disabled(! $canManage)>
                            <option value="none" @selected($purpose->destination_type === 'none')>Not chosen yet</option>
                            <option value="hosted_page" @selected($purpose->destination_type === 'hosted_page')>A page on my MotionGrove website</option>
                            <option value="external_url" @selected($purpose->destination_type === 'external_url')>A page on my existing website</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="{{ $formId }}-page">MotionGrove page</label>
                        <select class="form-select" id="{{ $formId }}-page" name="destination_page_id" @disabled(! $canManage || $pages->isEmpty())>
                            <option value="">{{ $pages->isEmpty() ? 'No MotionGrove website pages' : 'Choose a page' }}</option>
                            @foreach($pages as $page)
                                <option value="{{ $page->id }}" @selected($purpose->destination_page_id === $page->id)>{{ $page->title }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="{{ $formId }}-url">Page address on my existing website</label>
                        <input class="form-control" type="url" id="{{ $formId }}-url" name="destination_url" value="{{ $purpose->destination_url }}" placeholder="https://example.com/lessons" @disabled(! $canManage)>
                    </div>
                </div>
                @if($canManage)
                    <button type="submit" class="btn btn-sm btn-outline-primary mt-1">Save connections</button>
                @endif
            </form>

            {{-- 2. Economics --}}
            <p class="text-section-heading mt-3 mb-1">What a good result costs you</p>
            <p class="text-caption text-muted">Amounts are in {{ $currency ?: 'your business currency' }}. If you do not know a number yet, tick "I don't know yet": MotionGrove will never invent it, and will tell you what it therefore cannot claim.</p>

            <form method="POST" action="{{ route('customer.workspaces.businesses.ads.goals.economics', [$workspaceUid, $businessUid, $purpose->uid]) }}" data-role="economics-form">
                @csrf
                <div class="row g-1">
                    @foreach($row['questions'] as $q)
                        @php
                            $name = $q['key'];
                            $inputId = $formId . '-' . $name;
                            $hasRange = isset($q['suggested_min']) || isset($q['suggested_max']);
                        @endphp
                        <div class="col-md-6" data-question="{{ $name }}">
                            <label class="form-label" for="{{ $inputId }}">{{ $q['label'] }}</label>
                            @if(($q['type'] ?? '') === 'choice')
                                <select class="form-select" id="{{ $inputId }}" name="answers[{{ $name }}]" @disabled(! $canManage)>
                                    <option value="">Choose</option>
                                    @foreach(($q['options'] ?? []) as $option)
                                        <option value="{{ $option }}" @selected($q['value'] === $option)>{{ ucfirst(str_replace('_', '-', $option)) }}</option>
                                    @endforeach
                                </select>
                            @elseif(($q['type'] ?? '') === 'text')
                                <input class="form-control" type="text" maxlength="200" id="{{ $inputId }}" name="answers[{{ $name }}]" value="{{ $q['value'] }}" @disabled(! $canManage)>
                            @else
                                <input class="form-control" type="number" min="0" step="{{ ($q['type'] ?? '') === 'money' ? '0.01' : (($q['type'] ?? '') === 'percent' ? '0.1' : '1') }}"
                                       @if(($q['type'] ?? '') === 'percent') max="100" @endif
                                       id="{{ $inputId }}" name="answers[{{ $name }}]" value="{{ $q['value'] }}"
                                       @disabled(! $canManage || $q['unknown'])>
                            @endif
                            <div class="form-check mt-50">
                                <input class="form-check-input" type="checkbox" id="{{ $inputId }}-unknown" name="unknown[{{ $name }}]" value="1" @checked($q['unknown']) @disabled(! $canManage)>
                                <label class="form-check-label text-caption" for="{{ $inputId }}-unknown">I don't know yet</label>
                            </div>
                            @if(! empty($q['help']))
                                <p class="text-caption text-muted mb-0">{{ $q['help'] }}</p>
                            @endif
                            @if($hasRange)
                                <p class="text-caption text-muted mb-0">Typical range: {{ $q['suggested_min'] ?? '' }} to {{ $q['suggested_max'] ?? '' }}. A guide only.</p>
                            @endif
                        </div>
                    @endforeach
                </div>
                @if($canManage)
                    <button type="submit" class="btn btn-sm btn-primary mt-1" data-role="save-economics">Save numbers</button>
                @endif
            </form>

            {{-- 3. What those numbers add up to --}}
            <div class="mt-2" data-role="economics-summary">
                @php
                    $fmt = static fn (?int $micros): string => $micros === null ? 'Not set' : ($currency !== '' ? $currency . ' ' : '') . number_format($micros / 1_000_000, 2);
                @endphp
                <dl class="row mb-1">
                    <dt class="col-sm-5">Target cost per qualified lead</dt><dd class="col-sm-7" data-summary="target-cpl">{{ $fmt($profile->targetCplMicros) }}</dd>
                    <dt class="col-sm-5">Highest cost per qualified lead</dt><dd class="col-sm-7">{{ $fmt($profile->hardCplMicros) }}</dd>
                    <dt class="col-sm-5">Target cost per {{ $purpose->label('outcome') }}</dt><dd class="col-sm-7" data-summary="target-cac">{{ $fmt($profile->targetCacMicros) }}</dd>
                    <dt class="col-sm-5">Highest cost per {{ $purpose->label('outcome') }}</dt><dd class="col-sm-7">{{ $fmt($profile->hardCacMicros) }}</dd>
                    <dt class="col-sm-5">Contribution per {{ $purpose->label('person') }} over their life</dt>
                    <dd class="col-sm-7" data-summary="ltv">{{ $profile->contributionLtvMicros === null ? 'Unknown' : $fmt($profile->contributionLtvMicros) }}</dd>
                </dl>
                @foreach($profile->derived as $line)
                    <p class="text-caption mb-50" data-role="derived-line"><strong>{{ $line['label'] }}:</strong> {{ $line['value'] }}@if($line['note']) <span class="text-muted">&mdash; {{ $line['note'] }}</span>@endif</p>
                @endforeach
                @if($profile->contributionLtvMicros === null)
                    <p class="text-caption text-muted mb-50" data-role="no-profit-claim">Until the price, direct cost and typical length are known, MotionGrove can say whether your ads deliver leads and customers at a cost you set, but not whether they are profitable.</p>
                @endif
                @if($profile->suggestions !== [])
                    <div class="mt-1" data-role="suggestions">
                        <p class="text-caption mb-50"><strong>Suggestions (not used until you enter them above):</strong></p>
                        @foreach($profile->suggestions as $suggestion)
                            <p class="text-caption mb-50">{{ $suggestion['label'] }}: <strong>{{ $fmt($suggestion['value_micros']) }}</strong> <span class="text-muted">&mdash; {{ $suggestion['basis'] }}</span></p>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- 4. Campaigns --}}
            <p class="text-section-heading mt-3 mb-1">Campaigns that serve this goal</p>
            @php
                $assigned = collect($row['googleCampaigns'])->map(fn ($c) => ['provider' => 'google', 'label' => 'Google', 'uid' => $c->uid, 'name' => $c->name])
                    ->merge(collect($row['metaCampaigns'])->map(fn ($c) => ['provider' => 'meta', 'label' => 'Meta', 'uid' => $c->uid, 'name' => $c->name]));
            @endphp
            @forelse($assigned as $campaign)
                <form method="POST" action="{{ route('customer.workspaces.businesses.ads.goals.campaigns', [$workspaceUid, $businessUid]) }}" class="d-flex align-items-center gap-1 mb-50" data-role="assigned-campaign">
                    @csrf
                    <input type="hidden" name="provider" value="{{ $campaign['provider'] }}">
                    <input type="hidden" name="campaign_uid" value="{{ $campaign['uid'] }}">
                    <x-badge variant="neutral">{{ $campaign['label'] }}</x-badge>
                    <span>{{ $campaign['name'] }}</span>
                    @if($canManage)
                        <button type="submit" class="btn btn-sm btn-flat-secondary">Unassign</button>
                    @endif
                </form>
            @empty
                <p class="text-muted mb-1" data-role="no-assigned">No campaign is assigned to this goal yet.</p>
            @endforelse

            @if($canManage && $purpose->is_active && ($unassigned['google']->isNotEmpty() || $unassigned['meta']->isNotEmpty()))
                <form method="POST" action="{{ route('customer.workspaces.businesses.ads.goals.campaigns', [$workspaceUid, $businessUid]) }}" class="d-flex align-items-end gap-1 flex-wrap mt-1" data-role="assign-form">
                    @csrf
                    <input type="hidden" name="purpose_uid" value="{{ $purpose->uid }}">
                    <div>
                        <label class="form-label" for="{{ $formId }}-assign">Assign a campaign</label>
                        <select class="form-select" id="{{ $formId }}-assign" onchange="var o=this.options[this.selectedIndex];this.form.provider.value=o.dataset.provider||'';this.form.campaign_uid.value=o.value;">
                            <option value="">Choose a campaign</option>
                            @foreach($unassigned['google'] as $c)
                                <option value="{{ $c->uid }}" data-provider="google">Google &middot; {{ $c->name }}</option>
                            @endforeach
                            @foreach($unassigned['meta'] as $c)
                                <option value="{{ $c->uid }}" data-provider="meta">Meta &middot; {{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <input type="hidden" name="provider" value="">
                    <input type="hidden" name="campaign_uid" value="">
                    <button type="submit" class="btn btn-sm btn-outline-primary">Assign</button>
                </form>
            @endif

            {{-- 5. Guidance --}}
            @if(! empty($purpose->guidance))
                <details class="mt-2" data-role="guidance">
                    <summary class="fw-semibold">Strategy guidance for this goal</summary>
                    @foreach($purpose->guidance as $item)
                        <p class="mb-50 mt-1"><strong>{{ $item['title'] }}.</strong> {{ $item['body'] }}</p>
                    @endforeach
                    <p class="text-caption text-muted">Starting points, not rules. Your own results decide.</p>
                </details>
            @endif

            @if($canManage)
                <form method="POST" action="{{ route('customer.workspaces.businesses.ads.goals.toggle', [$workspaceUid, $businessUid, $purpose->uid]) }}" class="mt-2">
                    @csrf
                    <input type="hidden" name="active" value="{{ $purpose->is_active ? 0 : 1 }}">
                    <button type="submit" class="btn btn-sm btn-flat-secondary">{{ $purpose->is_active ? 'Pause this goal' : 'Resume this goal' }}</button>
                </form>
            @endif
        </x-card>
    @endforeach

    @if($canManage)
        <x-card :padded="true" class="mb-2" data-role="add-goal">
            <p class="text-section-heading mb-1">Add a goal</p>
            <form method="POST" action="{{ route('customer.workspaces.businesses.ads.goals.store', [$workspaceUid, $businessUid]) }}" class="row g-1 align-items-end">
                @csrf
                <div class="col-md-5">
                    <label class="form-label" for="new-goal-name">What are these ads for?</label>
                    <input class="form-control" id="new-goal-name" name="name" maxlength="120" placeholder="For example: New customers" required>
                </div>
                <div class="col-md-5">
                    <label class="form-label" for="new-goal-pipeline">Pipeline their leads arrive in</label>
                    <select class="form-select" id="new-goal-pipeline" name="pipeline_id">
                        <option value="">Choose later</option>
                        @foreach($pipelines as $pipeline)
                            <option value="{{ $pipeline->id }}">{{ $pipeline->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2"><button type="submit" class="btn btn-primary w-100">Add goal</button></div>
            </form>
        </x-card>
    @endif
@endsection
