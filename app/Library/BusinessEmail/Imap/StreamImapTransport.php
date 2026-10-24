<?php

namespace App\Library\BusinessEmail\Imap;

use App\Exceptions\BusinessEmail\BusinessEmailImapException as Imap;

/** Real sockets. Certificate AND host-name verification are always on. */
final class StreamImapTransport implements ImapTransport
{
    /** @var resource|null */
    private $stream = null;

    private string $peerName = '';

    public function open(string $ip, int $port, string $peerName, bool $implicitTls): void
    {
        $this->peerName = $peerName;

        $context = stream_context_create(['ssl' => $this->sslOptions()]);
        $address = (str_contains($ip, ':') ? '[' . $ip . ']' : $ip) . ':' . $port;

        $stream = @stream_socket_client(
            ($implicitTls ? 'ssl://' : 'tcp://') . $address,
            $errno,
            $errstr,
            (float) config('business_email.imap.connect_timeout_seconds', 8),
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($stream === false) {
            throw new Imap(str_contains(strtolower((string) $errstr), 'ssl') || str_contains(strtolower((string) $errstr), 'certificate') ? Imap::TLS_FAILED : Imap::CONNECT_FAILED);
        }

        stream_set_timeout($stream, (int) config('business_email.imap.io_timeout_seconds', 20));
        $this->stream = $stream;
    }

    public function readLine(): ?string
    {
        if ($this->stream === null) {
            return null;
        }

        $line = fgets($this->stream, 65536);

        return $line === false ? null : rtrim($line, "\r\n");
    }

    public function read(int $bytes): string
    {
        $data = '';

        while ($this->stream !== null && strlen($data) < $bytes) {
            $chunk = fread($this->stream, $bytes - strlen($data));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $data .= $chunk;
        }

        return $data;
    }

    public function write(string $data): void
    {
        if ($this->stream !== null) {
            @fwrite($this->stream, $data);
        }
    }

    public function startTls(): bool
    {
        if ($this->stream === null) {
            return false;
        }

        foreach ($this->sslOptions() as $key => $value) {
            stream_context_set_option($this->stream, 'ssl', $key, $value);
        }

        return @stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) === true;
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            @fclose($this->stream);
            $this->stream = null;
        }
    }

    /** @return array<string, mixed> */
    private function sslOptions(): array
    {
        return [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $this->peerName,
            'SNI_enabled' => true,
            'SNI_server_name' => $this->peerName,
        ];
    }
}
