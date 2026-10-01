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
use Illuminate\Support\Str;

/**
 * Google / Gmail / Google Workspace: OAuth 2.0 + the Gmail API
 * (`users.messages.send`). Direct HTTP, no Google SDK, so every response
 * shape is normalized at this one boundary.
 *
 * Scopes (config/business_email.php): `openid email` to identify the mailbox
 * and `gmail.send` to send. NO mailbox-read scope. Google lets a user untick
 * individual permissions on the consent screen, so a grant that came back
 * WITHOUT gmail.send fails closed (PermissionDenied) rather than activating
 * an account that cannot send.
 */
final class GoogleBusinessEmailProvider implements BusinessEmailProvider
{
    private const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const REVOKE_ENDPOINT = 'https://oauth2.googleapis.com/revoke';

    private const USERINFO_ENDPOINT = 'https://openidconnect.googleapis.com/v1/userinfo';

    private const SEND_ENDPOINT = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

    private const SEND_SCOPE = 'https://www.googleapis.com/auth/gmail.send';

    public function __construct(private readonly BusinessEmailOAuthConfig $config)
    {
    }

    public function provider(): BusinessEmailProviderType
    {
        return BusinessEmailProviderType::Google;
    }

    public function capabilities(): BusinessEmailProviderCapabilities
    {
        return new BusinessEmailProviderCapabilities(revocableGrant: true);
    }

    public function authorizationUrl(string $state, bool $forceConsent): string
    {
        $query = [
            'client_id' => $this->config->clientId(BusinessEmailProviderType::Google),
            'redirect_uri' => $this->config->redirect(BusinessEmailProviderType::Google),
            'response_type' => 'code',
            'scope' => implode(' ', (array) config('business_email.google.scopes')),
            'access_type' => 'offline',
            'state' => $state,
        ];

        if ($forceConsent) {
            // Google returns a refresh token only on a fresh consent.
            $query['prompt'] = 'consent';
        }

        return self::AUTH_ENDPOINT . '?' . http_build_query($query);
    }

    public function exchangeAuthorizationCode(string $code): BusinessEmailTokenGrant
    {
        $response = $this->postForm(self::TOKEN_ENDPOINT, [
            'code' => $code,
            'client_id' => $this->config->clientId(BusinessEmailProviderType::Google),
            'client_secret' => $this->config->clientSecret(BusinessEmailProviderType::Google),
            'redirect_uri' => $this->config->redirect(BusinessEmailProviderType::Google),
            'grant_type' => 'authorization_code',
        ], tokenEndpoint: true);

        $accessToken = (string) ($response['access_token'] ?? '');
        $scopes = isset($response['scope']) ? (string) $response['scope'] : null;

        if ($accessToken === '') {
            throw new BusinessEmailProviderException(BusinessEmailFailureCategory::PermanentProviderFailure, 'malformed_token_response');
        }

        if (! in_array(self::SEND_SCOPE, preg_split('/\s+/', (string) $scopes) ?: [], true)) {
            throw new BusinessEmailProviderException(BusinessEmailFailureCategory::PermissionDenied, 'send_scope_not_granted');
        }

        $profile = $this->getJson(self::USERINFO_ENDPOINT, $accessToken);

        $email = isset($profile['email']) ? strtolower(trim((string) $profile['email'])) : '';

        if ($email === '') {
            throw new BusinessEmailProviderException(BusinessEmailFailureCategory::PermanentProviderFailure, 'mailbox_not_identified');
        }

        return new BusinessEmailTokenGrant(
            accessToken: $accessToken,
            refreshToken: isset($response['refresh_token']) ? (string) $response['refresh_token'] : null,
            grantedScopes: $scopes,
            accountEmail: $email,
            accountId: isset($profile['sub']) ? (string) $profile['sub'] : null,
            displayName: isset($profile['name']) ? (string) $profile['name'] : null,
        );
    }

