<?php

namespace App\Library\Website\Setup;

/**
 * Website Builder redesign — pure, stateless: given a published
 * QuestionnaireVersion's step/question tree and a response's live
 * `answers`, computes which steps are currently visible, evaluating each
 * step's own `conditional_visibility` rule against the answers so far
 * (e.g. the Photobooth Backdrops step only appears once an earlier
 * "do you offer backdrops?" answer is true). This is what keeps the
 * wizard's back arrow correct even after an earlier answer changes what
 * later steps exist — a step that becomes hidden by a changed answer is
 * simply skipped, never shown stale or left dangling.
 *
 * Each entry in a version's `definition['steps']` is ONE question ("one
 * setup question per screen" — a step whose type is `repeatable_group`
 * collects several structured sub-entries on that one screen, but is
 * still one step/question in this tree, never several).
 *
 * @phpstan-type Step array{key: string, prompt: string, help_text: ?string, input_type: string, required: bool, options: ?array, conditional_visibility: ?array{depends_on: string, condition: string, value: mixed}, target_module: string, target_field: ?string, ai_instructions: ?string}
 */
final class QuestionnaireStepResolver
{
    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<string, mixed>  $answers
     * @return array<int, array<string, mixed>> only the steps currently visible, in definition order
     */
    public function visibleSteps(array $steps, array $answers): array
    {
        return array_values(array_filter($steps, fn (array $step) => $this->isVisible($step, $answers)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<string, mixed>  $answers
     */
    public function stepAt(array $steps, array $answers, string $stepKey): ?array
    {
        foreach ($this->visibleSteps($steps, $answers) as $step) {
            if ($step['key'] === $stepKey) {
                return $step;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<string, mixed>  $answers
     */
    public function firstStepKey(array $steps, array $answers): ?string
    {
        $visible = $this->visibleSteps($steps, $answers);

        return $visible[0]['key'] ?? null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<string, mixed>  $answers
     */
    public function nextStepKey(array $steps, array $answers, string $currentStepKey): ?string
    {
        $visible = $this->visibleSteps($steps, $answers);
        $keys = array_column($visible, 'key');
        $position = array_search($currentStepKey, $keys, true);

        if ($position === false) {
            return null;
        }

        // Screen-aware: the "next" step is the first step of the NEXT
        // screen, skipping any remaining steps that share the current
        // step's screen (a step with no `screen` is its own screen, so a
        // definition without screens behaves exactly as before).
        $next = $position + 1;
        $screen = $visible[$position]['screen'] ?? null;
        while ($screen !== null && isset($visible[$next]) && ($visible[$next]['screen'] ?? null) === $screen) {
            $next++;
        }

        return $keys[$next] ?? null;
    }

    /**
     * Wizard SCREENS: runs of consecutive visible steps sharing one
     * `screen` key (a step without one is a screen of its own). A screen is
     * presentation only — every step in it keeps its own key, answer,
     * validation and application path.
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<string, mixed>  $answers
     * @return array<int, array<int, array<string, mixed>>> screens in order, each a list of its steps
     */
    public function screens(array $steps, array $answers): array
    {
        $screens = [];
        $previousScreen = null;

        foreach ($this->visibleSteps($steps, $answers) as $step) {
            $screen = $step['screen'] ?? null;

            if ($screen !== null && $screen === $previousScreen) {
                $screens[array_key_last($screens)][] = $step;
            } else {
                $screens[] = [$step];
            }

            $previousScreen = $screen;
        }

        return $screens;
    }

    /**
     * The screen containing a visible step: every step on it, in order
     * (empty when the step is unknown or hidden). The FIRST entry's key is
     * the screen's address (current_step_key, URLs, Back/Next).
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<string, mixed>  $answers
     * @return array<int, array<string, mixed>>
     */
    public function screenFor(array $steps, array $answers, string $stepKey): array
    {
        foreach ($this->screens($steps, $answers) as $screen) {
            if (in_array($stepKey, array_column($screen, 'key'), true)) {
                return $screen;
            }
        }

        return [];
    }

    /**
     * The back arrow: the previously-visited step, recomputed against the
     * CURRENT answers — if an earlier step's visibility changed, the
     * owner is taken to whichever step is now immediately before the
     * current one, never a stale/hidden step.
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<string, mixed>  $answers
     */
    public function previousStepKey(array $steps, array $answers, string $currentStepKey): ?string
    {
        $visible = $this->visibleSteps($steps, $answers);
        $keys = array_column($visible, 'key');
        $position = array_search($currentStepKey, $keys, true);

        if ($position === false) {
            return null;
        }

        // Screen-aware: step back to the FIRST step of the previous screen.
        $screenStart = $position;
        $screen = $visible[$position]['screen'] ?? null;
        while ($screen !== null && $screenStart > 0 && ($visible[$screenStart - 1]['screen'] ?? null) === $screen) {
            $screenStart--;
        }

        if ($screenStart === 0) {
            return null;
        }

        $previous = $screenStart - 1;
        $previousScreen = $visible[$previous]['screen'] ?? null;
        while ($previousScreen !== null && $previous > 0 && ($visible[$previous - 1]['screen'] ?? null) === $previousScreen) {
            $previous--;
        }

        return $keys[$previous];
    }

    /**
     * Every currently-visible required question has a non-empty answer.
     * A question that is hidden by a conditional-visibility rule is never
     * required, however its own `required` flag reads — visibility always
     * wins over requiredness.
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<string, mixed>  $answers
     */
    public function isComplete(array $steps, array $answers): bool
    {
        foreach ($this->visibleSteps($steps, $answers) as $step) {
            if (($step['required'] ?? false) && $this->isEmptyAnswer($answers[$step['key']] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function isVisible(array $step, array $answers): bool
    {
        $rule = $step['conditional_visibility'] ?? null;

        if ($rule === null) {
            return true;
        }

        $dependsOnValue = $answers[$rule['depends_on']] ?? null;
        $condition = $rule['condition'] ?? 'equals';
        $expected = $rule['value'] ?? null;

        return match ($condition) {
            'equals' => $dependsOnValue === $expected,
            'not_equals' => $dependsOnValue !== $expected,
            'in' => is_array($expected) && in_array($dependsOnValue, $expected, true),
            'not_empty' => ! $this->isEmptyAnswer($dependsOnValue),
            default => true,
        };
    }

    private function isEmptyAnswer(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }
}
