<?php

namespace App\Library\BusinessEmail\Imap;

/** A byte stream to a mail server; the seam that lets tests script a server. */
interface ImapTransport
{
    /**
     * Connects to the already-vetted address. `$peerName` is the host name the
     * server certificate MUST match; verification can never be switched off.
     *
     * @throws \App\Exceptions\BusinessEmail\BusinessEmailImapException
     */
    public function open(string $ip, int $port, string $peerName, bool $implicitTls): void;

    /** One line without its CRLF, or null at end of stream / timeout. */
    public function readLine(): ?string;

    /** Exactly $bytes bytes, or fewer at end of stream. */
    public function read(int $bytes): string;

    public function write(string $data): void;

    /** Upgrades the open connection to TLS (STARTTLS) with certificate verification. */
    public function startTls(): bool;

    public function close(): void;
}
