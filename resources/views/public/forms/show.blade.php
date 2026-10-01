<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $context->form->name }}</title>
    <style>
        body{font-family:system-ui,sans-serif;max-width:42rem;margin:3rem auto;padding:0 1rem;color:#17212b}
        label{display:block;margin:1rem 0 .3rem}input,select,textarea,button{font:inherit;padding:.65rem;border:1px solid #9ba7b4;border-radius:.4rem;box-sizing:border-box}
        input[type=text],input[type=email],input[type=tel],input[type=date],select,textarea{width:100%}
        button{background:#125a9c;color:white;border:0;cursor:pointer;margin-top:1.2rem}.error{color:#a51d24}
        .back{margin-left:1rem}.step{color:#52606d;margin:.25rem 0 0}
        .hp{position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden}
    </style>
</head>
<body>
<main>
    <h1>{{ $context->form->name }}</h1>
    {{-- Presentation only. $version is the version PINNED by the token: the
         questions shown here are exactly the ones the answers will be validated
         against, even if the owner has published a newer version since. --}}
    @if ($pageCount > 1)
        <p class="step" data-role="public-form-step">Step {{ $pageNumber }} of {{ $pageCount }}@if($page['title']) — {{ $page['title'] }}@endif</p>
    @endif
    @if ($pageNumber === 1 && $version->intro)<p>{!! nl2br(e($version->intro)) !!}</p>@endif

    @if ($errors->any())
        <div class="error" role="alert" data-role="public-form-errors">
            @foreach ($errors->all() as $message)<div>{{ $message }}</div>@endforeach
        </div>
    @endif

    <form method="post" action="{{ route('public.forms.submit', [$context->deployment->uid]) }}" data-role="public-form">
        @csrf
        <input type="hidden" name="{{ $tokenField }}" value="{{ $token }}">
        @if ($pageCount > 1)
            <input type="hidden" name="{{ $pageField }}" value="{{ $pageKey }}">
        @endif
        <div class="hp" aria-hidden="true">
            <label for="{{ $honeypot }}">Leave this empty</label>
            <input type="text" id="{{ $honeypot }}" name="{{ $honeypot }}" value="" tabindex="-1" autocomplete="off">
        </div>

        @foreach ($fields as $field)
            @php
                $key = $field['key'];
                $required = $field['required'] ? 'required' : '';
                $current = old($key, $answers[$key] ?? null);
            @endphp
            @if ($field['type'] === 'checkbox')
                <label>
                    <input type="checkbox" name="{{ $key }}" value="1" @checked($current) {{ $required }}>
                    {{ $field['label'] }}@if($field['required']) *@endif
                </label>
            @else
                <label for="f-{{ $key }}">{{ $field['label'] }}@if($field['required']) *@endif</label>
                @if ($field['type'] === 'textarea')
                    <textarea id="f-{{ $key }}" name="{{ $key }}" rows="5" {{ $required }}>{{ $current }}</textarea>
                @elseif ($field['type'] === 'select')
                    <select id="f-{{ $key }}" name="{{ $key }}" {{ $required }}>
                        <option value="">Choose…</option>
                        @foreach ($field['options'] as $option)
                            <option value="{{ $option }}" @selected($current === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                @else
                    @php($inputType = ['email' => 'email', 'phone' => 'tel', 'date' => 'date'][$field['type']] ?? 'text')
                    <input id="f-{{ $key }}" type="{{ $inputType }}" name="{{ $key }}" value="{{ $current }}" {{ $required }}>
                @endif
            @endif
        @endforeach

        <button type="submit">{{ $isLast ? $version->submit_label : 'Next' }}</button>
        @if ($backUrl)
            <a class="back" href="{{ $backUrl }}" data-role="public-form-back">Back</a>
        @endif
    </form>
</main>
</body>
</html>
