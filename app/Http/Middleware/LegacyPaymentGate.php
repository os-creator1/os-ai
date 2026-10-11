<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail-closed gate for the inherited Ultimate SMS payment routes (see config/legacy_payments.php).
 *
 * A request is a "legacy payment request" when its route is a gateway success / return / callback / card-form /
 * offline / crypto route, or a POST that starts such a payment. Those are refused unless the gateway it concerns
 * is explicitly listed in legacy_payments.enabled_gateways. The gateway is taken from the route itself when the
 * route is gateway specific, otherwise from the request / session payment method; a request that names no
 * gateway, or any gateway that is not listed, is refused.
 */
class LegacyPaymentGate
{
    /** route-name suffix => gateway type it is specific to */
    private const NAMED_GATEWAYS = [
        'braintree'                     => 'braintree',
        'authorize_net'                 => 'authorize_net',
        'vodacommpesa'                  => 'vodacommpesa',
        'payment.offline'               => 'offline_payment',
        'payment.nowpayments'           => 'nowpayments',
        'payment.check_nowpayments_status' => 'nowpayments',
    ];

    /** POST URIs that start a legacy payment (they are unnamed) */
    private const INITIATION_URIS = [
        '#^(senderid|numbers|keywords)/\{[A-Za-z_]+\}/pay$#',
        '#^subscriptions/\{[A-Za-z_]+\}/(purchase|renew|change-plan)$#',
        '#^account/top-up$#',
        '#^pay-(offline|nowpayments)$#',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        if ($route === null) {
            return $next($request);
        }

        $name = (string) $route->getName();
        $uri = $route->uri();
        $isCallback = (bool) preg_match('/(^|\.)callback\.([a-z0-9_]+)/', $name, $callback);

        $specific = null;
        if ($isCallback) {
            $specific = $callback[2];
        } else {
            foreach (self::NAMED_GATEWAYS as $suffix => $gateway) {
                if (preg_match('/(^|\.)' . preg_quote($suffix, '/') . '$/', $name)) {
                    $specific = $gateway;

                    break;
                }
            }
        }

        $isSuccess = (bool) preg_match('/(^|\.)(payment_success)$/', $name) || $name === 'user.account.pay';
        $isInitiation = $request->isMethod('POST') && collect(self::INITIATION_URIS)->contains(fn ($p) => preg_match($p, $uri));

        if (! $isCallback && $specific === null && ! $isSuccess && ! $isInitiation) {
            return $next($request);
        }

        if ($this->allowed($request, $specific)) {
            return $next($request);
        }

        Log::warning('Blocked legacy payment request', [
            'route'   => $name ?: $uri,
            'user_id' => optional($request->user())->id,
            'ip'      => $request->ip(),
        ]);

        $message = 'This payment method is no longer available.';

        // Provider callbacks, API-style posts and the offline/crypto form posts get a 410; a browser gets a redirect.
        if ($isCallback || $request->expectsJson() || $request->routeIs('customer.payment.*', 'payment.*')) {
            return response()->json(['status' => 'error', 'message' => $message], 410);
        }

        return redirect()->route('user.home')->with(['status' => 'error', 'message' => $message]);
    }

    private function allowed(Request $request, ?string $specific): bool
    {
        $enabled = array_map('strtolower', (array) config('legacy_payments.enabled_gateways', []));
        if ($enabled === []) {
            return false;
        }

        if ($specific !== null) {
            return in_array(strtolower($specific), $enabled, true);
        }

        $candidates = array_filter([
            $request->input('payment_methods'),
            $request->input('payment_method'),
            $request->hasSession() ? $request->session()->get('payment_method') : null,
        ], fn ($v) => is_string($v) && $v !== '');

        if ($candidates === []) {
            return false;
        }

        foreach ($candidates as $candidate) {
            if (! in_array(strtolower($candidate), $enabled, true)) {
                return false;
            }
        }

        return true;
    }
}
