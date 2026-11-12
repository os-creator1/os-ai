{{-- Inbound browser calls on every normal page. Renders nothing unless the server says this user, in this Business, may take
     inbound calls (flag on + pilot-approved + browser routing + permission + not View-As). Contact profiles include the
     softphone themselves; the softphone partial is @once, so it can never be rendered twice. --}}
@auth
    @php
        $mgInboundCalling = null;
        if ((bool) config('messaging.voice.inbound.enabled')) {
            try {
                $mgCtx = app(\App\Library\Navigation\CustomerShellComposer::class)->currentContext(auth()->user());
                if ($mgCtx->selectedWorkspace !== null && $mgCtx->selectedBusiness !== null) {
                    $mgInboundCalling = app(\App\Library\Voice\Inbound\InboundCallingSurface::class)
                        ->browserCalling((string) $mgCtx->selectedWorkspace->uid, $mgCtx->selectedBusiness, auth()->user());
                }
            } catch (\Throwable) {
                $mgInboundCalling = null;
            }
        }
    @endphp
    @if ($mgInboundCalling !== null)
        @include('customer.people._softphone', ['browserCalling' => $mgInboundCalling])
    @endif
@endauth
