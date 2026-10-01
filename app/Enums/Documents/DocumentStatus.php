<?php

namespace App\Enums\Documents;

/**
 * Implementation Contract 17 §5.2 — Blueprint §18's six-state lifecycle
 * (draft -> sent -> signed/paid -> expired/void). Payment progress is a
 * separate axis derived from schedule items (§5.9); there is deliberately no
 * partially_paid state. Written only by the later canonical managers.
 *
 * THE ONE TRANSITION MAP. `allowedTransitions()` is the single authoritative
 * statement of which status may follow which (§7.1, §8.3, §8.6). The writers
 * — DocumentManager (send / sign / void / expire) and the payment finalizer
 * (-> paid) — each lock the document row and then check that the move they
 * are about to make is in this map, so an impossible transition is refused in
 * one place rather than by scattered per-method `in_array` lists that could
 * drift apart.
 *
 * `sent -> sent` is deliberate: it is the re-issue of a REVISED version of an
 * already-sent document (§7.1 Send), the one status-preserving move that still
 * mints a new link. Terminal states (paid, expired, void) never move (§8.3).
 * There is no `viewed` state: the public GET is side-effect-free (§6.3, §10).
 */
enum DocumentStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Signed = 'signed';
    case Paid = 'paid';
    case Expired = 'expired';
    case Void = 'void';

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Sent, self::Void],
            // sent -> paid is an invoice (no signature) settling; sent -> expired is
            // an unsigned, unpaid offer lapsing (§8.6).
            self::Sent => [self::Sent, self::Signed, self::Paid, self::Expired, self::Void],
            // A signed agreement is never expired (§8.6); it settles or is voided.
            self::Signed => [self::Paid, self::Void],
            self::Paid, self::Expired, self::Void => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}
