<?php

namespace App\Http\Controllers\Customer;

use App\Enums\Calendar\ExternalCalendarProvider;
use App\Exceptions\Calendar\ExternalCalendarConcurrencyException;
use App\Exceptions\Calendar\ExternalCalendarConfigurationException;
use App\Exceptions\Calendar\ExternalCalendarProviderException;
use App\Library\Calendar\ExternalCalendar\ExternalCalendarConnectionManager;
use App\Library\Calendar\ExternalCalendar\ExternalCalendarNotificationRegistrar;
use App\Library\Calendar\ExternalCalendar\ExternalCalendarOAuthStateSigner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use LogicException;

/**
 * Implementation Contract 15 §5.5/§12.F — the per-User external calendar
 * connection surface. Bare and tenant-free, unlike every other Calendar
 * controller in this slice: Blueprint §12 is explicit that this connection
 * is "globally to their User identity — not once per Workspace", so there
 * is no Workspace/Business tenancy chain to run and no Calendar
 * entitlement gate to check — any authenticated User may connect their own
 * calendar regardless of which Business/Workspace they are currently
 * viewing. Mirrors GoogleBusinessProfileController's shape for the parts
 * that DO carry over: the state-first OAuth callback discipline and the
 * closed-classification exception handling.
 *
 * THE OAUTH CALLBACKS ARE THE ONE FIXED, TENANT-FREE ROUTE PER PROVIDER
 * (§28): each provider matches redirect_uri exactly against a registered
 * URI, so callback() cannot start from a route parameter it trusts — it
 * starts from the signed state, and Auth::id() must equal the state's own
 * user id before the nonce is ever consumed.
 */
class ExternalCalendarConnectionController extends CustomerBaseController
{
    public function __construct(
        private readonly ExternalCalendarConnectionManager $connections,
        private readonly ExternalCalendarOAuthStateSigner $stateSigner,
        private readonly ExternalCalendarNotificationRegistrar $registrar,
    ) {
    }

    public function show(): View
    {
        return view('customer.calendarConnection.show', [
            'connection' => $this->connections->findForUser((int) Auth::id()),
        ]);
    }

    /**
     * Contract §9.3-equivalent — connect initiation. A CSRF-protected POST
     * because it mutates connection state and the nonce.
     */
    public function connect(Request $request, string $provider): RedirectResponse
    {
        $providerEnum = ExternalCalendarProvider::tryFrom($provider);

        abort_if($providerEnum === null, 404);

        try {
            $url = $this->connections->beginConnect((int) Auth::id(), $providerEnum);
        } catch (ExternalCalendarConfigurationException $exception) {
            Log::error('External calendar configuration error.', [
                'provider' => $provider,
                'reason' => $exception->reason,
                'operator_message' => $exception->operatorMessage(),
            ]);

            return $this->redirectWithError($exception->customerMessage(), 'Calendar connection unavailable');
        } catch (LogicException $exception) {
            return $this->redirectWithError($exception->getMessage());
        }

        return redirect()->away($url);
    }

    /**
     * Validation order, all failures 404 with ZERO token exchange:
     *   1. signed state present, signature valid, not expired
     *   2. the route's own provider matches the state's own provider
     *   3. the callback actor is the User who initiated THIS attempt
     *   4. the connection resolved strictly from the signed User id + provider
     *   5. only then is the nonce consumed, atomically and once
     *   6. only after successful consumption is the code exchanged
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $providerEnum = ExternalCalendarProvider::tryFrom($provider);

        abort_if($providerEnum === null, 404);

        $payload = $this->stateSigner->verify($request->query('state'));

        if ($payload === null) {
            abort(404);
        }

        if ($payload['p'] !== $providerEnum->value) {
            abort(404);
        }

        // Never let another authenticated session complete someone else's
        // OAuth flow, even with a structurally valid, unexpired state.
        if ((int) Auth::id() !== $payload['u']) {
            abort(404);
        }

        $connection = $this->connections->findForUser($payload['u']);

        if ($connection === null || $connection->provider !== $providerEnum) {
            abort(404);
        }

        if (! $this->connections->attemptBelongsToActor($connection, (int) Auth::id())) {
            abort(404);
        }

        if (! $this->stateSigner->consume($payload['u'], $providerEnum, $payload['n'])) {
            abort(404);
        }

        if ($request->query('error') !== null || $request->query('code') === null) {
            return $this->redirectWithError('The calendar provider did not complete the connection.');
        }

        try {
            $this->connections->completeConnect($connection, (string) $request->query('code'), (int) Auth::id());
        } catch (ExternalCalendarConcurrencyException $exception) {
            return $this->redirectWithError($exception->userMessage());
        } catch (ExternalCalendarProviderException $exception) {
            return $this->redirectWithError($exception->userMessage());
        }

        // Best-effort, non-fatal: registers the actual provider push
        // channel/subscription so notifications start arriving as close to
        // immediately as possible. A failure here never fails the connect
        // flow itself — the scheduled sweep retries registration, and
        // polling remains the fallback either way.
        $this->registrar->ensureRegistered($connection->fresh(), force: true);

        return redirect()
            ->route('customer.calendar-connection.show')
            ->with(['status' => 'success', 'message' => 'Calendar connected.']);
    }

    public function disconnect(): RedirectResponse
    {
        $connection = $this->connections->findForUser((int) Auth::id());

        if ($connection === null) {
            return redirect()->route('customer.calendar-connection.show');
        }

        // Best-effort provider-side teardown BEFORE local credentials are
        // cleared — unregister() still needs a valid access token, which
        // disconnect() below is about to destroy. If this fails, local
        // destruction proceeds regardless (see ExternalCalendarNotificationRegistrar's
        // own docblock and ExternalCalendarConnectionManager::endConnection()).
        $this->registrar->unregister($connection);

        try {
            $this->connections->disconnect($connection, (int) Auth::id());
        } catch (ExternalCalendarConcurrencyException $exception) {
            return $this->redirectWithError($exception->userMessage());
        }

        return redirect()
            ->route('customer.calendar-connection.show')
            ->with(['status' => 'success', 'message' => 'Calendar disconnected and stored authorization destroyed.']);
    }

    private function redirectWithError(string $message, ?string $title = null): RedirectResponse
    {
        return redirect()
            ->route('customer.calendar-connection.show')
            ->with(array_filter(['status' => 'error', 'message' => $message, 'message_title' => $title], fn ($value) => $value !== null));
    }
}
