<?php

namespace App\Library\Conversations;

use Carbon\CarbonInterface;

/**
 * Who the open conversation is with, for the contact panel — only what is
 * stored.
 *
 * Name, email, company, group and details exist only when the conversation
 * resolves to exactly one Contact of this Business (Slice 2B §10). With none,
 * or several on the same number, the panel shows the number alone: the two
 * cases read the same, because naming one of two same-number contacts would be
 * a guess.
 */
final class ConversationContext
{
    public const CONSENT_SUBSCRIBED = 'subscribed';

    public const CONSENT_UNSUBSCRIBED = 'unsubscribed';

    public const CONSENT_BLOCKED = 'blocked';

    public const CONSENT_NO_CONTACT = 'no_contact';

    /**
     * @param  list<array{label: string, value: string}>  $details
     * @param  list<array{title: string, rows: list<array{label: string, value: string}>}>  $sections
     */
    public function __construct(
        public readonly string $phone,
        public readonly ?string $businessNumber,
        public readonly string $consent,
        public readonly ?string $contactUid = null,
        public readonly ?string $name = null,
        public readonly ?string $email = null,
        public readonly ?string $company = null,
        public readonly ?string $group = null,
        public readonly ?CarbonInterface $addedAt = null,
        public readonly array $details = [],
        public readonly array $sections = [],
    ) {
    }

    public function title(): string
    {
        return $this->name ?? $this->phone;
    }

    /** Up to two initials from the contact's name; empty when there is no name. */
    public function initials(): string
    {
        $words = preg_split('/\s+/u', trim((string) $this->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return mb_strtoupper(implode('', array_map(static fn (string $word): string => mb_substr($word, 0, 1), array_slice($words, 0, 2))));
    }

    public function hasContact(): bool
    {
        return $this->contactUid !== null;
    }

    public function consentLabel(): string
    {
        return match ($this->consent) {
            self::CONSENT_SUBSCRIBED => 'Subscribed to texts',
            self::CONSENT_UNSUBSCRIBED => 'Unsubscribed from texts',
            self::CONSENT_BLOCKED => 'On your block list',
            default => 'Not linked to a contact',
        };
    }

    /** The badge variant x-badge already defines for each state. */
    public function consentVariant(): string
    {
        return match ($this->consent) {
            self::CONSENT_SUBSCRIBED => 'success',
            self::CONSENT_UNSUBSCRIBED, self::CONSENT_BLOCKED => 'warning',
            default => 'neutral',
        };
    }
}
