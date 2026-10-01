<?php

namespace App\Library\Forms;

use App\Models\FormDeployment;

/**
 * Forms V1 — the one-per-render operation token.
 *
 * ONE RENDERED FORM = ONE LOGICAL SUBMISSION. When the public form is rendered
 * it is issued a fresh token; the browser posts it back with the answers.
 * Everything that must "converge" — a double-click, a network retry, two tabs
 * of the same render, concurrent requests — carries the SAME token and so maps
 * to the one `form_submissions` row whose unique
 * (form_deployment_id, operation_nonce) it claims. A visitor who loads the form
 * twice holds two tokens, so two genuine submissions with identical text stay
 * separate, which is exactly the rule.
 *
 * STATELESS BUT NOT FORGEABLE. The token is `<nonce>.<hmac>`, where the HMAC
 * covers the deployment's uid and the nonce, keyed by the application key. No
 * table is needed to remember issued tokens, yet a token cannot be invented, and
 * a token issued for one deployment is refused at another. The database
 * uniqueness — not this class — is what makes a replay converge; this class only
 * proves a nonce came from us and belongs to this deployment.
 */
final class FormOperationToken
{
    private const NONCE_BYTES = 16;

    public static function issue(FormDeployment $deployment): string
    {
        $nonce = bin2hex(random_bytes(self::NONCE_BYTES));

        return $nonce.'.'.self::signature($deployment->uid, $nonce);
    }

    /**
     * @return ?string the nonce when the token is genuine for this deployment, otherwise null
     */
    public static function nonce(FormDeployment $deployment, mixed $token): ?string
    {
        if (! is_string($token) || substr_count($token, '.') !== 1) {
            return null;
        }

        [$nonce, $signature] = explode('.', $token, 2);

        if (preg_match('/^[0-9a-f]{'.(self::NONCE_BYTES * 2).'}$/', $nonce) !== 1) {
            return null;
        }

        return hash_equals(self::signature($deployment->uid, $nonce), $signature) ? $nonce : null;
    }

    private static function signature(string $deploymentUid, string $nonce): string
    {
        return hash_hmac('sha256', 'form-operation|'.$deploymentUid.'|'.$nonce, (string) config('app.key'));
    }
}
