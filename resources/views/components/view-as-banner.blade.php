{{--
    Customer Experience Slice 1B — the View-as-client banner (contract §5.5
    "Banner", §17.2). Persistent and non-dismissible on every page while a
    view-as session is active: names the viewed client, the real actor,
    the expiry, and carries the Exit control. Announced to assistive
    technology through role="status".

    Agency V1 final: rendered by the layout masters themselves, NOT by the
    breadcrumb partial. It used to ride on `panels.breadcrumb`, so an install
    with the title bar switched off (THEME_BREADCRUMBS=false) or a page that
    turns it off showed no banner and, with it, no Exit control on any
    module page. It is also sticky and uses the status-warning text token:
    the theme's own `.alert-warning` text is orange on pale orange (1.8:1).
--}}
@if(isset($customerContext) && $customerContext instanceof \App\Library\Navigation\CustomerContext && $customerContext->isViewingAsClient())
    @php
        $viewAs = $customerContext->viewAs;
        $now = \Carbon\CarbonImmutable::now();
        $endsAt = $viewAs->expiresAt->setTimezone(config('app.timezone', 'UTC'))->format('H:i');
    @endphp
    <style>
        .alert.customer-view-as-banner {
            position: sticky;
            top: 0;
            /* Above the page, below dropdowns (1000) and modals: the header's menus must open OVER the banner. */
            z-index: 999;
            color: var(--color-status-warning-text);
            background-color: var(--color-status-warning-soft-bg);
            border: 1px solid var(--color-status-warning-border);
        }
        /* A sticky or floating header is fixed to the top of the screen (4.5rem, the same figure the toast region
           clears): the banner must stick BELOW it or its Exit control would hide behind the header. */
        body.navbar-sticky .alert.customer-view-as-banner,
        body.navbar-floating .alert.customer-view-as-banner {
            top: 4.5rem;
        }
        .alert.customer-view-as-banner strong,
        .alert.customer-view-as-banner svg {
            color: inherit;
        }
        .alert.customer-view-as-banner .customer-view-as-exit {
            color: var(--color-status-warning-text);
            border-color: currentColor;
            font-weight: 600;
            white-space: nowrap;
        }
    </style>
    <div class="alert customer-view-as-banner d-flex flex-wrap align-items-center gap-1 mb-2 p-1"
         role="status" aria-live="polite" data-role="view-as-banner">
        <x-ds-icon name="eye" size="18" aria-hidden="true" />
        <div class="flex-grow-1">
            <strong>Viewing {{ $viewAs->businessName }} as a client.</strong>
            {{-- The detail is kept off a phone-width screen: the banner is sticky, and a tall one would cover a quarter of it. --}}
            <span class="visually-hidden d-md-none">Billing, funding, plan, staff, provider and delete actions are paused in this view.</span>
            <span class="d-none d-md-inline">
                You are still signed in as {{ $viewAs->actorDisplayName }}. Billing, funding, plan, staff, provider and delete actions are paused in this view.
                It ends automatically at {{ $endsAt }} ({{ $viewAs->minutesRemaining($now) }} min left).
            </span>
        </div>
        <form method="POST" action="{{ route('customer.view-as.exit') }}" class="mb-0">
            @csrf
            <button type="submit" class="btn btn-sm btn-outline-secondary customer-view-as-exit" data-role="view-as-exit">Exit client view</button>
        </form>
    </div>
@endif
