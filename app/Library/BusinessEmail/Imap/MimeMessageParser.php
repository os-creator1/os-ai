<?php

namespace App\Library\BusinessEmail\Imap;

use App\Library\BusinessEmail\InboundText;

/**
 * A deliberately small, defensive RFC 5322 / MIME reader for UNTRUSTED mail:
 * unfolds headers, decodes RFC 2047 words, walks multipart bodies (bounded
 * depth), decodes base64 / quoted-printable, converts charsets to UTF-8 and
 * prefers text/plain over text/html (reduced to inert text). Attachments are
 * never imported. It never throws on malformed input.
 */
final class MimeMessageParser
{
    private const MAX_DEPTH = 5;

    /** @return array{headers: array<string, string>, text: string, has_attachments: bool} */
    public function parse(string $raw): array
    {
        [$headerBlock, $body] = $this->split($raw);
        $headers = $this->headers($headerBlock);

        $found = ['plain' => null, 'html' => null, 'attachments' => false];
        $this->walk($headers, $body, 0, $found);

        $text = $found['plain'] ?? ($found['html'] !== null ? InboundText::htmlToText($found['html']) : '');

        $decoded = [];

        foreach ($headers as $name => $value) {
            $decoded[$name] = InboundText::utf8(str_contains($value, '=?') ? (string) iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : $value);
        }

        return ['headers' => $decoded, 'text' => trim($text), 'has_attachments' => $found['attachments']];
    }

    /** @return array{0: string, 1: string} */
    private function split(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $pos = strpos($raw, "\n\n");

        return $pos === false ? [$raw, ''] : [substr($raw, 0, $pos), substr($raw, $pos + 2)];
    }

    /** @return array<string, string> lower-case name => first value */
    private function headers(string $block): array
    {
        $headers = [];
        $current = null;

        foreach (explode("\n", $block) as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
                $headers[$current] .= ' ' . trim($line);

                continue;
            }

            if (($pos = strpos($line, ':')) > 0) {
                $name = strtolower(trim(substr($line, 0, $pos)));
                $current = isset($headers[$name]) ? '__dup_' . $name : $name;
                $headers[$current] = trim(substr($line, $pos + 1));
            }
        }

        return array_filter($headers, static fn (string $key): bool => ! str_starts_with($key, '__dup_'), ARRAY_FILTER_USE_KEY);
    }

    /** @param array<string, string> $headers @param array{plain: ?string, html: ?string, attachments: bool} $found */
    private function walk(array $headers, string $body, int $depth, array &$found): void
    {
        $type = strtolower(trim(explode(';', $headers['content-type'] ?? 'text/plain')[0]));
        $disposition = strtolower($headers['content-disposition'] ?? '');

        if ($depth > self::MAX_DEPTH) {
            return;
        }

        if (str_starts_with($type, 'multipart/')) {
            if (preg_match('/boundary\s*=\s*"?([^";\s]+)"?/i', $headers['content-type'] ?? '', $m) !== 1) {
                return;
            }

            foreach (explode('--' . $m[1], $body) as $index => $part) {
                $part = ltrim($part, "\n");

                if ($index === 0 || str_starts_with($part, '--') || trim($part) === '') {
                    continue;
                }

                [$partHeaders, $partBody] = $this->split($part);
                $this->walk($this->headers($partHeaders), $partBody, $depth + 1, $found);
            }

            return;
        }

        if (str_starts_with($disposition, 'attachment') || ($type !== 'text/plain' && $type !== 'text/html')) {
            $found['attachments'] = true;

            return;
        }

        $charset = preg_match('/charset\s*=\s*"?([A-Za-z0-9_.:-]+)"?/i', $headers['content-type'] ?? '', $m) === 1 ? $m[1] : null;
        $decoded = InboundText::utf8($this->decodeTransfer($body, strtolower(trim($headers['content-transfer-encoding'] ?? '7bit'))), $charset);

        if ($type === 'text/plain' && $found['plain'] === null) {
            $found['plain'] = $decoded;
        } elseif ($type === 'text/html' && $found['html'] === null) {
            $found['html'] = $decoded;
        }
    }

    private function decodeTransfer(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }
}
