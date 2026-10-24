<?php

namespace App\Library\BusinessEmail;

use App\DTO\BusinessEmail\BusinessEmailInboundBatch;
use App\DTO\BusinessEmail\BusinessEmailInboundMessage;
use App\DTO\BusinessEmail\BusinessEmailOutbound;
use App\DTO\BusinessEmail\BusinessEmailProviderCapabilities;
use App\DTO\BusinessEmail\BusinessEmailProviderSendResult;
use App\DTO\BusinessEmail\BusinessEmailTokenGrant;
use App\Enums\BusinessEmail\BusinessEmailFailureCategory;
use App\Enums\BusinessEmail\BusinessEmailProviderType;
use App\Exceptions\BusinessEmail\BusinessEmailProviderException;
use App\Library\BusinessEmail\Contracts\BusinessEmailProvider;
use App\Library\BusinessEmail\Contracts\InboundBusinessEmailProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Microsoft / Outlook / Microsoft 365: the Microsoft identity platform
 * (OAuth 2.0 v2) + Microsoft Graph. Direct HTTP, no SDK.
 *
 * Scopes (config/business_email.php): `offline_access` (refresh token),
 * `User.Read` (identify the mailbox), `Mail.Send` and `Mail.Read` (inbound
 * sync). A grant without Mail.Read still connects for sending; inbound stays
 * off until the owner reauthorizes.
 *
 * INBOUND uses Graph delta on the Inbox folder, one server page per run. The
 * cursor is the Graph `nextLink` / `deltaLink` URL itself; it is only ever
 * followed if it points at graph.microsoft.com, so the bearer token can never
 * be sent to another host. Ids are requested as ImmutableId so a stored
 * message id survives folder moves (Drafts → Sent Items) and can be replied to.
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
final class MicrosoftBusinessEmailProvider implements BusinessEmailProvider, InboundBusinessEmailProvider
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0';

    private const SEND_SCOPE = 'mail.send';

    private const READ_SCOPE = 'Mail.Read';

    private const SELECT = 'id,conversationId,internetMessageId,subject,from,toRecipients,body,receivedDateTime,isDraft,hasAttachments';

    public function __construct(private readonly BusinessEmailOAuthConfig $config)
    {
    }

    public function provider(): BusinessEmailProviderType
    {
        return BusinessEmailProviderType::Microsoft;
    }

    public function capabilities(): BusinessEmailProviderCapabilities
    {
        return new BusinessEmailProviderCapabilities(inboundSync: true, revocableGrant: false);
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
        if ($email->replyToProviderMessageId !== null && $email->replyToProviderMessageId !== '') {
            $reply = $this->sendAsReply($accessToken, $email);

            if ($reply !== null) {
                return $reply;
            }
            // The original is no longer addressable: fall through to a plain
            // message rather than failing the customer's reply.
        }

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

    /**
     * Threaded reply: createReply (keeps Graph's conversation and RFC headers),
     * then address it to the stored counterpart and set our text, then send.
     * Returns null ONLY when the original message cannot be found (nothing was
     * created or sent), so the caller can fall back to a plain message.
     */
    private function sendAsReply(string $accessToken, BusinessEmailOutbound $email): ?BusinessEmailProviderSendResult
    {
        try {
            $created = $this->http($accessToken)->asJson()->post(self::GRAPH . '/me/messages/' . rawurlencode((string) $email->replyToProviderMessageId) . '/createReply');
        } catch (ConnectionException $exception) {
            throw BusinessEmailProviderErrorMapper::fromTransport($exception, requestSent: false);
        }

        if (! $created->successful()) {
            $failure = $this->failure($created);

            if ($this->isNotFound($failure)) {
                return null;
            }

            throw $failure;
        }

        $draft = (array) $created->json();
        $draftId = isset($draft['id']) ? (string) $draft['id'] : '';

        if ($draftId === '') {
            throw new BusinessEmailProviderException(BusinessEmailFailureCategory::PermanentProviderFailure, 'malformed_draft_response');
        }

        try {
            $patched = $this->http($accessToken)->asJson()->patch(self::GRAPH . '/me/messages/' . rawurlencode($draftId), [
                'subject' => $email->subject,
                'body' => ['contentType' => 'Text', 'content' => $email->bodyText],
                'toRecipients' => [['emailAddress' => ['address' => $email->toEmail]]],
            ]);
        } catch (ConnectionException $exception) {
            throw BusinessEmailProviderErrorMapper::fromTransport($exception, requestSent: false);
        }

        if (! $patched->successful()) {
            throw $this->failure($patched);
        }

        $patchedJson = (array) $patched->json();

        try {
            $sent = $this->http($accessToken)->asJson()->post(self::GRAPH . '/me/messages/' . rawurlencode($draftId) . '/send');
        } catch (ConnectionException $exception) {
            throw BusinessEmailProviderErrorMapper::fromTransport($exception, requestSent: true);
        }

        if (! $sent->successful()) {
            throw $this->failure($sent);
        }

        return new BusinessEmailProviderSendResult(
            providerMessageId: $draftId,
            providerThreadId: (string) ($patchedJson['conversationId'] ?? $draft['conversationId'] ?? '') ?: $email->threadId,
            internetMessageId: (string) ($patchedJson['internetMessageId'] ?? $draft['internetMessageId'] ?? '') ?: null,
        );
    }

    public function inboundScope(): string
    {
        return self::READ_SCOPE;
    }

    public function fetchInbound(string $accessToken, ?string $cursor, int $limit): BusinessEmailInboundBatch
    {
        $limit = max(1, $limit);
        $url = $this->isGraphUrl($cursor) ? (string) $cursor : $this->initialDeltaUrl();

        try {
            $json = $this->getJson($url, $accessToken, $limit);
        } catch (BusinessEmailProviderException $exception) {
            // An expired delta token (410 / syncStateNotFound / resyncRequired)
            // restarts from the bounded initial window; stored ids dedupe.
            if ($cursor === null || ! $this->isSyncStateGone($exception)) {
                throw $exception;
            }

            $json = $this->getJson($this->initialDeltaUrl(), $accessToken, $limit);
        }

        $messages = [];
        $unparsed = [];

        foreach ((array) ($json['value'] ?? []) as $item) {
            if (! is_array($item) || isset($item['@removed'])) {
                continue;
            }

            try {
                $message = $this->parseMessage($item);
            } catch (\Throwable) {
                $unparsed[] = substr((string) ($item['id'] ?? 'unknown'), 0, 191);

                continue;
            }

            if ($message !== null) {
                $messages[] = $message;
            }
        }

        $next = isset($json['@odata.nextLink']) ? (string) $json['@odata.nextLink'] : null;
        $delta = isset($json['@odata.deltaLink']) ? (string) $json['@odata.deltaLink'] : null;

        // Only a Graph URL is ever persisted as a cursor (and later followed).
        if ($next !== null && $this->isGraphUrl($next)) {
            return new BusinessEmailInboundBatch($messages, $next, true, $unparsed);
        }

        return new BusinessEmailInboundBatch($messages, $delta !== null && $this->isGraphUrl($delta) ? $delta : $cursor, false, $unparsed);
    }

    /**
     * @param array<string, mixed> $item a Graph `message` resource
     */
    public function parseMessage(array $item): ?BusinessEmailInboundMessage
    {
        $id = (string) ($item['id'] ?? '');

        if ($id === '' || ($item['isDraft'] ?? false) === true) {
            return null;
        }

        $fromEmail = BusinessEmailContactResolver::normalize((string) ($item['from']['emailAddress']['address'] ?? ''));

        if ($fromEmail === null) {
            return null;
        }

        $toEmail = BusinessEmailContactResolver::normalize((string) ($item['toRecipients'][0]['emailAddress']['address'] ?? '')) ?? '';

        $content = InboundText::utf8((string) ($item['body']['content'] ?? ''));
        $text = strtolower((string) ($item['body']['contentType'] ?? 'text')) === 'html' ? InboundText::htmlToText($content) : trim($content);

        $received = isset($item['receivedDateTime']) ? date_create((string) $item['receivedDateTime']) : false;

        return new BusinessEmailInboundMessage(
            providerMessageId: substr($id, 0, 191),
            providerThreadId: isset($item['conversationId']) ? substr((string) $item['conversationId'], 0, 191) : null,
            internetMessageId: ($mid = InboundText::headerLine($item['internetMessageId'] ?? null, 255)) !== '' ? $mid : null,
            inReplyTo: null,
            references: null,
            fromEmail: $fromEmail,
            fromName: ($name = InboundText::headerLine($item['from']['emailAddress']['name'] ?? null, 191)) !== '' ? $name : null,
            toEmail: $toEmail,
            subject: mb_substr(InboundText::headerLine($item['subject'] ?? null, 1020), 0, 255),
            bodyText: InboundText::body($text),
            receivedAt: $received !== false ? $received : null,
            labels: ['INBOX'],
        );
    }

    private function initialDeltaUrl(): string
    {
        $days = max(1, (int) config('business_email.inbound.initial_days', 7));
        $since = gmdate('Y-m-d\TH:i:s\Z', time() - $days * 86400);

        return self::GRAPH . '/me/mailFolders/inbox/messages/delta?' . http_build_query([
            '$select' => self::SELECT,
            '$filter' => 'receivedDateTime ge ' . $since,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function isGraphUrl(?string $url): bool
    {
        if ($url === null || $url === '') {
            return false;
        }

        $parts = parse_url($url);

        return $parts !== false
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === 'graph.microsoft.com'
            && ! isset($parts['user'], $parts['port']);
    }

    private function isNotFound(BusinessEmailProviderException $exception): bool
    {
        return in_array(strtolower((string) $exception->providerCode), ['erroritemnotfound', 'resourcenotfound', 'itemnotfound', 'errorinvalidid', 'errorinvalidmailboxitemid', '404'], true);
    }

    private function isSyncStateGone(BusinessEmailProviderException $exception): bool
    {
        $code = strtolower((string) $exception->providerCode);

        return str_contains($code, 'syncstate') || str_contains($code, 'resync') || $code === '410' || $code === 'gone';
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
    private function getJson(string $url, string $accessToken, ?int $pageSize = null): array
    {
        try {
            $request = $this->http($accessToken);

            if ($pageSize !== null) {
                // One bounded server page per run; plain-text bodies.
                $request = $request->withHeaders(['Prefer' => 'IdType="ImmutableId", outlook.body-content-type="text", odata.maxpagesize=' . $pageSize]);
            }

            $response = $request->get($url);
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
            ->withHeaders(['Prefer' => 'IdType="ImmutableId"'])
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
