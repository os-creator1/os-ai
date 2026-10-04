<?php

namespace App\Library\PlatformAutomation;

use App\Enums\PlatformAutomation\PlatformTargetType;

/**
 * Validates and NORMALISES a Platform automation definition before it can be saved:
 *   ['params' => [...], 'conditions' => [[fact, op, value]], 'steps' => [[key, action, params]]].
 *
 * Fail-closed: an unknown trigger/action/fact/merge token, an unavailable trigger, a
 * step whose recipient or subject cannot exist for the trigger's target type, or a
 * non-HTTPS webhook is an error — never silently dropped.
 */
final class PlatformDefinitionValidator
{
    public const MAX_STEPS = 12;
    private const MAX_WAIT_MINUTES = 60 * 24 * 90;

    /** Which target types an action (or recipient) can be resolved for. */
    private const ACTION_NEEDS = [
        'suspend_business' => ['business'],
        'reactivate_business' => ['business'],
        'restore_workspace_access' => ['workspace', 'business', 'subscription'],
        'change_plan' => ['workspace', 'business', 'subscription'],
        'resend_email_verification' => ['user'],
        'send_password_reset_link' => ['user'],
        'send_announcement' => ['user', 'workspace', 'business', 'subscription', 'provider'],
        'create_internal_task' => ['user', 'workspace', 'business', 'subscription', 'provider', 'platform'],
        'add_internal_note' => ['user', 'workspace', 'business', 'subscription', 'provider', 'platform'],
        'flag_manual_review' => ['user', 'workspace', 'business', 'subscription', 'provider'],
    ];

    private const RECIPIENT_NEEDS = [
        'target_user' => ['user'],
        'workspace_owner' => ['workspace', 'business', 'subscription'],
        'business_owner' => ['business', 'provider'],
        'platform_admins' => ['user', 'workspace', 'business', 'subscription', 'provider', 'platform'],
    ];

    /**
     * @param  array<string, mixed>  $definition
     * @return array{definition: array<string, mixed>, errors: list<string>}
     */
    public function check(string $triggerType, array $definition): array
    {
        $errors = [];
        $triggers = PlatformAutomationCatalog::triggers();
        $trigger = $triggers[$triggerType] ?? null;

        if ($trigger === null || ! ($trigger['available'] ?? false)) {
            return ['definition' => [], 'errors' => ['That trigger is not available.']];
        }

        $targetType = (string) $trigger['target'];
        $params = $this->cleanParams($trigger['params'], (array) ($definition['params'] ?? []), 'Trigger', $errors);

        $conditions = [];
        foreach ((array) ($definition['conditions'] ?? []) as $i => $condition) {
            $fact = (string) ($condition['fact'] ?? '');
            $op = (string) ($condition['op'] ?? 'eq');
            $value = trim((string) ($condition['value'] ?? ''));

            if ($fact === '' && $value === '') {
                continue; // an empty row in the editor
            }
            if (! isset(PlatformAutomationCatalog::FACTS[$fact])) {
                $errors[] = 'Condition ' . ($i + 1) . ': unknown field.';
                continue;
            }
            if (! isset(PlatformAutomationCatalog::OPERATORS[$op])) {
                $errors[] = 'Condition ' . ($i + 1) . ': unknown operator.';
                continue;
            }
            if ($value === '' || mb_strlen($value) > 120) {
                $errors[] = 'Condition ' . ($i + 1) . ': enter a value.';
                continue;
            }
            $conditions[] = ['fact' => $fact, 'op' => $op, 'value' => $value];
        }

        $steps = [];
        $stepList = array_values((array) ($definition['steps'] ?? []));
        if ($stepList === []) {
            $errors[] = 'Add at least one step.';
        }
        if (count($stepList) > self::MAX_STEPS) {
            $errors[] = 'An automation can have at most ' . self::MAX_STEPS . ' steps.';
            $stepList = array_slice($stepList, 0, self::MAX_STEPS);
        }

        $actions = PlatformAutomationCatalog::actions();
        foreach ($stepList as $i => $step) {
            $n = $i + 1;
            $action = (string) ($step['action'] ?? '');
            $meta = $actions[$action] ?? null;

            if ($meta === null) {
                $errors[] = "Step {$n}: choose an action.";
                continue;
            }

            $stepParams = $this->cleanParams($meta['params'], (array) ($step['params'] ?? []), "Step {$n}", $errors);

            if (isset(self::ACTION_NEEDS[$action]) && ! in_array($targetType, self::ACTION_NEEDS[$action], true)) {
                $errors[] = "Step {$n}: \"{$meta['label']}\" cannot act on a {$targetType} trigger.";
            }

            if (isset($stepParams['recipient'])
                && ! in_array($targetType, self::RECIPIENT_NEEDS[$stepParams['recipient']] ?? [], true)) {
                $errors[] = "Step {$n}: this trigger has no {$stepParams['recipient']} to send to.";
            }

            if ($action === 'wait') {
                $minutes = $this->waitMinutes($stepParams);
                if ($minutes < 1 || $minutes > self::MAX_WAIT_MINUTES) {
                    $errors[] = "Step {$n}: wait between a minute and 90 days.";
                }
            }

            if ($action === 'webhook' && ! $this->isSafeWebhookUrl((string) ($stepParams['url'] ?? ''))) {
                $errors[] = "Step {$n}: the webhook must be a public https:// URL.";
            }

            foreach (['title', 'message', 'subject', 'body'] as $field) {
                if (isset($stepParams[$field])) {
                    foreach ($this->unknownTokens((string) $stepParams[$field]) as $token) {
                        $errors[] = "Step {$n}: unknown merge field {{{$token}}}.";
                    }
                }
            }

            $steps[] = ['key' => 's' . $n, 'action' => $action, 'params' => $stepParams];
        }

        return [
            'definition' => ['params' => $params, 'conditions' => $conditions, 'steps' => $steps],
            'errors' => array_values(array_unique($errors)),
        ];
    }

