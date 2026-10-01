<?php

namespace App\Library\BusinessEmail;

use App\DTO\BusinessEmail\BusinessEmailOutbound;
use App\DTO\BusinessEmail\BusinessEmailProviderCapabilities;
use App\DTO\BusinessEmail\BusinessEmailProviderSendResult;
use App\DTO\BusinessEmail\BusinessEmailTokenGrant;
use App\Enums\BusinessEmail\BusinessEmailFailureCategory;
use App\Enums\BusinessEmail\BusinessEmailProviderType;
use App\Exceptions\BusinessEmail\BusinessEmailProviderException;
use App\Library\BusinessEmail\Contracts\BusinessEmailProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Microsoft / Outlook / Microsoft 365: the Microsoft identity platform
 * (OAuth 2.0 v2) + Microsoft Graph. Direct HTTP, no SDK.
 *
 * Scopes (config/business_email.php): `offline_access` (refresh token),
 * `User.Read` (identify the mailbox) and `Mail.Send`. NO Mail.Read.
 *
 * SENDING is draft-then-send: `POST /me/messages` creates a draft and returns
 * the provider message id, conversation (thread) id and internetMessageId;
 * `POST /me/messages/{id}/send` then sends it. (Graph's one-shot `sendMail`
 * returns 202 with no identifiers, which would leave nothing to correlate.)
 * If the draft is created but the send step is ambiguous, the draft id is NOT
 * retried by a new send: the sender records `unconfirmed`.
 *
 * Microsoft exposes no public refresh-token revocation endpoint for a
 * delegated app, so revokeGrant() is a deliberate no-op: disconnecting
 * destroys the stored token locally, and the user can remove the app from
 * their Microsoft account.
 */
