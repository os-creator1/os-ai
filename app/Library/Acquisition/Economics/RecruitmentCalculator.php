<?php

namespace App\Library\Acquisition\Economics;

/**
 * Teacher Recruitment — COMPLETELY SEPARATE from student economics.
 *
 * The Business outcome is a HIRE, not a lead. There is no revenue and no LTV
 * here, so this calculator can never support a profitability claim: it only
 * judges cost per qualified applicant and cost per hire against limits the
 * owner set. The qualified-applicant -> hire rate is the product of the three
 * stage rates and exists only when ALL THREE are known.
 */
final class RecruitmentCalculator extends AbstractEconomicsCalculator
{
    public const KEY = 'recruitment';

    public function key(): string
    {
        return self::KEY;
    }

    public function inputs(): array
    {
        return [
            'teachers_needed' => ['type' => 'count', 'label' => 'How many teachers do you currently need?', 'help' => 'Context for you; it is not used in any calculation.'],
            'subjects_needed' => ['type' => 'text', 'label' => 'Which subjects do you need teachers for?', 'help' => 'Context for you; it is not used in any calculation.'],
            'target_qualified_cpl' => ['type' => 'money', 'label' => 'Target cost per qualified applicant', 'help' => 'An applicant who passes your first screening.'],
            'hard_cpl' => ['type' => 'money', 'label' => 'Hard maximum cost per qualified applicant', 'help' => 'The most you would ever accept.'],
            'target_cac' => ['type' => 'money', 'label' => 'Target cost per hired teacher', 'help' => 'What you would be happy to spend on ads for each teacher you actually hire.'],
            'hard_cac' => ['type' => 'money', 'label' => 'Hard maximum cost per hired teacher', 'help' => 'The most you would ever accept.'],
            'application_to_screened_pct' => ['type' => 'percent', 'label' => 'Applications that pass screening (%)', 'help' => 'Leave as "I don\'t know yet" if you have not tracked it.'],
            'screened_to_interview_pct' => ['type' => 'percent', 'label' => 'Screened applicants who get an interview (%)', 'help' => 'Optional.'],
            'interview_to_hire_pct' => ['type' => 'percent', 'label' => 'Interviewed applicants who are hired (%)', 'help' => 'Optional.'],
        ];
    }

    public function profile(array $answers, array $unknown): EconomicsProfile
    {
        $targetCpl = $this->micros($this->number($answers, $unknown, 'target_qualified_cpl'));
        $hardCpl = $this->micros($this->number($answers, $unknown, 'hard_cpl'));
        $targetCac = $this->micros($this->number($answers, $unknown, 'target_cac'));
        $hardCac = $this->micros($this->number($answers, $unknown, 'hard_cac'));

        $stages = [
            $this->rate($this->number($answers, $unknown, 'application_to_screened_pct')),
            $this->rate($this->number($answers, $unknown, 'screened_to_interview_pct')),
            $this->rate($this->number($answers, $unknown, 'interview_to_hire_pct')),
        ];

        $derived = [];
        $rate = null;

        // From a qualified (screened) applicant to a hire: the two later stage
        // rates. Needs both; the first rate describes the step BEFORE "qualified".
        if ($stages[1] !== null && $stages[2] !== null) {
            $rate = $stages[1] * $stages[2];
            $derived[] = ['label' => 'Qualified applicant to hire', 'value' => number_format($rate * 100, 1) . '%', 'note' => 'Screened-to-interview rate x interview-to-hire rate, from your own answers.'];
        }

        if ($targetCpl === null && $targetCac !== null && $rate !== null && $rate > 0) {
            $targetCpl = (int) round($targetCac * $rate);
            $derived[] = ['label' => 'Target cost per qualified applicant', 'value' => $this->format($targetCpl), 'note' => 'Derived: your target cost per hire x the qualified-applicant-to-hire rate.'];
        }

        [$unknownKeys, $unanswered] = $this->coverage($answers, $unknown);

        // No revenue, so no contribution and no LTV: never a profitability claim.
        return new EconomicsProfile($targetCpl, $hardCpl, $targetCac, $hardCac, $rate, null, null, [], $derived, $unknownKeys, $unanswered);
    }
}
