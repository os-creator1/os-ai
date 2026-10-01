<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Library\Forms\Exceptions\FormUnavailableException;
use App\Library\Forms\FormDeploymentResolver;
use App\Library\Forms\FormOperationToken;
use App\Library\Forms\FormSubmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Forms V1 — the form's own public link: a guest adapter into
 * FormSubmissionService, the canonical writer.
 *
 * The URL carries a DEPLOYMENT uid, which is the deterministic evidence of the
 * Location (FormDeploymentResolver). Every action re-runs the identical
 * authority stack before it renders or reads a single guest value, and EVERY
 * refusal — unknown link, switched-off form, archived Location, unentitled or
 * locked account — is the same 404, so a visitor learns nothing about why.
 *
 * Each render is issued its OWN operation token, embedded in the page; that
 * token, not the body, is what makes a retry or double-click converge on one
 * submission while two genuine submissions stay separate.
 *
 * A filled honeypot is answered exactly like a success and stores nothing: a
 * bot is told nothing it can adapt to, and no row, Contact or event exists to
 * pollute the Business.
 */
class PublicFormController extends Controller
{
    public function __construct(
        private readonly FormDeploymentResolver $resolver,
        private readonly FormSubmissionService $submissions,
    ) {
    }

    public function show(string $deploymentUid): View
    {
        $context = $this->resolveOrNotFound($deploymentUid);

        return view('public.forms.show', [
            'context' => $context,
            'token' => FormOperationToken::issue($context->deployment),
            'honeypot' => FormSubmissionService::HONEYPOT_FIELD,
            'tokenField' => FormSubmissionService::TOKEN_FIELD,
        ]);
    }

    public function submit(Request $request, string $deploymentUid): RedirectResponse
    {
        // Authority first, before any guest input is inspected.
        $this->resolveOrNotFound($deploymentUid);

        if (trim((string) $request->input(FormSubmissionService::HONEYPOT_FIELD)) !== '') {
            return redirect()->route('public.forms.thanks', [$deploymentUid]);
        }

        try {
            $this->submissions->submit($deploymentUid, $request->all());
        } catch (FormUnavailableException) {
            abort(404);
        }

        return redirect()->route('public.forms.thanks', [$deploymentUid]);
    }

    public function thanks(string $deploymentUid): View
    {
        return view('public.forms.thanks', ['context' => $this->resolveOrNotFound($deploymentUid)]);
    }

    private function resolveOrNotFound(string $deploymentUid)
    {
        try {
            return $this->resolver->resolve($deploymentUid);
        } catch (FormUnavailableException) {
            abort(404);
        }
    }
}
