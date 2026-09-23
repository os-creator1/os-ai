<?php

namespace App\Http\Controllers\Public;

use App\Exceptions\Documents\DocumentLinkException;
use App\Http\Controllers\Controller;
use App\Library\Documents\DocumentManager;
use App\Library\Documents\PublicDocumentAccess;
use App\Library\Documents\PublicDocumentGuard;
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
            // Deliberately NOT named $errors: that would shadow Blade's own
            // ViewErrorBag and change how every shared partial behaves.
            'formErrors' => $errors,
        ];
    }
}
