<?php

namespace App\Library\Website\Domains;

/**
 * Website Generation + Hosting Slice B (custom domains, contract §40 —
 * "DNS verification approach" was named there as a blocking question;
 * this class is that decision). Ownership is proven with a TXT-record
 * token, deliberately separate from whatever record later points real
 * traffic at the platform (CNAME/A) — a domain whose DNS still resolves
 * to us from a PREVIOUS owner's setup must never be enough on its own to
 * let a new claimant take it over; they must be able to add a fresh TXT
 * record naming a token only they were ever shown.
 *
 * A real DNS lookup is genuine outbound network I/O, so this is a plain
 * (non-final) class specifically so tests can bind a Mockery double in
 * its place — mirroring WebsiteAiGenerationClient's own precedent — and
 * never perform a real lookup.
 */
class DnsVerifier
{
    /**
     * True only when $host has a TXT record whose value is EXACTLY
     * $expectedValue (never a substring/prefix match — that would let a
     * TXT record with extra, attacker-controlled content also pass).
     */
    public function hasTxtRecord(string $host, string $expectedValue): bool
    {
        $records = @dns_get_record($host, DNS_TXT) ?: [];

        foreach ($records as $record) {
            if (($record['txt'] ?? null) === $expectedValue) {
                return true;
            }
        }

        return false;
    }
}
