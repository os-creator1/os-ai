<?php

namespace App\Library\BusinessEmail\Imap;

use App\Exceptions\BusinessEmail\BusinessEmailImapException as Imap;

/**
 * The few IMAP commands inbound sync needs: LOGIN, SELECT INBOX, UID SEARCH,
 * partial UID FETCH and LOGOUT. Bounded everywhere: line lengths, literal
 * sizes and the number of response lines.
 *
 * Arguments are quoted with control characters rejected, so a username or
 * password can never inject a second command. Nothing here logs a command or
 * a response.
 */
final class ImapClient
{
    private const MAX_LITERAL = 2_097_152;

    private const MAX_LINES = 20000;

    private int $tag = 0;

    public function __construct(private readonly ImapTransport $transport)
    {
    }

    /** @throws Imap */
    public function open(string $ip, int $port, string $peerName, string $security): void
    {
        $this->transport->open($ip, $port, $peerName, $security === 'ssl');

        $greeting = $this->transport->readLine();

        if ($greeting === null || preg_match('/^\* (OK|PREAUTH)\b/i', $greeting) !== 1) {
            throw new Imap(Imap::PROTOCOL);
        }

        if ($security === 'starttls') {
            $this->command('STARTTLS');

            if (! $this->transport->startTls()) {
                throw new Imap(Imap::TLS_FAILED);
            }
        }
    }

    /** @throws Imap */
    public function login(string $username, string $password): void
    {
        try {
            $this->command('LOGIN ' . $this->quote($username) . ' ' . $this->quote($password));
        } catch (Imap $exception) {
            throw $exception->reason === Imap::PROTOCOL ? new Imap(Imap::AUTH_FAILED) : $exception;
        }
    }

    /** @return array{uidvalidity: int, uidnext: int} @throws Imap */
    public function selectInbox(): array
    {
        $response = $this->command('SELECT "INBOX"');
        $uidValidity = 0;
        $uidNext = 0;

        foreach ($response['lines'] as $line) {
            if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $line, $m) === 1) {
                $uidValidity = (int) $m[1];
            }

            if (preg_match('/\[UIDNEXT (\d+)\]/i', $line, $m) === 1) {
                $uidNext = (int) $m[1];
            }
        }

        if ($uidValidity === 0) {
            throw new Imap(Imap::PROTOCOL);
        }

        return ['uidvalidity' => $uidValidity, 'uidnext' => $uidNext];
    }

    /**
     * @param string $criteria a fixed, caller-built criteria string (digits and keywords only)
     * @return list<int> ascending
     * @throws Imap
     */
    public function uidSearch(string $criteria): array
    {
        if (preg_match('/^[A-Za-z0-9:*, -]{1,64}$/', $criteria) !== 1) {
            throw new Imap(Imap::PROTOCOL);
        }

        $response = $this->command('UID SEARCH ' . $criteria);
        $uids = [];

        foreach ($response['lines'] as $line) {
            if (preg_match('/^\* SEARCH\b(.*)$/i', $line, $m) === 1) {
                foreach (preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $uid) {
                    if (ctype_digit($uid)) {
                        $uids[(int) $uid] = true;
                    }
                }
            }
        }

        $uids = array_keys($uids);
        sort($uids);

        return $uids;
    }

    /** The raw RFC 822 message, truncated server-side to $maxBytes; null if it vanished. @throws Imap */
    public function fetchMessage(int $uid, int $maxBytes): ?string
    {
        $response = $this->command(sprintf('UID FETCH %d (BODY.PEEK[]<0.%d>)', $uid, $maxBytes));

        return $response['literals'][0] ?? null;
    }

    public function logout(): void
    {
        try {
            $this->command('LOGOUT');
        } catch (\Throwable) {
            // best effort
        }

        $this->transport->close();
    }

    /** @return array{lines: list<string>, literals: list<string>} @throws Imap */
    private function command(string $command): array
    {
        $tag = 'A' . str_pad((string) ++$this->tag, 4, '0', STR_PAD_LEFT);
        $this->transport->write($tag . ' ' . $command . "\r\n");

        $lines = [];
        $literals = [];

        for ($i = 0; $i < self::MAX_LINES; $i++) {
            $line = $this->transport->readLine();

            if ($line === null) {
                throw new Imap(Imap::TIMEOUT);
            }

            if (str_starts_with($line, $tag . ' ')) {
                if (preg_match('/^' . $tag . ' OK\b/i', $line) === 1) {
                    return ['lines' => $lines, 'literals' => $literals];
                }

                throw new Imap(preg_match('/AUTHENTICATIONFAILED|AUTHORIZATIONFAILED|LOGIN failed|invalid credentials/i', $line) === 1 ? Imap::AUTH_FAILED : Imap::PROTOCOL);
            }

            $lines[] = $line;

            if (preg_match('/\{(\d+)\}$/', $line, $m) === 1) {
                $size = (int) $m[1];

                if ($size > self::MAX_LITERAL) {
                    throw new Imap(Imap::PROTOCOL);
                }

                $literals[] = $this->transport->read($size);
            }
        }

        throw new Imap(Imap::PROTOCOL);
    }

    private function quote(string $value): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new Imap(Imap::INVALID_SETTINGS, 'The username or password contains characters that are not allowed.');
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
