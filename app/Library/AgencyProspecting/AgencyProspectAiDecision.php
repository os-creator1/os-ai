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

        $nextStage = $data['next_stage'] ?? null;

        if ($nextStage !== null && (! is_int($nextStage) || ! in_array($nextStage, self::ALLOWED_NEXT_STAGES, true))) {
            return null;
        }

        $sendBookingLink = $data['send_booking_link'] ?? false;

        if (! is_bool($sendBookingLink)) {
            return null;
        }

        $proposedSlot = $data['proposed_slot'] ?? null;

        if ($proposedSlot !== null && (! is_string($proposedSlot) || trim($proposedSlot) === '')) {
            return null;
        }

        return new self($intent, trim($reply), $nextStage, $sendBookingLink, $proposedSlot);
    }

    public function isHardNegative(): bool
    {
        return $this->intent === 'hard_negative';
    }
}
