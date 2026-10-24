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
use Illuminate\Support\Str;

/**
 * Google / Gmail / Google Workspace: OAuth 2.0 + the Gmail API
 * (`users.messages.send`). Direct HTTP, no Google SDK, so every response
 * shape is normalized at this one boundary.
 *
 * Scopes (config/business_email.php): `openid email` to identify the mailbox,
 * `gmail.send` to send and `gmail.readonly` (restricted) for inbound sync.
 * Google lets a user untick individual permissions on the consent screen, so
 * a grant that came back WITHOUT gmail.send fails closed (PermissionDenied)
 * rather than activating an account that cannot send. A grant without
 * gmail.readonly still connects for sending; inbound sync simply stays off
 * until the owner reauthorizes.
 */
final class GoogleBusinessEmailProvider implements BusinessEmailProvider, InboundBusinessEmailProvider
{
    private const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const REVOKE_ENDPOINT = 'https://oauth2.googleapis.com/revoke';

    private const USERINFO_ENDPOINT = 'https://openidconnect.googleapis.com/v1/userinfo';

    private const SEND_ENDPOINT = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

    private const API = 'https://gmail.googleapis.com/gmail/v1/users/me';

    private const READ_SCOPE = 'https://www.googleapis.com/auth/gmail.readonly';

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
        return new BusinessEmailProviderCapabilities(inboundSync: true, revocableGrant: true);
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
            $payload = ['raw' => rtrim(strtr(base64_encode($mime), '+/', '-_'), '=')];

            // Keeps the reply in the same Gmail conversation.
            if ($email->threadId !== null && $email->threadId !== '') {
                $payload['threadId'] = $email->threadId;
            }

            $response = $this->http($accessToken)->asJson()->post(self::SEND_ENDPOINT, $payload);
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

    public function inboundScope(): string
    {
        return self::READ_SCOPE;
    }

    public function fetchInbound(string $accessToken, ?string $cursor, int $limit): BusinessEmailInboundBatch
    {
        $limit = max(1, $limit);
        $hasMore = false;

        if ($cursor === null || $cursor === '') {
            [$ids, $newCursor] = $this->initialIds($accessToken, $limit);
        } else {
            try {
                [$ids, $newCursor, $hasMore] = $this->historyIds($accessToken, $cursor, $limit);
            } catch (BusinessEmailProviderException $exception) {
                // A history id older than ~a week is gone (404): fall back to a
                // bounded resync; stored messages are skipped by their ids.
                if (! $this->isNotFound($exception)) {
                    throw $exception;
                }

                [$ids, $newCursor] = $this->initialIds($accessToken, $limit);
            }
        }

        $messages = [];

        foreach ($ids as $id) {
            $message = $this->fetchMessage($accessToken, $id);

            if ($message !== null) {
                $messages[] = $message;
            }
        }

        return new BusinessEmailInboundBatch($messages, $newCursor, $hasMore);
    }

    /** @return array{0: list<string>, 1: string|null} ids and the cursor taken BEFORE listing */
    private function initialIds(string $accessToken, int $limit): array
    {
        $profile = $this->getJson(self::API . '/profile', $accessToken);
        $cursor = isset($profile['historyId']) ? (string) $profile['historyId'] : null;

        $days = max(1, (int) config('business_email.inbound.initial_days', 7));
        $max = min($limit, max(1, (int) config('business_email.inbound.initial_max_messages', 50)));

        $list = $this->getJson(self::API . '/messages?' . http_build_query([
            'q' => 'in:inbox newer_than:' . $days . 'd',
            'maxResults' => $max,
        ]), $accessToken);

        $ids = [];

        foreach ((array) ($list['messages'] ?? []) as $row) {
            if (isset($row['id'])) {
                $ids[] = (string) $row['id'];
            }
        }

        // Oldest first, so a conversation is stored in the order it happened.
        return [array_reverse($ids), $cursor];
    }

    /** @return array{0: list<string>, 1: string|null, 2: bool} */
    private function historyIds(string $accessToken, string $cursor, int $limit): array
    {
        $json = $this->getJson(self::API . '/history?' . http_build_query([
            'startHistoryId' => $cursor,
            'historyTypes' => 'messageAdded',
            'labelId' => 'INBOX',
            'maxResults' => 100,
        ]), $accessToken);

        $ids = [];
        $lastRecord = null;

        foreach ((array) ($json['history'] ?? []) as $record) {
            $lastRecord = isset($record['id']) ? (string) $record['id'] : $lastRecord;

            foreach ((array) ($record['messagesAdded'] ?? []) as $added) {
                if (isset($added['message']['id'])) {
                    $ids[(string) $added['message']['id']] = true;
                }
            }
        }

        $ids = array_keys($ids);
        $hasMore = isset($json['nextPageToken']) || count($ids) > $limit;
        $ids = array_slice($ids, 0, $limit);

        // More pages: advance only as far as the records read; the rest is
        // returned by the next run. Otherwise the mailbox's current history id.
        $newCursor = $hasMore ? ($lastRecord ?? $cursor) : (isset($json['historyId']) ? (string) $json['historyId'] : $cursor);

        return [$ids, $newCursor, $hasMore];
    }