    public static function waitMinutes(array $params): int
    {
        $amount = (int) ($params['amount'] ?? 0);

        return match ((string) ($params['unit'] ?? 'hours')) {
            'minutes' => $amount,
            'days' => $amount * 1440,
            default => $amount * 60,
        };
    }

    /**
     * @param  array<string, array<int, mixed>>  $schema
     * @param  array<string, mixed>  $given
     * @param  list<string>  $errors
     * @return array<string, mixed>
     */
    private function cleanParams(array $schema, array $given, string $label, array &$errors): array
    {
        $clean = [];

        foreach ($schema as $key => $spec) {
            [$name, $type, $required] = [$spec[0], $spec[1], $spec[2]];
            $options = $spec[3] ?? null;
            $raw = $given[$key] ?? ($spec[4] ?? null);
            $value = is_scalar($raw) ? trim((string) $raw) : '';

            if ($value === '') {
                if ($required) {
                    $errors[] = "{$label}: {$name} is required.";
                }
                continue;
            }

            if ($type === 'number') {
                if (! ctype_digit($value) || (int) $value < 1 || (int) $value > 100000) {
                    $errors[] = "{$label}: {$name} must be a whole number of at least 1.";
                    continue;
                }
                $clean[$key] = (int) $value;
            } elseif ($type === 'select') {
                if (! array_key_exists($value, (array) $options)) {
                    $errors[] = "{$label}: {$name} has an unknown choice.";
                    continue;
                }
                $clean[$key] = $value;
            } else {
                if (mb_strlen($value) > ($type === 'textarea' ? 2000 : 300)) {
                    $errors[] = "{$label}: {$name} is too long.";
                    continue;
                }
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /** @return list<string> */
    private function unknownTokens(string $text): array
    {
        preg_match_all('/\{\{\s*([^}]*?)\s*\}\}/', $text, $m);

        return array_values(array_filter($m[1], fn ($t) => ! in_array($t, PlatformAutomationCatalog::MERGE_TOKENS, true)));
    }

    private function isSafeWebhookUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return str_contains($host, '.');
    }
}
