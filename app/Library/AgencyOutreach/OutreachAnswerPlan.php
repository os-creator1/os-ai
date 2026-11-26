<?php

namespace App\Library\AgencyOutreach;

/**
 * What OutreachQuestionMatcher found in one prospect reply: the requests it read, the
 * approved entries that answer them (entry id => prospect order), and the requests no
 * approved answer covers (request index => the prospect's wording). A plain value.
 */
final class OutreachAnswerPlan
{
    /**
     * @param  list<string>  $requests
     * @param  array<string, int>  $answers
     * @param  array<int, string>  $unresolved
     */
    public function __construct(
        public readonly array $requests,
        public readonly array $answers,
        public readonly array $unresolved,
        public readonly bool $usedAi,
        public readonly bool $aiAttempted,
    ) {
    }

    public static function none(): self
    {
        return new self([], [], [], false, false);
    }

    public function hasRequests(): bool
    {
        return $this->requests !== [];
    }

    public function hasAnswers(): bool
    {
        return $this->answers !== [];
    }

    /** @return list<string> entry ids in the prospect's order */
    public function orderedAnswerIds(): array
    {
        $answers = $this->answers;
        asort($answers);

        return array_keys($answers);
    }

    /** @return list<string> the unanswered wording, in the prospect's order, without repeats */
    public function unansweredQuestions(): array
    {
        $unresolved = $this->unresolved;
        ksort($unresolved);
        $out = [];

        foreach ($unresolved as $text) {
            $out[OutreachFaq::aliasKey($text)] ??= $text;
        }

        return array_values($out);
    }
}
