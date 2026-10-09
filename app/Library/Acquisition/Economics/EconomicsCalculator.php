<?php

namespace App\Library\Acquisition\Economics;

/**
 * Acquisition Purpose V1 — a CODE-OWNED economics formula.
 *
 * A niche Blueprint chooses WHICH calculator a purpose uses and the wording
 * of its questions; it can never supply a formula. The calculator declares the
 * closed set of input keys it understands, so a Blueprint cannot invent an
 * input that nothing reads.
 *
 * Pure: answers in, a profile out. No database, no clock, no provider, no AI.
 */
interface EconomicsCalculator
{
    public function key(): string;

    /**
     * The closed input set, in display order.
     *
     * type: money (Business currency, major units), count (whole number),
     * percent (0-100), text (free text, never used in arithmetic), choice
     * (one of `options`).
     *
     * @return array<string, array{type: string, label: string, help: string, options?: list<string>}>
     */
    public function inputs(): array;

    /**
     * @param  array<string, mixed>  $answers  question key => answered value
     * @param  list<string>  $unknown  question keys answered "I don't know yet"
     */
    public function profile(array $answers, array $unknown): EconomicsProfile;
}
