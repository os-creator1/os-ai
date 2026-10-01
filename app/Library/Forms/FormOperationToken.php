<?php

namespace App\Library\Forms;

use App\Models\FormDeployment;
use App\Models\FormVersion;

/**
 * Forms V1 — the one-per-start, VERSION-PINNED operation identity.
 *
 * ONE STARTED FORM OR QUESTIONNAIRE = ONE LOGICAL SUBMISSION. When a form is
 * first shown it is issued a fresh token; the browser posts it back with every
 * step. Everything that must "converge" — a double-click, a retry, two tabs of
 * the same start, concurrent requests — carries the SAME token and so maps to the
 * one `form_submissions` row whose unique (deployment, nonce) it claims. A visitor
 * who starts twice holds two tokens, so two genuine submissions with identical
 * text stay separate.
 *
 * THE TOKEN PINS THE VERSION. It is `<nonce>.<form_version_id>.<hmac>`, the HMAC
 * covering the deployment uid, the immutable version id and the nonce, keyed by
 * the application key. So:
 *
 *   - the version the visitor SAW travels inside the authenticated token, never as
 *     a bare posted id the server would have to trust: change either the nonce or
 *     the version and the signature no longer verifies;
 *   - a token issued for one deployment is refused at another;
 *   - an owner publishing version N+1 after the start cannot reinterpret the
 *     visitor's flow — the server re-reads version N by id and validates and
 *     persists against exactly that.
 *
 * Pinning is identity, NOT authority: the caller (FormDeploymentResolver, then
 * FormSubmissionService) still re-proves on every request that the version
 * belongs to the deployment's Form and that the Form, deployment, Location,
 * Business and account may CURRENTLY accept submissions. A lost entitlement or a
 * switched-off form refuses an old, validly signed token.
 *
 * Stateless but not forgeable; the database uniqueness — not this class — is what
 * makes a replay converge.
 */
final class FormOperationToken
{
    private const NONCE_BYTES = 16;

    /**
     * @param  ?FormVersion  $version  the version being shown; null pins the form's current version
     */
    public static function issue(FormDeployment $deployment, ?FormVersion $version = null): string
    {
        $version ??= FormVersion::query()
            ->where('form_id', $deployment->form_id)
            ->orderByDesc('version')
            ->firstOrFail();

        $nonce = bin2hex(random_bytes(self::NONCE_BYTES));

        return $nonce.'.'.$version->id.'.'.self::signature($deployment->uid, (int) $version->id, $nonce);
    }

    /**
     * @return ?array{nonce: string, version_id: int} the claims when the token is genuine for this deployment, otherwise null
     */
    public static function claims(FormDeployment $deployment, mixed $token): ?array
    {
        if (! is_string($token) || substr_count($token, '.') !== 2) {
            return null;
        }

        [$nonce, $versionId, $signature] = explode('.', $token, 3);

        if (preg_match('/^[0-9a-f]{'.(self::NONCE_BYTES * 2).'}$/', $nonce) !== 1 || preg_match('/^[1-9][0-9]{0,18}$/', $versionId) !== 1) {
            return null;
        }

        if (! hash_equals(self::signature($deployment->uid, (int) $versionId, $nonce), $signature)) {
            return null;
        }

        return ['nonce' => $nonce, 'version_id' => (int) $versionId];
    }

    private static function signature(string $deploymentUid, int $versionId, string $nonce): string
    {
        return hash_hmac('sha256', 'form-operation|'.$deploymentUid.'|'.$versionId.'|'.$nonce, (string) config('app.key'));
    }
}
