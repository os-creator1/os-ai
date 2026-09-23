<?php

namespace App\Http\Controllers\Public;

use App\Exceptions\Documents\DocumentLinkException;
use App\Exceptions\Payments\PaymentStartException;
use App\Exceptions\Payments\StripeConnectException;
use App\Http\Controllers\Controller;
use App\Library\Documents\DocumentManager;
use App\Library\Documents\PublicDocumentAccess;
use App\Library\Documents\PublicDocumentGuard;
use App\Library\Payments\PaymentManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Implementation Contract 17 §6.3 / §6.3.1 / §7.1 — the end customer's whole
 * surface: view the document, and sign it.
 *
 * NO ACCOUNT, NO AUTHENTICATION. The permission boundary is possession of
 * the secure link. It exposes no list, no search, no other document and no
 * Business data beyond this document's own frozen content.
 *
 * THE GET IS SIDE-EFFECT-FREE AND REVISITABLE. It records nothing: there is
 * no DocumentViewed event and no last_viewed_at column in V1 (§10). A
 * customer may open, refresh and revisit it any number of times with zero
 * writes.
 *
 * ONE UNIFORM REFUSAL. Every failure — unknown uid, wrong token, rotated
 * token, expired link, voided document, suspended account, unentitled
 * Business, unusable Location — renders the byte-identical page with the
 * same 404 status. `->missing()` on the route keeps an unresolved binding
 * from rendering a 500 (the delta over the opt-in precedent, §6.3).
 *
 * NO PAYMENT SURFACE HERE. §6.3.2's payment-start POST is Sub-slice E and is
 * deliberately absent: this controller makes no provider call, creates no
 * PaymentIntent and accepts no card data of any kind.
 */
class PublicDocumentController extends Controller
{
    public function __construct(
        private readonly PublicDocumentGuard $guard,
        private readonly DocumentManager $manager,
        private readonly PaymentManager $payments,
    ) {
    }

    public function show(string $uid, string $token): Response
    {
        try {
            $access = $this->guard->resolve($uid, $token);
        } catch (DocumentLinkException) {
            return $this->refusal();
        }

        return response()->view('public.documents.show', $this->viewData($access));
    }

    public function sign(Request $request, string $uid, string $token): Response
    {
        try {
            $access = $this->guard->resolve($uid, $token);
            $this->guard->assertSignable($access);
        } catch (DocumentLinkException) {
            return $this->refusal();
        }

        // The request schema is closed and carries NO consent text: the
        // consent statement is a server-side constant (DocumentManager::
        // CONSENT_STATEMENT) so a browser can never choose what it agreed
        // to. It also carries no card data of any kind (§6.3.2).
        try {
            $evidence = $request->validate([
                'signer_name' => 'required|string|max:160',
                'signer_email' => 'required|string|email|max:255',
                'typed_name' => 'required|string|max:160',
            ]);
        } catch (ValidationException $e) {
            return response()->view('public.documents.show', $this->viewData($access, $e->errors()), 422);
        }

        try {
            $this->manager->sign($access->document, [
                'signer_name' => $evidence['signer_name'],
                'signer_email' => $evidence['signer_email'],
                'typed_name' => $evidence['typed_name'],
                'ip_address' => (string) $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        } catch (ValidationException $e) {
            // A lifecycle race (someone voided or signed between the guard
            // and the lock) is refused with the same uniform page rather
            // than a message describing another party's activity.
            return $this->refusal();
        }

        return response()->view('public.documents.signed', [
            'document' => $access->document->refresh(),
            'business' => $access->business,
        ]);
    }

    /**
     * §6.3.2 / §7.2 — the ONLY entry point permitted to invoke PAY START.
     *
     * Authorized by the same `{uid}/{token}` possession and re-running all
     * five §6.3.1 rechecks, then PaymentManager runs §7.2's algorithm under
     * §7.0's lock order.
     *
     * THE REQUEST SCHEMA IS EMPTY, DELIBERATELY. There is no card number,
     * expiry, CVC, payment-method or amount field — and no Stripe account
     * field, because §7.2.2 derives the connected account server-side from
     * the payment row. A request carrying card-shaped input is REJECTED
     * outright rather than having those fields quietly ignored: silently
     * accepting them would mean card data had already entered a Laravel
     * request body, log and exception trace (§6.3.2, §11.8).
     *
     * A refusal is the same uniform response as every other failure on this
     * surface: an end customer must not learn whether a document is unsigned,
     * already being paid, or belongs to a Business that is not payment-ready.
     */
    public function payStart(Request $request, string $uid, string $token): Response|JsonResponse
    {
        if ($this->carriesCardShapedInput($request)) {
            return response()->json(['error' => 'unsupported_request'], 422);
        }

        try {
            $access = $this->guard->resolve($uid, $token);
            $result = $this->payments->start($access);
        } catch (DocumentLinkException|PaymentStartException|StripeConnectException) {
            // A provider failure is refused exactly like every other reason:
            // it must never surface as a 500 that leaks a stack trace, and the
            // customer must not learn whether the fault was theirs, the
            // document's, or Stripe's.
            return $this->refusal();
        }

        // §7.2.1 — the minimum transient browser material, and nothing more.
        // The platform SECRET key is never here.
        return response()->json($result->toResponse());
    }

    /**
     * §6.3.2 — card data has no place in this endpoint's contract, so a
     * request that carries any is refused rather than sanitized. Matching on
     * the SHAPE as well as the name means an attacker cannot smuggle a PAN
     * through an unexpected key.
     */
    private function carriesCardShapedInput(Request $request): bool
    {
        foreach ($request->all() as $key => $value) {
            $name = strtolower((string) $key);

            foreach (['card', 'number', 'pan', 'cvc', 'cvv', 'exp_month', 'exp_year', 'expiry', 'payment_method', 'token', 'account'] as $forbidden) {
                if (str_contains($name, $forbidden)) {
                    return true;
                }
            }

            // A bare 13-19 digit value is a PAN whatever it is called.
            if (is_scalar($value) && preg_match('/\A[0-9 -]{13,25}\z/', (string) $value) === 1
                && strlen((string) preg_replace('/\D/', '', (string) $value)) >= 13) {
                return true;
            }
        }

        return false;
    }

    /**
     * §6.3 — ONE generic response, identical for every failure reason, so
     * possession of a uid can never be turned into an oracle for whether a
     * document exists, is voided, or belongs to a suspended account.
     */
    private function refusal(): Response
    {
        return response()->view('public.documents.invalid', [], 404);
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     * @return array<string, mixed>
     */
    private function viewData(PublicDocumentAccess $access, array $errors = []): array
    {
        $version = $access->version;

        return [
            'document' => $access->document,
            'business' => $access->business,
            'version' => $version,
            'lines' => $version->lineItems()->orderBy('position')->orderBy('id')->get(),
            'schedule' => $version->paymentScheduleItems()->orderBy('sequence')->get(),
            'consentStatement' => DocumentManager::CONSENT_STATEMENT,
            'signature' => $access->document->signature()->first(),
            // §6.3.2 — payment STATE only. Computing it creates no payment
            // row, no PaymentIntent and no provider call; the Pay action is a
            // separate POST.
            'payment' => $this->payments->paymentState($access),
            'paymentToken' => request()->route('token'),
            // Deliberately NOT named $errors: that would shadow Blade's own
            // ViewErrorBag and change how every shared partial behaves.
            'formErrors' => $errors,
        ];
    }
}