final class MicrosoftBusinessEmailProvider implements BusinessEmailProvider
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0';

    private const SEND_SCOPE = 'mail.send';

    public function __construct(private readonly BusinessEmailOAuthConfig $config)
    {
    }

    public function provider(): BusinessEmailProviderType
    {
        return BusinessEmailProviderType::Microsoft;
    }

    public function capabilities(): BusinessEmailProviderCapabilities
    {
        return new BusinessEmailProviderCapabilities(revocableGrant: false);
    }

    public function authorizationUrl(string $state, bool $forceConsent): string
    {
        $query = [
            'client_id' => $this->config->clientId(BusinessEmailProviderType::Microsoft),
            'response_type' => 'code',
            'redirect_uri' => $this->config->redirect(BusinessEmailProviderType::Microsoft),
            'response_mode' => 'query',
            'scope' => implode(' ', (array) config('business_email.microsoft.scopes')),
            'state' => $state,
        ];

        if ($forceConsent) {
            $query['prompt'] = 'consent';
        }

        return $this->tenantBase() . '/authorize?' . http_build_query($query);
    }

    public function exchangeAuthorizationCode(string $code): BusinessEmailTokenGrant
    {
        $response = $this->postForm($this->tenantBase() . '/token', [
            'code' => $code,
            'client_id' => $this->config->clientId(BusinessEmailProviderType::Microsoft),
            'client_secret' => $this->config->clientSecret(BusinessEmailProviderType::Microsoft),
            'redirect_uri' => $this->config->redirect(BusinessEmailProviderType::Microsoft),
            'grant_type' => 'authorization_code',
            'scope' => implode(' ', (array) config('business_email.microsoft.scopes')),
        ]);

        $accessToken = (string) ($response['access_token'] ?? '');
        $scopes = isset($response['scope']) ? (string) $response['scope'] : null;

        if ($accessToken === '') {
            throw new BusinessEmailProviderException(BusinessEmailFailureCategory::PermanentProviderFailure, 'malformed_token_response');
        }

        $granted = array_map('strtolower', preg_split('/\s+/', (string) $scopes) ?: []);

        if (! in_array(self::SEND_SCOPE, $granted, true)) {
            throw new BusinessEmailProviderException(BusinessEmailFailureCategory::PermissionDenied, 'send_scope_not_granted');
        }

        $profile = $this->getJson(self::GRAPH . '/me?$select=id,displayName,mail,userPrincipalName', $accessToken);

        $email = strtolower(trim((string) ($profile['mail'] ?? $profile['userPrincipalName'] ?? '')));

        if ($email === '') {
            throw new BusinessEmailProviderException(BusinessEmailFailureCategory::PermanentProviderFailure, 'mailbox_not_identified');
        }

        return new BusinessEmailTokenGrant(
            accessToken: $accessToken,
            refreshToken: isset($response['refresh_token']) ? (string) $response['refresh_token'] : null,
            grantedScopes: $scopes,
            accountEmail: $email,
            accountId: isset($profile['id']) ? (string) $profile['id'] : null,
            displayName: isset($profile['displayName']) ? (string) $profile['displayName'] : null,
        );
    }

    public function exchangeRefreshToken(string $refreshToken): BusinessEmailTokenGrant
    {
        $response = $this->postForm($this->tenantBase() . '/token', [
            'refresh_token' => $refreshToken,
            'client_id' => $this->config->clientId(BusinessEmailProviderType::Microsoft),
            'client_secret' => $this->config->clientSecret(BusinessEmailProviderType::Microsoft),
            'grant_type' => 'refresh_token',
            'scope' => implode(' ', (array) config('business_email.microsoft.scopes')),
        ]);

        $accessToken = (string) ($response['access_token'] ?? '');

        if ($accessToken === '') {
            throw new BusinessEmailProviderException(BusinessEmailFailureCategory::PermanentProviderFailure, 'malformed_token_response');
        }

        return new BusinessEmailTokenGrant(
            accessToken: $accessToken,
            // Graph ROTATES refresh tokens; the manager re-encrypts a new one.
            refreshToken: isset($response['refresh_token']) ? (string) $response['refresh_token'] : null,
            grantedScopes: isset($response['scope']) ? (string) $response['scope'] : null,
        );
    }

    public function send(string $accessToken, BusinessEmailOutbound $email): BusinessEmailProviderSendResult
    {
        // Step 1 — create the draft. Nothing has been sent yet, so any
        // failure here is an ordinary, safely-retryable failure.
        try {
            $draft = $this->http($accessToken)->asJson()->post(self::GRAPH . '/me/messages', [
                'subject' => $email->subject,
                'body' => ['contentType' => 'Text', 'content' => $email->bodyText],
                'toRecipients' => [['emailAddress' => ['address' => $email->toEmail]]],
            ]);
        } catch (ConnectionException $exception) {
            throw BusinessEmailProviderErrorMapper::fromTransport($exception, requestSent: false);
        }

        if (! $draft->successful()) {
            throw $this->failure($draft);
        }

        $draftJson = (array) $draft->json();
        $messageId = isset($draftJson['id']) ? (string) $draftJson['id'] : '';

        if ($messageId === '') {
            throw new BusinessEmailProviderException(BusinessEmailFailureCategory::PermanentProviderFailure, 'malformed_draft_response');
        }

        // Step 2 — send it. From here the message can leave the building, so
        // a timeout mid-flight is ambiguous.
        try {
            $sent = $this->http($accessToken)->asJson()->post(self::GRAPH . '/me/messages/' . rawurlencode($messageId) . '/send');
        } catch (ConnectionException $exception) {
            throw BusinessEmailProviderErrorMapper::fromTransport($exception, requestSent: true);
        }

        if (! $sent->successful()) {
            throw $this->failure($sent);
        }

        return new BusinessEmailProviderSendResult(
            providerMessageId: $messageId,
            providerThreadId: isset($draftJson['conversationId']) ? (string) $draftJson['conversationId'] : null,
            internetMessageId: isset($draftJson['internetMessageId']) ? (string) $draftJson['internetMessageId'] : null,
        );
    }

    public function revokeGrant(string $refreshToken): void
    {
        // Intentionally a no-op — see the class docblock.
    }

    private function tenantBase(): string
    {
        $tenant = (string) config('business_email.microsoft.tenant', 'common');

        // The tenant is a path segment: allow only an id, domain or the
        // well-known aliases, never anything that could alter the URL.
        if (preg_match('/^[A-Za-z0-9.-]{1,128}$/', $tenant) !== 1) {
            $tenant = 'common';
        }

        return 'https://login.microsoftonline.com/' . $tenant . '/oauth2/v2.0';
    }

    /** @return array<string, mixed> */
    private function postForm(string $url, array $form): array
    {
        try {
            $response = Http::asForm()
                ->connectTimeout($this->connectTimeout())
                ->timeout($this->requestTimeout())
                ->post($url, $form);
        } catch (ConnectionException $exception) {
            throw BusinessEmailProviderErrorMapper::fromTransport($exception, requestSent: false);
        }

        if (! $response->successful()) {
            throw $this->failure($response, tokenEndpoint: true);
        }

        return (array) $response->json();
    }

    /** @return array<string, mixed> */
    private function getJson(string $url, string $accessToken): array
    {
        try {
            $response = $this->http($accessToken)->get($url);
        } catch (ConnectionException $exception) {
            throw BusinessEmailProviderErrorMapper::fromTransport($exception, requestSent: false);
        }

        if (! $response->successful()) {
            throw $this->failure($response);
        }

        return (array) $response->json();
    }

    private function http(string $accessToken): PendingRequest
    {
        return Http::withToken($accessToken)
            ->acceptJson()
            ->connectTimeout($this->connectTimeout())
            ->timeout($this->requestTimeout());
    }

    private function failure(Response $response, bool $tokenEndpoint = false): BusinessEmailProviderException
    {
        $json = (array) $response->json();
        $error = $json['error'] ?? null;
        $code = is_string($error) ? $error : (is_array($error) ? ($error['code'] ?? null) : null);

        $recipientProblem = is_string($code) && preg_match('/recipient|smtpaddress/i', $code) === 1;

        return BusinessEmailProviderErrorMapper::fromHttp(
            $response->status(),
            is_string($code) ? $code : null,
            $recipientProblem,
            $tokenEndpoint,
        );
    }

    private function connectTimeout(): int
    {
        return max(1, (int) config('business_email.http.connect_timeout_seconds', 5));
    }

    private function requestTimeout(): int
    {
        return max(1, (int) config('business_email.http.request_timeout_seconds', 20));
    }
}
