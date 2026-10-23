{{--
    Implementation Contract 17 Sub-slice D — the Stripe Connect status page
    for money lane B.

    READ-ONLY FOR NON-OWNERS. Staff who already passed the gate chain may see
    whether the business can be paid; only an owner sees the controls, and the
    server refuses them regardless of what this page renders (§6.2).

    NO ACCOUNT ID IS EVER A FORM FIELD. Every action posts to a route that
    derives the connected account from the Business's own row, so the browser
    cannot name or substitute another account.

    EVERY STATE COMES FROM THE CONNECTION ROW. The progress steps read
    details_submitted / charges_enabled / payouts_enabled, and "Ready" appears
    only when the manager's own isChargeReady() agrees ($chargeReady). Returning
    from Stripe proves nothing; "Check status" re-reads the provider.

    No secret, no API key, no onboarding URL and no raw provider payload is
    rendered here. The Stripe account id is shown because it is the Business's
    own identifier and is useful for support, and it is escaped like everything
    else.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Online payments')

@section('page-style')
    @include('customer.settings._redesign-assets')
@endsection

@php
    use App\Enums\Documents\StripeConnectionStatus;

    $scope = [$workspaceUid, $businessUid];

    $viaOauth = $connection !== null && $connection->connection_method === 'oauth';
    $requirementsDue = $connection === null ? [] : (json_decode((string) ($connection->getRawOriginal('requirements_due') ?? '[]'), true) ?: []);

    $detailsDone = $connection !== null && (bool) $connection->details_submitted;
    $chargesDone = $connection !== null && (bool) $connection->charges_enabled;
    $payoutsDone = $connection !== null && (bool) $connection->payouts_enabled;

    // One named state per situation, so the badge, heading and copy never disagree.
    $state = match (true) {
        $connection === null => 'none',
        $chargeReady && $payoutsDone => 'ready',
        $chargeReady => 'payouts_pending',
        $connection->status === StripeConnectionStatus::Restricted => 'restricted',
        $connection->status === StripeConnectionStatus::Active => 'payments_off',
        default => 'incomplete',
    };

    [$badgeVariant, $badgeLabel] = match ($state) {
        'ready' => ['success', 'Ready'],
        'payouts_pending' => ['warning', 'Payouts not enabled'],
        'restricted' => ['danger', 'Restricted'],
        'payments_off' => ['warning', 'Payments not enabled'],
        'incomplete' => ['warning', 'Setup not finished'],
        default => ['neutral', 'Not connected'],
    };

    // Stripe's hosted onboarding is also how a finished-but-limited account is
    // brought up to date, so it stays on offer until payments AND payouts are on.
    $needsStripe = $connection !== null && ! ($chargeReady && $payoutsDone);

    $previousConnections = $history->reject(
        fn ($row) => $connection !== null && (int) $row->id === (int) $connection->id
    );

    $steps = $connection === null ? [] : [
        ['key' => 'account', 'label' => $viaOauth ? 'Stripe account connected' : 'Stripe account created', 'done' => true, 'capability' => null],
        ['key' => 'details', 'label' => 'Business details submitted', 'done' => $detailsDone, 'capability' => 'details'],
        ['key' => 'charges', 'label' => 'Can accept payments', 'done' => $chargesDone, 'capability' => 'charges'],
        ['key' => 'payouts', 'label' => 'Can receive payouts', 'done' => $payoutsDone, 'capability' => 'payouts'],
    ];
@endphp

@section('content')
    <div class="sr-page">
        <div class="sr-head">
            <div class="sr-head__main">
                <h1 class="sr-title">
                    Online payments
                    <x-badge :variant="$badgeVariant" data-role="status-badge">{{ $badgeLabel }}</x-badge>
                </h1>
                <p class="sr-lede">Get paid by customers through your own Stripe account.</p>
            </div>
        </div>

        <x-flash-alert class="mb-2" />

        <section class="sr-card pc-main" data-section="stripe-connection" aria-labelledby="pc-main-title">
            <div class="pc-main__grid">
                <div class="pc-main__lead">
                    <span class="sr-tile sr-tile--lg {{ $state === 'ready' ? 'pc-tile--success' : '' }}" aria-hidden="true">
                        <x-ds-icon :name="$state === 'ready' ? 'check' : 'credit-card'" size="20" />
                    </span>

                    @if($state === 'none')
                        <h2 class="pc-main__title" id="pc-main-title">Get paid online</h2>
                        <p class="pc-main__text" data-role="connection-state" data-status="none">
                            No Stripe account is connected yet, so <span class="pc-wrap">{{ $business->name }}</span> can't be paid online. Connect an existing Stripe account or set up a new one to start taking card payments.
                        </p>
                    @else
                        <h2 class="pc-main__title" id="pc-main-title">
                            @switch($state)
                                @case('ready') Online payments are ready @break
                                @case('payouts_pending') Payments are on, payouts aren't @break
                                @case('restricted') Stripe needs more information @break
                                @case('payments_off') Stripe hasn't enabled payments yet @break
                                @default Stripe needs a few more details
                            @endswitch
                        </h2>
                        <p class="pc-main__text" data-role="connection-state" data-status="{{ $connection->status->value }}">
                            @switch($state)
                                @case('ready')
                                    Stripe confirms customers can pay <span class="pc-wrap">{{ $business->name }}</span> online. Payouts go to your bank on Stripe's schedule.
                                    @break
                                @case('payouts_pending')
                                    Customers can pay <span class="pc-wrap">{{ $business->name }}</span> online, but Stripe hasn't enabled payouts to your bank yet.
                                    @break
                                @case('restricted')
                                    Stripe has limited this account, so customers can't pay <span class="pc-wrap">{{ $business->name }}</span> online yet.
                                    @break
                                @case('payments_off')
                                    Connected, but Stripe has not finished enabling payments for <span class="pc-wrap">{{ $business->name }}</span>.
                                    @break
                                @default
                                    Customers can't pay <span class="pc-wrap">{{ $business->name }}</span> online until you finish on Stripe. It usually takes a few minutes.
                            @endswitch
                        </p>

                        @if(count($requirementsDue) > 0)
                            <p class="pc-reason" data-role="requirements-due">Still needed by Stripe: <span class="pc-wrap">{{ implode(', ', array_map(fn ($r) => str_replace('_', ' ', (string) $r), $requirementsDue)) }}</span></p>
                        @endif

                        @if($connection->requirements_disabled_reason)
                            <p class="pc-reason" data-role="disabled-reason">Stripe reason: <span class="pc-wrap">{{ $connection->requirements_disabled_reason }}</span></p>
                        @endif
                    @endif

                    @if($isOwner)
                        @if($state === 'none')
                            <div class="sr-btn-row">
                                <form method="POST" action="{{ route('customer.workspaces.businesses.payments.connect.existing', $scope) }}" data-role="connect-existing-form">
                                    @csrf
                                    <x-button type="submit" variant="primary" icon="external-link">Connect existing Stripe account</x-button>
                                </form>
                                <form method="POST" action="{{ route('customer.workspaces.businesses.payments.connect.start', $scope) }}" data-role="connect-form">
                                    @csrf
                                    <x-button type="submit" variant="secondary" icon="plus">Set up a new Stripe account</x-button>
                                </form>
                            </div>
                            <p class="pc-main__hint" data-role="connect-hint">You'll sign in or finish setup on Stripe, then come back here. Existing-account connection works for standard Stripe accounts only; an account already controlled by another platform can't be connected.</p>
                        @else
                            <div class="sr-btn-row">
                                @if($needsStripe && $viaOauth)
                                    {{-- An existing account is finished in the owner's own Stripe Dashboard;
                                         we cannot mint onboarding links for an account we did not create. --}}
                                    <x-button variant="primary" icon="external-link" href="https://dashboard.stripe.com" rel="noopener" data-role="stripe-dashboard-link">Finish in your Stripe Dashboard</x-button>
                                @elseif($needsStripe)
                                    <x-button variant="primary" icon="external-link" :href="route('customer.workspaces.businesses.payments.connect.resume', $scope)" data-role="resume-link">Continue on Stripe</x-button>
                                @endif
                                <form method="POST" action="{{ route('customer.workspaces.businesses.payments.connect.refresh', $scope) }}" data-role="refresh-form">
                                    @csrf
                                    <x-button type="submit" variant="secondary" icon="refresh-cw">Check status</x-button>
                                </form>
                            </div>
                        @endif
                    @elseif($state === 'none')
                        <p class="pc-main__hint" data-role="owner-only-note">Only an owner can connect a Stripe account.</p>
                    @else
                        <p class="pc-main__hint" data-role="owner-only-note">Only an owner can change this Stripe connection.</p>
                    @endif

                    @if($connection !== null)
                        <p class="pc-main__hint" data-role="last-checked">
                            @if($connection->last_synced_at)
                                Last checked <time datetime="{{ $connection->last_synced_at->toIso8601String() }}" title="{{ $connection->last_synced_at->format('j F Y, H:i') }}">{{ $connection->last_synced_at->diffForHumans() }}</time>
                            @else
                                Not checked with Stripe yet
                            @endif
                        </p>
                    @endif

                    {{-- §11.4 — payability is a separate question from being
                         connected. Stated plainly rather than implied. --}}
                    <p class="pc-main__hint" data-role="charge-ready" data-ready="{{ $chargeReady ? '1' : '0' }}">
                        @if($chargeReady)
                            Stripe has confirmed this business can accept online payments.
                        @else
                            This business cannot be paid online yet.
                        @endif
                    </p>
                </div>

                @if($connection === null)
                    <aside class="pc-side pc-side--soft" aria-label="What you get once Stripe setup is finished">
                        <p class="sr-eyebrow">Once Stripe setup is finished</p>
                        <ul class="pc-benefits">
                            <li>
                                <span class="pc-ring" aria-hidden="true"></span>
                                <div>
                                    <strong>Customers pay after signing</strong>
                                    <span>A payment step can follow a signed proposal.</span>
                                </div>
                            </li>
                            <li>
                                <span class="pc-ring" aria-hidden="true"></span>
                                <div>
                                    <strong>"Signed → payment" automation</strong>
                                    <span>Turn on the recipe in Automations to send the payment request for you.</span>
                                </div>
                            </li>
                            <li>
                                <span class="pc-ring" aria-hidden="true"></span>
                                <div>
                                    <strong>Payouts to your bank</strong>
                                    <span>Stripe pays out on its own schedule.</span>
                                </div>
                            </li>
                        </ul>
                    </aside>
                @else
                    <aside class="pc-side" aria-label="Setup progress">
                        <p class="sr-eyebrow">Progress</p>
                        <ol class="pc-progress" data-role="capabilities">
                            @foreach($steps as $step)
                                <li class="pc-progress__row {{ $step['done'] ? 'is-done' : 'is-pending' }}"
                                    @if($step['capability'] !== null) data-capability="{{ $step['capability'] }}" data-enabled="{{ $step['done'] ? '1' : '0' }}" @endif>
                                    <span class="pc-mark {{ $step['done'] ? 'pc-mark--done' : 'pc-mark--pending' }}" aria-hidden="true">
                                        @if($step['done'])<x-ds-icon name="check" size="12" strokeWidth="2.5" />@endif
                                    </span>
                                    <div class="pc-progress__body">
                                        <strong>{{ $step['label'] }}</strong>
                                        @if($step['key'] === 'account')
                                            <code class="pc-wrap" data-role="stripe-account">{{ $connection->stripe_account_id }}</code>
                                        @else
                                            <span>{{ $step['done'] ? 'Done' : 'Not yet' }}</span>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    </aside>
                @endif
            </div>

            @if($connection !== null)
                <details class="pc-tech" data-role="technical-details">
                    <summary><x-ds-icon name="chevron-right" size="14" aria-hidden="true" /> Technical details</summary>
                    <dl class="pc-tech__list">
                        <div><dt>Connection status</dt><dd>{{ ucfirst($connection->status->value) }}</dd></div>
                        <div data-role="connection-method"><dt>Connected via</dt><dd>{{ $viaOauth ? 'Existing Stripe account' : 'New Stripe account' }}</dd></div>
                        <div><dt>Details submitted</dt><dd>{{ $detailsDone ? 'Yes' : 'Not yet' }}</dd></div>
                        <div><dt>Accept payments</dt><dd>{{ $chargesDone ? 'Enabled' : 'Not enabled' }}</dd></div>
                        <div><dt>Receive payouts</dt><dd>{{ $payoutsDone ? 'Enabled' : 'Not enabled' }}</dd></div>
                        @if($connection->default_currency)
                            <div><dt>Currency</dt><dd>{{ strtoupper($connection->default_currency) }}</dd></div>
                        @endif
                        <div><dt>Last synchronised</dt><dd>{{ $connection->last_synced_at ? $connection->last_synced_at->format('j F Y, H:i') : 'Never' }}</dd></div>
                        <div><dt>Stripe restriction</dt><dd class="pc-wrap">{{ $connection->requirements_disabled_reason ?: 'None reported' }}</dd></div>
                    </dl>
                </details>
            @endif
        </section>

        @include('customer.business.payments._how-it-works', ['business' => $business])

        @if($connection !== null && $isOwner)
            <section class="sr-card pc-disconnect" aria-labelledby="pc-disconnect-title">
                <div class="pc-disconnect__row">
                    <div>
                        <h2 class="pc-disconnect__title" id="pc-disconnect-title">Disconnect Stripe</h2>
                        <p class="pc-disconnect__text">Stops online payments for this business.</p>
                    </div>
                    <button type="button" class="btn btn-sr-danger-outline btn-sm" data-bs-toggle="modal" data-bs-target="#disconnect-stripe-dialog" data-role="disconnect-open">Disconnect</button>
                </div>
            </section>

            <x-dialog id="disconnect-stripe-dialog" title="Disconnect Stripe?" size="sm">
                <p class="mb-1">Customers will no longer be able to pay <span class="pc-wrap">{{ $business->name }}</span> online through MotionGrove.</p>
                <p class="text-caption mb-0">
                    This ends MotionGrove's connection only. Your Stripe account itself isn't deleted, and past payments and
                    connection history stay on record.
                    @if($viaOauth)
                        We'll also revoke MotionGrove's access to it at Stripe. You can connect it, or another account, again later.
                    @else
                        Connecting again later lets you connect an existing account or set up a new one.
                    @endif
                </p>
                <x-slot:footer>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Keep connected</button>
                    <form method="POST" action="{{ route('customer.workspaces.businesses.payments.connect.disconnect', $scope) }}" data-role="disconnect-form">
                        @csrf
                        <button type="submit" class="btn btn-danger btn-sm">Disconnect Stripe</button>
                    </form>
                </x-slot:footer>
            </x-dialog>
        @endif

        @if($previousConnections->isNotEmpty())
            <section class="sr-card" data-section="connection-history">
                <div class="sr-card__head"><h2 class="sr-card__title">Previous connections</h2></div>
                <ul class="sr-list">
                    @foreach($previousConnections as $row)
                        <li class="sr-list__row" data-role="history-row" data-status="{{ $row->status->value }}">
                            <div class="sr-list__main">
                                <div class="sr-list__name"><code class="pc-wrap">{{ $row->stripe_account_id }}</code></div>
                                <div class="sr-list__sub">
                                    {{ ucfirst($row->status->value) }}@if($row->disconnected_at), disconnected {{ $row->disconnected_at->format('j F Y') }}@endif
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
    @include('legal._notice', ['doc' => 'payments-and-esignatures', 'text' => 'Which party is responsible for refunds, chargebacks and negative balances on these payments depends on your Stripe account and the'])
@endsection
