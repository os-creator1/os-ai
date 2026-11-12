@extends('layouts/contentLayoutMaster')

@section('title', 'Calling')

@section('page-style')
    <style>
        .mg-calling{max-width:44rem}
        .mg-calling h1{font-size:1.4rem;margin:0 0 .25rem}
        .mg-calling .mg-calling-sub{color:var(--color-text-muted,#6f6d67);margin:0 0 1.25rem}
        .mg-calling-card{background:var(--color-surface-primary,#fff);border:1px solid var(--color-border-neutral,#e5e1da);border-radius:.9rem;padding:1.25rem;margin-bottom:1rem}
        .mg-calling-card h2{font-size:1.05rem;margin:0 0 .75rem}
        .mg-calling-opt{display:flex;gap:.75rem;align-items:flex-start;padding:.75rem;border:1px solid var(--color-border-neutral,#e5e1da);border-radius:.7rem;margin-bottom:.6rem;cursor:pointer}
        .mg-calling-opt:has(input:checked){border-color:var(--color-primary,#b5524c)}
        .mg-calling-opt input{margin-top:.25rem}
        .mg-calling-opt strong{display:block}
        .mg-calling-opt span{font-size:.85rem;color:var(--color-text-muted,#6f6d67)}
        .mg-calling-note{padding:.75rem 1rem;border-radius:.7rem;background:var(--color-surface-secondary,#f6f4f1);font-size:.9rem;margin-bottom:1rem}
        .mg-calling-err{color:#b4514a;font-size:.85rem;margin:.35rem 0 0}
        .mg-calling-row{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}
        .mg-calling-badge{display:inline-block;padding:.1rem .6rem;border-radius:999px;font-size:.8rem;font-weight:600;background:#e3f3ea;color:#17693f}
        .mg-calling-badge.is-pending{background:#fdf6e3;color:#7a4a05}
    </style>
@endsection

@section('content')
    @php($enabled = $platformEnabled && $pilotApproved)
    @php($formsDisabled = ! $canManage || ! $enabled)
    <div class="mg-calling" data-role="calling-settings">
        <h1>Calling</h1>
        <p class="mg-calling-sub">Choose what happens when someone calls your MotionGrove number.</p>

        @if (session('status'))
            <div class="mg-calling-note" role="status" data-role="calling-status">{{ session('status') }}</div>
        @endif

        @if (! $enabled)
            <div class="mg-calling-note" data-role="calling-unavailable">Inbound calling is not turned on for this business yet. It is rolling out to pilot businesses; you will be able to choose an option here once your business is approved.</div>
        @elseif (! $canManage)
            <div class="mg-calling-note" data-role="calling-readonly">Only the account owner, or a member with calling permission, can change these settings.</div>
        @endif

        <form method="post" action="{{ route('customer.workspaces.businesses.calling.update', [$workspaceUid, $businessUid]) }}" class="mg-calling-card" data-role="calling-mode-form">
            @csrf
            @method('PUT')
            <h2>When someone calls</h2>
            @foreach ([
                'off' => ['Do not answer calls', 'Calls are not routed. Callers get a busy signal.'],
                'browser' => ['Ring MotionGrove in the browser', 'Available team members see an incoming-call popup on any page and can answer or decline.'],
                'forward' => ['Forward to my phone', 'Calls ring one verified personal phone number.'],
                'browser_then_forward' => ['Browser first, then my phone', 'Ring the browser; if nobody answers in time, forward to your verified phone.'],
            ] as $value => [$label, $hint])
                <label class="mg-calling-opt">
                    <input type="radio" name="mode" value="{{ $value }}" @checked($mode === $value) @disabled($formsDisabled)>
                    <span><strong>{{ $label }}</strong>{{ $hint }}</span>
                </label>
            @endforeach
            @error('mode')<p class="mg-calling-err">{{ $message }}</p>@enderror

            <label for="mg-ring-secs" style="display:block;margin-top:1rem;font-weight:600">Ring the browser for (seconds)</label>
            <input id="mg-ring-secs" type="number" name="browser_ring_secs" min="{{ $ringMin }}" max="{{ $ringMax }}" value="{{ old('browser_ring_secs', $ringSecs) }}" class="form-control" style="max-width:8rem" @disabled($formsDisabled)>
            @error('browser_ring_secs')<p class="mg-calling-err">{{ $message }}</p>@enderror
            <p style="font-size:.85rem;color:var(--color-text-muted,#6f6d67);margin:.35rem 0 1rem">Used before forwarding to your phone. Between {{ $ringMin }} and {{ $ringMax }} seconds.</p>

            <button type="submit" class="btn btn-primary" @disabled($formsDisabled)>Save</button>
        </form>

        <div class="mg-calling-card" data-role="calling-forward">
            <h2>Personal phone for forwarding</h2>
            @if ($forwardLast4 !== null)
                <p>
                    Number ending in <strong>{{ $forwardLast4 }}</strong>
                    @if ($forwardVerified)<span class="mg-calling-badge" data-role="forward-verified">Verified</span>@else<span class="mg-calling-badge is-pending" data-role="forward-unverified">Not verified</span>@endif
                </p>
            @else
                <p style="color:var(--color-text-muted,#6f6d67)">No number saved yet.</p>
            @endif

            <form method="post" action="{{ route('customer.workspaces.businesses.calling.forward.verify', [$workspaceUid, $businessUid]) }}" class="mg-calling-row" data-role="forward-verify-form">
                @csrf
                <input type="tel" name="phone" class="form-control" style="max-width:15rem" placeholder="(555) 123-4567" autocomplete="tel" required @disabled($formsDisabled)>
                <button type="submit" class="btn btn-outline-secondary" @disabled($formsDisabled)>Call me with a code</button>
            </form>
            @error('phone')<p class="mg-calling-err">{{ $message }}</p>@enderror

            @if ($codePending)
                <form method="post" action="{{ route('customer.workspaces.businesses.calling.forward.confirm', [$workspaceUid, $businessUid]) }}" class="mg-calling-row" style="margin-top:.75rem" data-role="forward-confirm-form">
                    @csrf
                    <input type="text" name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" class="form-control" style="max-width:9rem" placeholder="6-digit code" autocomplete="one-time-code" required @disabled($formsDisabled)>
                    <button type="submit" class="btn btn-primary" @disabled($formsDisabled)>Verify</button>
                </form>
            @endif
            @error('code')<p class="mg-calling-err">{{ $message }}</p>@enderror

            @if ($forwardLast4 !== null)
                <form method="post" action="{{ route('customer.workspaces.businesses.calling.forward.remove', [$workspaceUid, $businessUid]) }}" style="margin-top:.75rem">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-link p-0" @disabled($formsDisabled)>Remove this number</button>
                </form>
            @endif
            <p style="font-size:.85rem;color:var(--color-text-muted,#6f6d67);margin:.75rem 0 0">We verify the number with a short call before any call is forwarded to it. Toll-free, premium-rate and some international numbers cannot be used.</p>
        </div>
    </div>
@endsection
