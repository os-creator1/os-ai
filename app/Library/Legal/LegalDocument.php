<?php

namespace App\Library\Legal;

use Carbon\CarbonImmutable;

/**
 * One versioned legal document. Immutable: the identity of what a customer
 * accepted is (slug, version, hash), where the hash is the sha256 of the
 * canonical source text (LF line endings, trimmed) and so does not move when a
 * company fact such as a contact email is filled in.
 */
final class LegalDocument
{
    /** @var list<string> */
    public readonly array $unresolved;

    /**
     * @param  list<string>  $summary
     * @param  list<string>  $unresolved  "[VERIFY: ...]" markers still present after company facts are applied
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly string $short,
        public readonly string $category,
        public readonly bool $primary,
        public readonly string $version,
        public readonly string $status,
        public readonly ?string $effectiveDate,
        public readonly ?string $updatedAt,
        public readonly ?string $approvedBy,
        public readonly ?string $approvedAt,
        public readonly string $description,
        public readonly array $summary,
        public readonly ?string $releaseNote,
        public readonly string $source,
        public readonly string $hash,
        array $unresolved,
        public readonly bool $sealed,
    ) {
        $this->unresolved = $unresolved;
    }

    public static function canonical(string $source): string
    {
        return rtrim(str_replace(["\r\n", "\r"], "\n", $source))."\n";
    }

    public static function hashOf(string $source): string
    {
        return hash('sha256', self::canonical($source));
    }

    /** Approved, complete and sealed: the only state in which it is public (and acceptable). */
    public function isPublished(): bool
    {
        return $this->status === 'approved'
            && $this->approvedBy !== null && $this->approvedBy !== ''
            && $this->approvedAt !== null
            && $this->effectiveDate !== null
            && $this->unresolved === []
            && $this->sealed;
    }

    /** What still stands between this document and release, as plain sentences. */
    public function blockers(): array
    {
        $out = [];
        if ($this->status !== 'approved') {
            $out[] = 'status is "'.$this->status.'", not "approved"';
        }
        if (! $this->approvedBy || ! $this->approvedAt) {
            $out[] = 'approved_by / approved_at not recorded';
        }
        if ($this->effectiveDate === null) {
            $out[] = 'no effective_date';
        }
        if ($this->unresolved !== []) {
            $out[] = count($this->unresolved).' unresolved [VERIFY] item(s)';
        }
        if (! $this->sealed) {
            $out[] = 'hash not sealed in resources/legal/hashes.json for version '.$this->version;
        }

        return $out;
    }

    public function effectiveLabel(): string
    {
        return $this->effectiveDate ? CarbonImmutable::parse($this->effectiveDate)->format('j F Y') : 'On approval';
    }

    public function updatedLabel(): string
    {
        return $this->updatedAt ? CarbonImmutable::parse($this->updatedAt)->format('j F Y') : '—';
    }
}