    private function fetchMessage(string $accessToken, string $id): ?BusinessEmailInboundMessage
    {
        try {
            $json = $this->getJson(self::API . '/messages/' . rawurlencode($id) . '?format=full', $accessToken);
        } catch (BusinessEmailProviderException $exception) {
            // Deleted between listing and fetching: nothing to import.
            if ($this->isNotFound($exception)) {
                return null;
            }

            throw $exception;
        }

        return $this->parseMessage($json);
    }

    /**
     * @param array<string, mixed> $json a Gmail `users.messages` resource, format=full
     */
    public function parseMessage(array $json): ?BusinessEmailInboundMessage
    {
        $id = (string) ($json['id'] ?? '');

        if ($id === '') {
            return null;
        }

        $headers = [];

        foreach ((array) ($json['payload']['headers'] ?? []) as $header) {
            $name = strtolower((string) ($header['name'] ?? ''));

            if ($name !== '' && ! isset($headers[$name])) {
                $headers[$name] = (string) ($header['value'] ?? '');
            }
        }

        $from = $this->parseAddress($headers['from'] ?? '');
        $to = $this->parseAddress($headers['to'] ?? '');

        if ($from['email'] === null) {
            return null;
        }

        $received = isset($json['internalDate']) && is_numeric($json['internalDate'])
            ? \Carbon\Carbon::createFromTimestampMs((int) $json['internalDate'])
            : null;

        $maxBody = max(100, (int) config('business_email.inbound.max_body_length', 20000));

        return new BusinessEmailInboundMessage(
            providerMessageId: substr($id, 0, 191),
            providerThreadId: isset($json['threadId']) ? substr((string) $json['threadId'], 0, 191) : null,
            internetMessageId: $this->headerValue($headers['message-id'] ?? null, 255),
            inReplyTo: $this->headerValue($headers['in-reply-to'] ?? null, 255),
            references: $this->headerValue($headers['references'] ?? null, 2000),
            fromEmail: $from['email'],
            fromName: $from['name'] !== null ? mb_substr($from['name'], 0, 191) : null,
            toEmail: $to['email'] ?? '',
            subject: mb_substr($this->clean($this->decodeWords($headers['subject'] ?? '')), 0, 255),
            bodyText: mb_substr($this->extractBody((array) ($json['payload'] ?? [])), 0, $maxBody),
            receivedAt: $received,
            labels: array_values(array_map('strval', (array) ($json['labelIds'] ?? []))),
        );
    }

    private function isNotFound(BusinessEmailProviderException $exception): bool
    {
        return in_array(strtolower((string) $exception->providerCode), ['notfound', 'not_found', '404'], true);
    }

    private function headerValue(?string $value, int $max): ?string
    {
        $value = $value === null ? '' : $this->clean($value);

        return $value === '' ? null : substr($value, 0, $max);
    }

    /** @return array{email: string|null, name: string|null} first address of an address header */
    private function parseAddress(string $header): array
    {
        $header = $this->decodeWords($header);

        if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>/', $header, $m) === 1) {
            $email = $m[2];
            $name = trim($m[1]);
        } else {
            $email = trim(explode(',', $header)[0]);
            $name = '';
        }

        return [
            'email' => BusinessEmailContactResolver::normalize($email),
            'name' => $name !== '' ? $this->clean($name) : null,
        ];
    }

    private function decodeWords(string $value): string
    {
        return str_contains($value, '=?') ? (string) iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : $value;
    }

    /** @param array<string, mixed> $payload */
    private function extractBody(array $payload): string
    {
        $plain = $this->findPart($payload, 'text/plain');

        if ($plain !== null) {
            return trim($plain);
        }

        $html = $this->findPart($payload, 'text/html');

        return $html === null ? '' : trim(html_entity_decode(strip_tags((string) preg_replace('#<(script|style)\b.*?</\1>#is', '', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** @param array<string, mixed> $part */
    private function findPart(array $part, string $mime): ?string
    {
        if (($part['mimeType'] ?? null) === $mime && isset($part['body']['data'])) {
            $decoded = base64_decode(strtr((string) $part['body']['data'], '-_', '+/'), false);

            return $decoded === false ? null : str_replace("\0", '', $decoded);
        }

        foreach ((array) ($part['parts'] ?? []) as $child) {
            $found = is_array($child) ? $this->findPart($child, $mime) : null;

            if ($found !== null) {
                return $found;
            }
        }

        return null;
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
            ...$this->threadHeaders($email),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];

        $body = chunk_split(base64_encode($email->bodyText), 76, "\r\n");

        return [implode("\r\n", $headers) . "\r\n\r\n" . $body, $messageId];
    }

    /** @return list<string> */
    private function threadHeaders(BusinessEmailOutbound $email): array
    {
        $headers = [];

        if ($email->inReplyTo !== null && $email->inReplyTo !== '') {
            $headers[] = 'In-Reply-To: ' . $this->clean($email->inReplyTo);
            $references = trim($this->clean((string) $email->references));
            $headers[] = 'References: ' . ($references !== '' ? $references : $this->clean($email->inReplyTo));
        }

        return $headers;
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
