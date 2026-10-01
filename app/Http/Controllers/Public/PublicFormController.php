<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Library\Forms\Exceptions\FormUnavailableException;
use App\Library\Forms\FormDeploymentContext;
use App\Library\Forms\FormDeploymentResolver;
use App\Library\Forms\FormOperationToken;
use App\Library\Forms\FormSessionStore;
use App\Library\Forms\FormSubmissionService;
use App\Models\FormSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
 * ONE FLOW, ONE VERSION. Starting a form issues ONE operation token, pinned to
 * the version shown (FormOperationToken). A one-page form is a single GET and
 * POST. A questionnaire carries the same token through every page: the page URL
 * embeds it, the server holds the answers in a session (FormSessionStore) rather
 * than trusting the browser to carry them, and next/back never switch version
 * even if the owner publishes a newer one meanwhile. Moving between pages creates
 * no Contact, Opportunity, event or submission — only the final POST does.
 *
 * A stale, forged or out-of-order page address is a 404; a token that does not
 * verify, belongs to another deployment, or names a version that is not a version
 * of this Form is a 404 on GET and a validation refusal on POST.
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
        private readonly FormSessionStore $sessions,
    ) {
    }

    /** The start of a form or questionnaire: issues the token, shows the FIRST page. */
    public function show(string $deploymentUid): View
    {
        $context = $this->resolveOrNotFound($deploymentUid);

        return $this->renderPage($context, FormOperationToken::issue($context->deployment, $context->version), $context->version->pageKeys()[0], []);
    }

    /** A later (or revisited) page of a questionnaire. */
    public function page(string $deploymentUid, string $token, string $pageKey): View
    {
        $context = $this->pinnedOrNotFound($deploymentUid, $token);

        if (! $context->version->isMultiPage()) {
            abort(404);
        }

        $claims = FormOperationToken::claims($context->deployment, $token);
        $session = $this->sessions->find($context, $claims['nonce']);

        // Opening a page needs every earlier page completed: a stale, skipped or
        // invented page address fails closed.
        abort_unless($this->sessions->canOpen($context, $session, $pageKey), 404);

        return $this->renderPage($context, $token, $pageKey, $session?->answers ?? []);
    }

    public function submit(Request $request, string $deploymentUid): RedirectResponse
    {
        // Authority first, before any guest input is inspected.
        $this->resolveOrNotFound($deploymentUid);

        if (trim((string) $request->input(FormSubmissionService::HONEYPOT_FIELD)) !== '') {
            return redirect()->route('public.forms.thanks', [$deploymentUid]);
        }

        try {
            $result = $this->submissions->submit($deploymentUid, $request->all());
        } catch (FormUnavailableException) {
            abort(404);
        }

        if (! $result->isFinal()) {
            return redirect()->route('public.forms.page', [
                $deploymentUid, (string) $request->input(FormSubmissionService::TOKEN_FIELD), $result->nextPage,
            ]);
        }

        return redirect()->route('public.forms.thanks', ['deploymentUid' => $deploymentUid, 's' => $result->submission->uid]);
    }

    public function thanks(Request $request, string $deploymentUid): View
    {
        $context = $this->resolveOrNotFound($deploymentUid);

        // Show the thank-you of the version the visitor actually completed, when
        // the submission is named and genuinely belongs to this deployment.
        $submitted = $request->query('s');
        $version = is_string($submitted) && Str::isUuid($submitted)
            ? FormSubmission::query()->where('uid', $submitted)->where('form_deployment_id', $context->deployment->id)->first()?->version
            : null;

        return view('public.forms.thanks', ['context' => $context, 'version' => $version ?? $context->version]);
    }

    /**
     * @param  array<string, mixed>  $answers  the server-held answers to pre-fill
     */
    private function renderPage(FormDeploymentContext $context, string $token, string $pageKey, array $answers): View
    {
        $version = $context->version;
        $index = $version->pageIndex($pageKey) ?? abort(404);
        $pages = $version->pages();

        return view('public.forms.show', [
            'context' => $context,
            'version' => $version,
            'token' => $token,
            'tokenField' => FormSubmissionService::TOKEN_FIELD,
            'pageField' => FormSubmissionService::PAGE_FIELD,
            'honeypot' => FormSubmissionService::HONEYPOT_FIELD,
            'pageKey' => $pageKey,
            'page' => $pages[$index],
            'pageNumber' => $index + 1,
            'pageCount' => count($pages),
            'isLast' => $index === count($pages) - 1,
            'fields' => $version->fieldsOnPage($pageKey),
            'answers' => $answers,
            'backUrl' => $index > 0
                ? route('public.forms.page', [$context->deployment->uid, $token, $pages[$index - 1]['key']])
                : null,
        ]);
    }

    private function resolveOrNotFound(string $deploymentUid): FormDeploymentContext
    {
        try {
            return $this->resolver->resolve($deploymentUid);
        } catch (FormUnavailableException) {
            abort(404);
        }
    }

    /** Current authority AND a token that verifies for a version of this Form; otherwise 404. */
    private function pinnedOrNotFound(string $deploymentUid, string $token): FormDeploymentContext
    {
        $context = $this->resolveOrNotFound($deploymentUid);
        $claims = FormOperationToken::claims($context->deployment, $token) ?? abort(404);

        try {
            return $this->resolver->pin($context, $claims['version_id']);
        } catch (FormUnavailableException) {
            abort(404);
        }
    }
}
