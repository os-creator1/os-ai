<?php

namespace App\Library\Documents\Blocks;

use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\Contacts;

/**
 * Implementation Contract 17B §4 — the ONLY authority on merge fields.
 *
 * An allow-list of tokens, nothing else: no expressions, no filters, no
 * lookups by caller-chosen key. A token is a plain string key into a context
 * array of token => value strings; an unknown token has no catalog entry, is
 * rejected by BlockSchema at write time and resolves to the empty string
 * here, so it can never become a path into any other data.
 *
 * Three context builders, one per lifecycle moment:
 *   forDocument()        LIVE values for drafts / preview (recipient snapshot,
 *                        falling back to the Contact when no name is set yet).
 *   freeze()             the values written into `content.parties.merge` at
 *                        send, hashed with the version.
 *   fromFrozenParties()  what an ISSUED version renders from — never the live
 *                        Business or Contact, so a later edit cannot rewrite
 *                        what was sent or signed.
 *   sample()             fixed placeholder values for platform-template
 *                        previews (a platform template has no Business/Contact).
 */
final class DocumentMergeFields
{
    /** Longest value ever carried into a rendered document. */
    private const MAX_VALUE_LENGTH = 500;

    /** @var array<string, array{label: string, group: string, sample: string}> */
    private const CATALOG = [
        'contact.first_name' => ['label' => 'Contact first name', 'group' => 'Contact', 'sample' => 'Alex'],
        'contact.full_name' => ['label' => 'Contact full name', 'group' => 'Contact', 'sample' => 'Alex Morgan'],
        'contact.email' => ['label' => 'Contact email', 'group' => 'Contact', 'sample' => 'alex@example.com'],
        'business.name' => ['label' => 'Business name', 'group' => 'Business', 'sample' => 'Your Business'],
        'business.phone' => ['label' => 'Business phone', 'group' => 'Business', 'sample' => '+1 555 0100'],
        'business.email' => ['label' => 'Business email', 'group' => 'Business', 'sample' => 'hello@yourbusiness.example'],
        'business.website' => ['label' => 'Business website', 'group' => 'Business', 'sample' => 'https://yourbusiness.example'],
        'document.title' => ['label' => 'Document title', 'group' => 'Document', 'sample' => 'Proposal title'],
    ];

    private function __construct()
    {
    }

    public static function isAllowed(mixed $token): bool
    {
        return is_string($token) && isset(self::CATALOG[$token]);
    }

    /**
     * The catalog the editor offers.
     *
     * @return array<int, array{token: string, label: string, group: string}>
     */
    public static function catalog(): array
    {
        $out = [];

        foreach (self::CATALOG as $token => $meta) {
            $out[] = ['token' => $token, 'label' => $meta['label'], 'group' => $meta['group']];
        }

        return $out;
    }

    public static function label(string $token): string
    {
        return self::CATALOG[$token]['label'] ?? '';
    }

    /**
     * @return array<int, string>
     */
    public static function tokens(): array
    {
        return array_keys(self::CATALOG);
    }

    /**
     * One token against a context. Unknown token, missing or non-string value
     * all resolve to '' — never an error, never a leak of another key.
     *
     * @param  array<string, mixed>  $context
     */
    public static function resolve(string $token, array $context): string
    {
        if (! isset(self::CATALOG[$token])) {
            return '';
        }

        $value = $context[$token] ?? '';

        return is_string($value) ? self::clean($value) : '';
    }

    /**
     * LIVE context for a draft or a preview.
     *
     * @return array<string, string>
     */
    public static function forDocument(BusinessDocument $document): array
    {
        $business = Business::find($document->business_id);
        $context = self::values(
            $business,
            (string) $document->title,
            (string) $document->recipient_name_snapshot,
            (string) $document->recipient_email_snapshot,
        );

        if ($context['contact.full_name'] === '' && $document->contact_id !== null) {
            $contact = Contacts::find($document->contact_id);
            $name = $contact === null ? '' : trim((string) $contact->getFullName(''));

            if ($name !== '') {
                $context['contact.full_name'] = self::clean($name);
                $context['contact.first_name'] = self::firstName($name);
            }
        }

        return $context;
    }

    /**
     * The values frozen into `content.parties.merge` at send: the recipient
     * SNAPSHOT only (deterministic — no live Contact lookup), plus the
     * Business and title as they are at that instant.
     *
     * @return array<string, string>
     */
    public static function freeze(BusinessDocument $document, Business $business): array
    {
        return self::values(
            $business,
            (string) $document->title,
            (string) $document->recipient_name_snapshot,
            (string) $document->recipient_email_snapshot,
        );
    }

    /**
     * Context for an ISSUED version, from its frozen `content.parties` only.
     * Versions issued before the merge snapshot existed fall back to the
     * older party keys so the common tokens still resolve.
     *
     * @param  array<string, mixed>  $parties
     * @return array<string, string>
     */
    public static function fromFrozenParties(array $parties): array
    {
        $context = array_fill_keys(array_keys(self::CATALOG), '');

        $frozen = is_array($parties['merge'] ?? null) ? $parties['merge'] : [];

        // Legacy party keys first, so an explicit frozen value wins.
        $legacy = [
            'business.name' => $parties['business_name'] ?? null,
            'document.title' => $parties['document_title'] ?? null,
            'contact.full_name' => $parties['recipient_name'] ?? null,
        ];

        foreach ($legacy as $token => $value) {
            if (is_string($value)) {
                $context[$token] = self::clean($value);
            }
        }

        if ($context['contact.full_name'] !== '') {
            $context['contact.first_name'] = self::firstName($context['contact.full_name']);
        }

        foreach ($frozen as $token => $value) {
            if (is_string($token) && isset(self::CATALOG[$token]) && is_string($value)) {
                $context[$token] = self::clean($value);
            }
        }

        return $context;
    }

    /**
     * Fixed sample values for previewing a platform template.
     *
     * @return array<string, string>
     */
    public static function sample(): array
    {
        $out = [];

        foreach (self::CATALOG as $token => $meta) {
            $out[$token] = $meta['sample'];
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private static function values(?Business $business, string $title, string $recipientName, string $recipientEmail): array
    {
        $recipientName = trim($recipientName);

        return [
            'contact.first_name' => $recipientName === '' ? '' : self::firstName($recipientName),
            'contact.full_name' => self::clean($recipientName),
            'contact.email' => self::clean($recipientEmail),
            'business.name' => self::clean((string) $business?->name),
            'business.phone' => self::clean((string) $business?->phone),
            'business.email' => self::clean((string) $business?->email),
            'business.website' => self::clean((string) $business?->website_url),
            'document.title' => self::clean($title),
        ];
    }

    private static function firstName(string $fullName): string
    {
        $parts = preg_split('/\s+/u', trim($fullName), 2) ?: [];

        return self::clean($parts[0] ?? '');
    }

    private static function clean(string $value): string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');

        return mb_substr($value, 0, self::MAX_VALUE_LENGTH);
    }
}
