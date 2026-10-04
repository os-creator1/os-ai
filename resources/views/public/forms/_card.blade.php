{{-- Forms — one page of a form as the visitor sees it. Shared by the public page
     and the builder's Preview ($preview = true: no token, no honeypot, nothing is
     submitted). $version may be an unsaved FormVersion in the preview: only its
     presentation (intro, submit_label, style) is read here. --}}
@php
    $preview = $preview ?? false;
    $align = $version->style()['button_align'] ?? 'left';
    $title = $page['title'] ?? null;
@endphp
<div class="pf-card" data-role="public-form-card">
    <h1 class="pf-title">{{ $name }}</h1>
    {{-- Presentation only. $version is the version PINNED by the token: the
         questions shown here are exactly the ones the answers will be validated
         against, even if the owner has published a newer version since. --}}
    @if ($pageCount > 1)
        <p class="pf-step" data-role="public-form-step">Step {{ $pageNumber }} of {{ $pageCount }}@if($title) — {{ $title }}@endif</p>
    @endif
    @if ($pageNumber === 1 && $version->intro)<p class="pf-intro">{!! nl2br(e($version->intro)) !!}</p>@endif

    @if (! $preview && $errors->any())
        <div class="pf-error" role="alert" data-role="public-form-errors">
            @foreach ($errors->all() as $message)<div>{{ $message }}</div>@endforeach
        </div>
    @endif

    @if ($preview)
        <form onsubmit="return false" data-role="public-form" novalidate>
    @else
        <form method="post" action="{{ route('public.forms.submit', [$context->deployment->uid]) }}" data-role="public-form">
            @csrf
            <input type="hidden" name="{{ $tokenField }}" value="{{ $token }}">
            @if ($pageCount > 1)
                <input type="hidden" name="{{ $pageField }}" value="{{ $pageKey }}">
            @endif
            <div class="pf-hp" aria-hidden="true">
                <label for="{{ $honeypot }}">Leave this empty</label>
                <input type="text" id="{{ $honeypot }}" name="{{ $honeypot }}" value="" tabindex="-1" autocomplete="off">
            </div>
    @endif

        @include('public.forms._fields', ['fields' => $fields, 'answers' => $answers ?? []])

        <div class="pf-actions pf-align-{{ $align }}">
            <button type="{{ $preview ? 'button' : 'submit' }}" class="pf-btn">{{ $isLast ? $version->submit_label : 'Next' }}</button>
            @if (! empty($backUrl))
                <a class="pf-back" href="{{ $backUrl }}" data-role="public-form-back">Back</a>
            @endif
        </div>
    </form>
</div>
