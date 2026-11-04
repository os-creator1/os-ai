<?php

namespace Tests\Feature\BusinessEmail\Support;

use App\Library\BusinessEmail\Imap\ImapTransport;
use Closure;

/**
 * A scripted IMAP server: `$server` receives each command (without its tag)
 * and returns [raw untagged response, tagged status text]. No sockets.
 */
final class FakeImapTransport implements ImapTransport
{
    /** @var list<string> every command the client sent (passwords redacted) */
    public array $commands = [];

    public ?string $openedIp = null;

    public ?string $peerName = null;

    public bool $implicitTls = false;

    public bool $closed = false;

    public bool $tlsUpgraded = false;

    public bool $tlsWorks = true;

    public string $greeting = '* OK IMAP ready';

    private string $buffer = '';

    private string $tag = '';

    /** @param Closure(string): array{0: string, 1: string} $server */
    public function __construct(private readonly Closure $server)
    {
    }

    public function open(string $ip, int $port, string $peerName, bool $implicitTls): void
    {
        $this->openedIp = $ip;
        $this->peerName = $peerName;
        $this->implicitTls = $implicitTls;
        $this->closed = false;
        $this->buffer = $this->greeting . "\r\n";
    }

    public function readLine(): ?string
    {
        if ($this->buffer === '') {
            return null;
        }

        $pos = strpos($this->buffer, "\r\n");

        if ($pos === false) {
            $line = $this->buffer;
            $this->buffer = '';

            return $line;
        }

        $line = substr($this->buffer, 0, $pos);
        $this->buffer = substr($this->buffer, $pos + 2);

        return $line;
    }

    public function read(int $bytes): string
    {
        $data = substr($this->buffer, 0, $bytes);
        $this->buffer = (string) substr($this->buffer, strlen($data));

        return $data;
    }

    public function write(string $data): void
    {
        $line = rtrim($data, "\r\n");
        [$this->tag, $command] = explode(' ', $line, 2) + [1 => ''];
        $this->commands[] = str_starts_with($command, 'LOGIN ') ? 'LOGIN <redacted>' : $command;

        [$untagged, $status] = ($this->server)($command);
        $this->buffer .= $untagged . $this->tag . ' ' . $status . "\r\n";
    }

    public function startTls(): bool
    {
        $this->tlsUpgraded = $this->tlsWorks;

        return $this->tlsWorks;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    /** A FETCH answer with a literal body. */
    public static function fetch(int $uid, string $raw): string
    {
        return "* 1 FETCH (UID {$uid} BODY[]<0> {" . strlen($raw) . "}\r\n" . $raw . ")\r\n";
    }
}