    public function exchangeRefreshToken(string $refreshToken): BusinessEmailTokenGrant
    {
        $response = $this->postForm(self::TOKEN_ENDPOINT, [
            'refresh_token' => $refreshToken,
            'client_id' => $this->config->clientId(BusinessEmailProviderType::Google),
            'client_secret' => $this->config->clientSecret(BusinessEmailProviderType::Google),
            'grant_type' => 'refresh_token',
        ], tokenEndpoint: true);

        $accessToken = (string) ($response['access_token'] ?? '');

        if ($accessToken === '') {
            throw new BusinessEmailProviderException(BusinessEmailFailureCategory::PermanentProviderFailure, 'malformed_token_response');
        }

        return new BusinessEmailTokenGrant(
            accessToken: $accessToken,
            // Google normally does not rotate the refresh token.
            refreshToken: isset($response['refresh_token']) ? (string) $response['refresh_token'] : null,
            grantedScopes: isset($response['scope']) ? (string) $response['scope'] : null,
        );
    }

    public function send(string $accessToken, BusinessEmailOutbound $email): BusinessEmailProviderSendResult
    {
        [$mime, $messageId] = $this->buildMime($email);

        try {
            $response = $this->http($accessToken)->asJson()->post(self::SEND_ENDPOINT, [
                'raw' => rtrim(strtr(base64_encode($mime), '+/', '-_'), '='),
            ]);
        } catch (ConnectionException $exception) {
            throw BusinessEmailProviderErrorMapper::fromTransport($exception, requestSent: true);
        }

        if (! $response->successful()) {
            throw $this->failure($response);
        }

        $json = (array) $response->json();

        return new BusinessEmailProviderSendResult(
            providerMessageId: isset($json['id']) ? (string) $json['id'] : null,
            providerThreadId: isset($json['threadId']) ? (string) $json['threadId'] : null,
            // Gmail may replace our Message-ID; keep the one we asked for as
            // the best available internet identity, never as a delivery claim.
            internetMessageId: $messageId,
        );
    }

    public function revokeGrant(string $refreshToken): void
    {
        try {
            Http::asForm()
                ->connectTimeout($this->connectTimeout())
                ->timeout($this->requestTimeout())
                ->post(self::REVOKE_ENDPOINT, ['token' => $refreshToken]);
        } catch (\Throwable) {
            // Best effort: local destruction of the credential proceeds regardless.
        }
    }

    /**
     * RFC 5322 plain-text message. Every header value has control characters
     * removed so a subject/name/address can never inject another header.
     *
     * @return array{0: string, 1: string} [raw MIME, Message-ID]
     */
    public function buildMime(BusinessEmailOutbound $email): array
    {
        $from = $this->clean($email->fromEmail);

        // The Message-ID domain is restricted to hostname characters, so a
        // mailbox string can never smuggle anything into this header.
        $domain = (string) preg_replace('/[^A-Za-z0-9.-]/', '', Str::afterLast($from, '@'));
        $messageId = '<' . Str::uuid() . '@' . ($domain !== '' ? $domain : 'localhost') . '>';

        if ($email->fromName !== null && trim($email->fromName) !== '') {
            $from = $this->encodeWord($this->clean($email->fromName)) . ' <' . $from . '>';
        }

        $headers = [
            'From: ' . $from,
            'To: ' . $this->clean($email->toEmail),
            'Subject: ' . $this->encodeWord($this->clean($email->subject)),
            'Message-ID: ' . $messageId,
            'Date: ' . now()->toRfc2822String(),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];

        $body = chunk_split(base64_encode($email->bodyText), 76, "\r\n");

        return [implode("\r\n", $headers) . "\r\n\r\n" . $body, $messageId];
    }

    private function clean(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value));
    }

    private function encodeWord(string $value): string
    {
        return preg_match('/^[\x20-\x7E]*$/', $value) === 1 && ! str_contains($value, '=?')
            ? $value
            : '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /** @return array<string, mixed> */
    private function postForm(string $url, array $form, bool $tokenEndpoint): array
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
            throw $this->failure($response, $tokenEndpoint);
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
        $code = null;
        $message = '';

        if (is_string($error)) {
            $code = $error;
        } elseif (is_array($error)) {
            $code = $error['errors'][0]['reason'] ?? ($error['status'] ?? null);
            $message = (string) ($error['message'] ?? '');
        }

        $recipientProblem = $message !== '' && preg_match('/invalid to header|recipient|invalid address/i', $message) === 1;

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
