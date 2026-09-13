<?php

namespace App\Library\Coo\Insight;

use App\Enums\Coo\CooInsightStatementClass;
use JsonException;

/**
 * Contract §8.3/§8.4, T-INS-4 — the gate between what a model wrote and what a
 * customer may ever read.
 *
 * All or nothing. The output is accepted only if EVERY statement passes; one
 * bad statement discards the whole answer, because a partially rendered
 * answer would be an answer the rules did not approve (§8.3 "anything invalid
 * is discarded and nothing is shown").
 *
 * The schema is fixed: an object whose only key is `statements`, a list of 1
 * to 3 objects whose only keys are `class`, `text` and `fact_refs`. Then:
 *
 *  - every `fact_refs` entry must be a reference the facts actually contain —
 *    a made-up reference is rejected, never silently shown;
 *  - `text` is at most 280 characters;
 *  - KNOWN cites at least one fact and restates it. Only a metric fact has a
 *    value text can be checked against, so a known statement must cite a
 *    metric and contain that metric's current or previous count. A "known"
 *    claim about anything else cannot be verified here, and is rejected;
 *  - LIKELY uses hedged wording ("may", "likely", "could");
 *  - no statement of any class uses causal wording ("caused", "because of",
 *    "led to", "resulted in", "drove", "thanks to"). No canonical fact is
 *    causal (§8.4), so there is no case in which causal wording is allowed —
 *    except the contract's own fixed disclaimer, "We can't tell whether
 *    {event} caused it.", which denies a cause rather than claiming one.
 */
final class CooInsightOutputValidator
{
    public const MAX_STATEMENTS = 3;

    public const MAX_TEXT_LENGTH = 280;

    public const CAUSAL_PHRASES = ['caused', 'because of', 'led to', 'resulted in', 'drove', 'thanks to'];

    public const HEDGE_WORDS = ['may', 'likely', 'could'];

    /**
     * @return array<int, array{class: string, text: string, fact_refs: array<int, string>}>|null
     *   the validated statements, or null when the output must be discarded
     */
    public function validate(?string $raw, CooInsightFacts $facts): ?array
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded) || array_is_list($decoded) || array_keys($decoded) !== ['statements']) {
            return null;
        }

        $statements = $decoded['statements'];

        if (! is_array($statements) || ! array_is_list($statements) || $statements === [] || count($statements) > self::MAX_STATEMENTS) {
            return null;
        }

        $allowedRefs = $facts->factRefs();
        $validated = [];

        foreach ($statements as $statement) {
            $clean = $this->statement($statement, $facts, $allowedRefs);

            if ($clean === null) {
                return null;
            }

            $validated[] = $clean;
        }

        return $validated;
    }

    /**
     * @param  array<int, string>  $allowedRefs
     * @return array{class: string, text: string, fact_refs: array<int, string>}|null
     */
    private function statement(mixed $statement, CooInsightFacts $facts, array $allowedRefs): ?array
    {
        if (! is_array($statement) || array_is_list($statement)) {
            return null;
        }

        $keys = array_keys($statement);
        sort($keys);

        if ($keys !== ['class', 'fact_refs', 'text']) {
            return null;
        }

        $class = is_string($statement['class']) ? CooInsightStatementClass::tryFrom($statement['class']) : null;
        $text = is_string($statement['text']) ? trim($statement['text']) : '';
        $refs = $statement['fact_refs'];

        if ($class === null || $text === '' || mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            return null;
        }

        if (! is_array($refs) || ! array_is_list($refs)) {
            return null;
        }

        foreach ($refs as $ref) {
            if (! is_string($ref) || ! in_array($ref, $allowedRefs, true)) {
                return null;
            }
        }

        $refs = array_values(array_unique($refs));

        if ($this->claimsCause($text)) {
            return null;
        }

        if ($class === CooInsightStatementClass::Known && ! $this->restatesACitedMetric($text, $refs, $facts)) {
            return null;
        }

        if ($class === CooInsightStatementClass::Likely && ! $this->isHedged($text)) {
            return null;
        }

        return ['class' => $class->value, 'text' => $text, 'fact_refs' => $refs];
    }

    private function claimsCause(string $text): bool
    {
        // The contract's fixed disclaimer denies a cause; it is the one place
        // the word may appear, and only in exactly that sentence.
        $withoutDisclaimer = (string) preg_replace("/We can(?:'|’)t tell whether [^.]{1,160}? caused it\\./iu", '', $text);

        foreach (self::CAUSAL_PHRASES as $phrase) {
            if (preg_match('/\b' . preg_quote($phrase, '/') . '\b/iu', $withoutDisclaimer) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isHedged(string $text): bool
    {
        foreach (self::HEDGE_WORDS as $word) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/iu', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, string> $refs */
    private function restatesACitedMetric(string $text, array $refs, CooInsightFacts $facts): bool
    {
        foreach ($refs as $ref) {
            if (! str_starts_with($ref, 'metric.')) {
                continue;
            }

            $metric = $facts->metric(substr($ref, strlen('metric.')));

            if ($metric === null) {
                continue;
            }

            foreach ([$metric['current'], $metric['previous']] as $value) {
                foreach (array_unique([(string) $value, number_format($value)]) as $written) {
                    if (preg_match('/(?<![\d,.])' . preg_quote($written, '/') . '(?![\d,])/u', $text) === 1) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
