<?php

namespace App\Library\BusinessEmail;

use App\DTO\BusinessEmail\BusinessEmailInboundBatch;
use App\DTO\BusinessEmail\BusinessEmailInboundMessage;
use App\DTO\BusinessEmail\BusinessEmailOutbound;
use App\DTO\BusinessEmail\BusinessEmailProviderCapabilities;
use App\DTO\BusinessEmail\BusinessEmailProviderSendResult;
use App\DTO\BusinessEmail\BusinessEmailTokenGrant;
use App\Enums\BusinessEmail\BusinessEmailFailureCategory as Category;
use App\Enums\BusinessEmail\BusinessEmailProviderType;
use App\Exceptions\BusinessEmail\BusinessEmailImapException as ImapError;
use App\Exceptions\BusinessEmail\BusinessEmailProviderException;
use App\Library\BusinessEmail\Contracts\BusinessEmailProvider;
use App\Library\BusinessEmail\Contracts\InboundBusinessEmailProvider;
use App\Library\BusinessEmail\Imap\ImapClient;
use App\Library\BusinessEmail\Imap\ImapHostGuard;
use App\Library\BusinessEmail\Imap\ImapSettings;
use App\Library\BusinessEmail\Imap\ImapTransport;
use App\Library\BusinessEmail\Imap\MimeMessageParser;
use App\Library\BusinessEmail\Imap\SmtpMailer;
use App\Library\BusinessEmail\Imap\StreamImapTransport;
use App\Library\BusinessEmail\Imap\SymfonySmtpMailer;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * "Other email": a standard IMAP (receive) + SMTP (send) mailbox. Not OAuth.
 *
 * The credential is the account's single encrypted credential column holding
 * the whole {@see ImapSettings} blob. exchangeRefreshToken() simply validates
 * the blob and hands it back as the request-lifetime "access token", so the
 * existing manager/sender/syncer paths (token invariant, optimistic locking,
 * revocation on dead credentials) are reused unchanged.
 *
 * THREADING has no provider thread id, so one is derived from RFC headers: the
 * hash of the thread ROOT Message-ID (first References entry, else In-Reply-To,
 * else the message's own id). Inbound replies to a message we sent share the
 * root of that message; our replies reuse the stored thread id.
 *
 * INBOUND: the cursor is `uidvalidity:lastUid`. UIDVALIDITY changes (or no
 * cursor) restart from a bounded recent window; stored messages dedupe by
 * Message-ID. Fetches are partial (bounded bytes) and attachments are not
 * imported.
 */
final class ImapBusinessEmailProvider implements BusinessEmailProvider, InboundBusinessEmailProvider
{
    private const MAX_FETCH_BYTES = 262144;

    public function __construct(private readonly ImapHostGuard $guard)
    {
    }

    public function provider(): BusinessEmailProviderType
    {
        return BusinessEmailProviderType::Imap;
    }

    public function capabilities(): BusinessEmailProviderCapabilities
    {
        return new BusinessEmailProviderCapabilities(inboundSync: true, revocableGrant: false);
    }

    public function authorizationUrl(string $state, bool $forceConsent): string
    {
        throw new \LogicException('Other email does not use OAuth.');
    }

    public function exchangeAuthorizationCode(string $code): BusinessEmailTokenGrant
    {
        throw new \LogicException('Other email does not use OAuth.');
    }

    public function exchangeRefreshToken(string $refreshToken): BusinessEmailTokenGrant
    {
        try {
            ImapSettings::fromBlob($refreshToken);
        } catch (ImapError) {
            throw new BusinessEmailProviderException(Category::AuthenticationExpired, 'unreadable_credentials', revocation: true);
        }

        return new BusinessEmailTokenGrant(accessToken: $refreshToken, refreshToken: null, grantedScopes: null);
    }

    public function revokeGrant(string $refreshToken): void
    {
        // Nothing to revoke at the provider; local destruction of the credential is the revocation.
    }

    public function inboundScope(): string
    {
        return '';
    }

    /**
     * Verifies BOTH directions with the given settings: IMAP login + INBOX, and
     * SMTP connect + TLS + authentication (nothing is sent).
     *
     * @throws ImapError
     */
    public function testConnection(ImapSettings $settings): void
    {
        $ip = $this->guard->resolveSafe($settings->imapHost);
        $client = new ImapClient($this->transport());

        try {
            $client->open($ip, $settings->imapPort, $settings->imapHost, $settings->imapSecurity);
            $client->login($settings->username, $settings->password);
            $client->selectInbox();
        } finally {
            $client->logout();
        }

        $smtpIp = $this->guard->resolveSafe($settings->smtpHost);

        try {
            $this->mailer()->test($settings, $smtpIp);
        } catch (ImapError $error) {
            throw $error;
        } catch (\Throwable $exception) {
            $message = $exception->getMessage();

            throw new ImapError(match (true) {
                preg_match('/code "?535|auth(entication)? (failed|required)|invalid credentials/i', $message) === 1 => ImapError::SMTP_AUTH_FAILED,
                preg_match('/starttls|tls|ssl|certificate/i', $message) === 1 => ImapError::TLS_FAILED,
                preg_match('/could not be established|refused|timed out/i', $message) === 1 => ImapError::CONNECT_FAILED,
                default => ImapError::PROTOCOL,
            });
        }
    }

    public function send(string $accessToken, BusinessEmailOutbound $email): BusinessEmailProviderSendResult
    {
        $settings = $this->settings($accessToken);

        try {
            $ip = $this->guard->resolveSafe($settings->smtpHost);
        } catch (ImapError $error) {
            throw $this->providerError($error);
        }

        $domain = (string) preg_replace('/[^A-Za-z0-9.-]/', '', Str::afterLast($email->fromEmail, '@'));
        $messageId = '<' . Str::uuid() . '@' . ($domain !== '' ? $domain : 'localhost') . '>';

        $message = (new Email())
            ->from(new Address($email->fromEmail, (string) $email->fromName))
            ->to($email->toEmail)
            ->subject(InboundText::headerLine($email->subject, 998))
            ->text($email->bodyText);

        $headers = $message->getHeaders();
        $headers->addIdHeader('Message-ID', trim($messageId, '<>'));

        if ($email->inReplyTo !== null && $email->inReplyTo !== '') {
            $headers->addTextHeader('In-Reply-To', InboundText::headerLine($email->inReplyTo, 998));
            $headers->addTextHeader('References', InboundText::headerLine($email->references ?: $email->inReplyTo, 2000));
        }

        try {
            $this->mailer()->send($settings, $ip, $message);
        } catch (\Throwable $exception) {
            throw $this->smtpError($exception, requestSent: true);
        }

        $root = $email->threadId ?: 'th:' . sha1(self::firstId((string) ($email->references ?: $email->inReplyTo)) ?? $messageId);

        return new BusinessEmailProviderSendResult(providerMessageId: $messageId, providerThreadId: $root, internetMessageId: $messageId);
    }

    public function fetchInbound(string $accessToken, ?string $cursor, int $limit): BusinessEmailInboundBatch
    {
        $settings = $this->settings($accessToken);
        $limit = max(1, $limit);

        try {
            $ip = $this->guard->resolveSafe($settings->imapHost);
            $client = new ImapClient($this->transport());
            $client->open($ip, $settings->imapPort, $settings->imapHost, $settings->imapSecurity);
        } catch (ImapError $error) {
            throw $this->providerError($error);
        }

        try {
            $client->login($settings->username, $settings->password);
            $box = $client->selectInbox();
            [$validity, $last] = $this->parseCursor($cursor);

            if ($validity !== $box['uidvalidity'] || $last === null) {
                [$uids, $newCursor, $hasMore] = $this->initialWindow($client, $box, $limit);
            } else {
                $uids = array_values(array_filter($client->uidSearch(sprintf('UID %d:*', $last + 1)), static fn (int $uid): bool => $uid > $last));
                $hasMore = count($uids) > $limit;
                $uids = array_slice($uids, 0, $limit);
                $newCursor = $uids === [] ? (string) $cursor : $validity . ':' . end($uids);
            }

            $messages = [];
            $unparsed = [];
            $parser = new MimeMessageParser();

            foreach ($uids as $uid) {
                try {
                    $raw = $client->fetchMessage($uid, self::MAX_FETCH_BYTES);
                    $message = $raw === null ? null : $this->toMessage($parser->parse($raw), $box['uidvalidity'], $uid);
                } catch (ImapError $error) {
                    if ($error->reason !== ImapError::PROTOCOL) {
                        throw $error;
                    }

                    $message = null;
                    $unparsed[] = 'imap:' . $box['uidvalidity'] . ':' . $uid;

                    continue;
                } catch (\Throwable) {
                    $unparsed[] = 'imap:' . $box['uidvalidity'] . ':' . $uid;

                    continue;
                }

                if ($message !== null) {
                    $messages[] = $message;
                }
            }

            return new BusinessEmailInboundBatch($messages, $newCursor, $hasMore, $unparsed);
        } catch (ImapError $error) {
            throw $this->providerError($error);
        } finally {
            $client->logout();
        }
    }

    /** @return array{0: list<int>, 1: string, 2: bool} */
    private function initialWindow(ImapClient $client, array $box, int $limit): array
    {
        $days = max(1, (int) config('business_email.inbound.initial_days', 7));
        $max = min($limit, max(1, (int) config('business_email.inbound.initial_max_messages', 50)));
        $since = gmdate('j-M-Y', time() - $days * 86400);

        $uids = array_slice($client->uidSearch('SINCE ' . $since), -$max);

        // Taken from SELECT before reading: anything newer is picked up next run.
        return [$uids, $box['uidvalidity'] . ':' . max(0, $box['uidnext'] - 1), false];
    }

    /** @return array{0: int|null, 1: int|null} */
    private function parseCursor(?string $cursor): array
    {
        if ($cursor !== null && preg_match('/^(\d+):(\d+)$/', $cursor, $m) === 1) {
            return [(int) $m[1], (int) $m[2]];
        }

        return [null, null];
    }

    /**
     * @param array{headers: array<string, string>, text: string, has_attachments: bool} $parsed
     */
    private function toMessage(array $parsed, int $uidValidity, int $uid): ?BusinessEmailInboundMessage
    {
        $headers = $parsed['headers'];
        $from = $this->address($headers['from'] ?? '');

        if ($from['email'] === null) {
            return null;
        }

        $mid = InboundText::headerLine($headers['message-id'] ?? null, 255);
        $key = $mid !== '' ? $mid : 'imap:' . $uidValidity . ':' . $uid;
        $providerId = strlen($key) <= 191 ? $key : 'mid:' . sha1($key);

        $inReplyTo = self::firstId($headers['in-reply-to'] ?? '');
        $references = InboundText::headerLine($headers['references'] ?? null, 2000);
        $root = self::firstId($references) ?? $inReplyTo ?? ($mid !== '' ? $mid : $providerId);

        $received = isset($headers['date']) ? strtotime($headers['date']) : false;

        return new BusinessEmailInboundMessage(
            providerMessageId: $providerId,
            providerThreadId: 'th:' . sha1($root),
            internetMessageId: $mid !== '' ? $mid : null,
            inReplyTo: $inReplyTo,
            references: $references !== '' ? $references : null,
            fromEmail: $from['email'],
            fromName: $from['name'],
            toEmail: $this->address($headers['to'] ?? '')['email'] ?? '',
            subject: mb_substr(InboundText::headerLine($headers['subject'] ?? null, 1020), 0, 255),
            bodyText: InboundText::body($parsed['text']),
            receivedAt: $received !== false ? (new \DateTimeImmutable())->setTimestamp($received) : null,
            labels: ['INBOX'],
        );
    }

    /** @return array{email: string|null, name: string|null} */
    private function address(string $header): array
    {
        if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>/', $header, $m) === 1) {
            $email = $m[2];
            $name = trim($m[1]);
        } else {
            $email = trim(explode(',', $header)[0]);
            $name = '';
        }

        $name = InboundText::headerLine($name, 191);

        return ['email' => BusinessEmailContactResolver::normalize($email), 'name' => $name !== '' ? $name : null];
    }

    /** The first <message-id> token of a header value, or null. */
    private static function firstId(string $value): ?string
    {
        return preg_match('/<[^<>\s]{1,250}>/', $value, $m) === 1 ? $m[0] : null;
    }

    private function settings(string $blob): ImapSettings
    {
        try {
            return ImapSettings::fromBlob($blob);
        } catch (ImapError) {
            throw new BusinessEmailProviderException(Category::AuthenticationExpired, 'unreadable_credentials', revocation: true);
        }
    }

    private function providerError(ImapError $error): BusinessEmailProviderException
    {
        return match (true) {
            // The server rejected the stored credentials: the grant is dead (reconnect required).
            $error->isCredentialFailure() => new BusinessEmailProviderException(Category::AuthenticationExpired, $error->reason, revocation: true),
            $error->isTemporary() => new BusinessEmailProviderException(Category::TemporaryProviderFailure, $error->reason),
            default => new BusinessEmailProviderException(Category::PermanentProviderFailure, $error->reason),
        };
    }

    private function smtpError(\Throwable $exception, bool $requestSent = false): BusinessEmailProviderException
    {
        $message = $exception->getMessage();

        if (preg_match('/code "?535|auth(entication)? (failed|required)|invalid credentials/i', $message) === 1) {
            return new BusinessEmailProviderException(Category::AuthenticationExpired, ImapError::SMTP_AUTH_FAILED);
        }

        if (preg_match('/code "?55[0-4]/', $message) === 1) {
            return new BusinessEmailProviderException(Category::RecipientInvalid, 'smtp_recipient_rejected');
        }

        if (preg_match('/could not be established|connection refused|timed out|getaddrinfo/i', $message) === 1 && ! str_contains($message, 'DATA')) {
            return new BusinessEmailProviderException(Category::TemporaryProviderFailure, 'smtp_connect_failed');
        }

        if (preg_match('/starttls|tls|ssl|certificate/i', $message) === 1) {
            return new BusinessEmailProviderException(Category::PermanentProviderFailure, 'smtp_tls_failed');
        }

        // Anything else after the request was dispatched is ambiguous: never auto-resend.
        return new BusinessEmailProviderException(Category::TemporaryProviderFailure, 'smtp_error', ambiguous: $requestSent);
    }

    private function transport(): ImapTransport
    {
        return app()->bound(ImapTransport::class) ? app(ImapTransport::class) : new StreamImapTransport();
    }

    private function mailer(): SmtpMailer
    {
        return app()->bound(SmtpMailer::class) ? app(SmtpMailer::class) : new SymfonySmtpMailer();
    }
}
