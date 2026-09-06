<?php

namespace App\Library\AgencyProspecting;

use JsonException;

/**
 * Runtime pass — the sole bounded, validated shape a free-form AI response
 * is ever converted into before it may touch any persisted state. Free-form
 * AI output never mutates state directly (task requirement) — every field
 * here is independently validated; any deviation returns null, which the
 * responder treats as "send nothing, advance nothing" (never a guess, never
 * a partial application).
 *
 * Correction 1 — `send_booking_link` and `next_stage === 4` are a strict
 * biconditional: stage 4 means "a booking link was actually sent", so the
 * AI can never request stage 4 without also requesting the link (and vice
 * versa) — any other combination is an invalid decision, not a partially
 * honored one. Whether a `booking_url` is actually configured is a
 * Workspace-settings concern the raw JSON has no visibility into, so that
 * half of the check is enforced by the caller (AgencyProspectingRespondJob),
 * not here. The AI-authored `reply` also has every URL stripped
 * unconditionally at construction time — the responder appends the one
 * server-configured booking URL itself when authorized to; the AI can
 * never smuggle a second (or, when unauthorized, a first) URL into the
 * outbound body.
 */
final class AgencyProspectAiDecision
{
    public const INTENTS = ['positive', 'question', 'qualification', 'booking', 'scheduling', 'soft_negative', 'hard_negative', 'other'];

    /**
     * The AI may never set 6 (Booked — manual/calendar-authoritative only)
     * and never 1 (an initial-only stage). 99 is the only terminal value it
     * may request; whether that specific transition is allowed from the
     * member's current stage is a separate check (AgencyProspectStageTransitionGuard).
     */
    public const ALLOWED_NEXT_STAGES = [2, 3, 4, 5, 99];

    private function __construct(
        public readonly string $intent,
        public readonly string $reply,
        public readonly ?int $nextStage,
        public readonly bool $sendBookingLink,
        public readonly ?string $proposedSlot,
    ) {
    }

    public static function fromRawJson(?string $json): ?self
    {
        if ($json === null || trim($json) === '') {
            return null;
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($data)) {
            return null;
        }

        return self::fromArray($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $intent = $data['intent'] ?? null;

        if (! is_string($intent) || ! in_array($intent, self::INTENTS, true)) {
            return null;
        }

        $reply = $data['reply'] ?? null;

        if (! is_string($reply) || trim($reply) === '') {
            return null;
        }

        $reply = trim(AgencyProspectUrlPolicy::sanitizeAiText(trim($reply)));

        if ($reply === '') {
            return null;
        }

        $nextStage = $data['next_stage'] ?? null;

        if ($nextStage !== null && (! is_int($nextStage) || ! in_array($nextStage, self::ALLOWED_NEXT_STAGES, true))) {
            return null;
        }

        $sendBookingLink = $data['send_booking_link'] ?? false;

        if (! is_bool($sendBookingLink)) {
            return null;
        }

        // Stage 4 ("booking link sent") and send_booking_link are the same
        // fact from two angles — any AI decision claiming one without the
        // other is internally inconsistent and must be rejected outright,
        // never partially honored.
        if ($sendBookingLink !== ($nextStage === 4)) {
            return null;
        }

        $proposedSlot = $data['proposed_slot'] ?? null;

        if ($proposedSlot !== null && (! is_string($proposedSlot) || trim($proposedSlot) === '')) {
            return null;
        }

        return new self($intent, $reply, $nextStage, $sendBookingLink, $proposedSlot);
    }

    public function isHardNegative(): bool
    {
        return $this->intent === 'hard_negative';
    }
}
