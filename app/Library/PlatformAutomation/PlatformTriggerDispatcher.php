<?php

namespace App\Library\PlatformAutomation;

use App\Enums\PlatformAutomation\PlatformAutomationStatus;
use App\Enums\PlatformAutomation\PlatformRunState;
use App\Enums\PlatformAutomation\PlatformSafetyClass;
use App\Enums\PlatformAutomation\PlatformStepState;
use App\Enums\PlatformAutomation\PlatformTargetType;
use App\Jobs\PlatformAutomation\ExecutePlatformAutomationRun;
use App\Models\PlatformAutomation;
use App\Models\PlatformAutomationRun;
use App\Models\PlatformAutomationStep;
use App\Models\PlatformAutomationVersion;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only door from an event/state to a Platform run. A trigger carries an EXPLICIT
 * target and an occurrence key; for each enabled automation of that trigger this
 * creates at most ONE run (unique per automation + occurrence key), pinned to the
 * automation's current immutable version, with every step materialised up front.
 *
 * It never touches the Business automation engine (different tables, different
 * runtime) and never reads "the current Business".
 */
class PlatformTriggerDispatcher
{
    /**
     * @param  array<string, scalar>  $context  small facts for conditions (e.g. to_status)
     * @return int runs created
     */
    public function fire(string $triggerType, PlatformTarget $target, string $occurrenceKey, array $context = []): int
    {
        $trigger = PlatformAutomationCatalog::triggers()[$triggerType] ?? null;

        if ($trigger === null || ! $this->targetMatches((string) $trigger['target'], $target)) {
            return 0;
        }

        $created = 0;

        PlatformAutomation::query()
            ->where('status', PlatformAutomationStatus::Enabled->value)
            ->where('trigger_type', $triggerType)
            ->orderBy('id')
            ->each(function (PlatformAutomation $automation) use ($target, $occurrenceKey, $context, &$created) {
                if ($this->fireFor($automation, $target, $occurrenceKey, $context) !== null) {
                    $created++;
                }
            });

        return $created;
    }

    /** @param array<string, scalar> $context */
    public function fireFor(PlatformAutomation $automation, PlatformTarget $target, string $occurrenceKey, array $context = []): ?PlatformAutomationRun
    {
        $version = PlatformAutomationVersion::query()
            ->where('automation_id', $automation->id)
            ->where('version_number', $automation->version)
            ->first();

        if ($version === null) {
            return null;
        }

        $definition = (array) $version->definition;
        $run = new PlatformAutomationRun([
            'workspace_id' => $target->workspaceId,
            'business_id' => $target->businessId,
            'user_id' => $target->userId,
            'context' => $context,
        ]);

        if (! $this->conditionsPass((array) ($definition['conditions'] ?? []), (new PlatformRunContext($run))->facts())) {
            return null;
        }

        try {
            $run = DB::transaction(function () use ($automation, $version, $definition, $target, $occurrenceKey, $context) {
                $run = PlatformAutomationRun::create([
                    'uid' => (string) Str::uuid(),
                    'automation_id' => $automation->id,
                    'version_id' => $version->id,
                    'trigger_type' => $automation->trigger_type,
                    'occurrence_key' => Str::limit($occurrenceKey, 191, ''),
                    'target_type' => $target->type->value,
                    'target_id' => $target->id,
                    'workspace_id' => $target->workspaceId,
                    'business_id' => $target->businessId,
                    'user_id' => $target->userId,
                    'context' => $context === [] ? null : $context,
                    'state' => PlatformRunState::Queued->value,
                    'scheduled_at' => now(),
                ]);

                $actions = PlatformAutomationCatalog::actions();
                foreach (array_values((array) ($definition['steps'] ?? [])) as $i => $step) {
                    $class = PlatformSafetyClass::from($actions[$step['action']]['safety']);

                    PlatformAutomationStep::create([
                        'run_id' => $run->id,
                        'step_index' => $i,
                        'step_key' => (string) $step['key'],
                        'action_type' => (string) $step['action'],
                        'safety_class' => $class->value,
                        'config' => (array) ($step['params'] ?? []),
                        'state' => PlatformStepState::Pending->value,
                    ]);
                }

                return $run;
            });
        } catch (UniqueConstraintViolationException) {
            return null; // the same occurrence already produced its run
        }

        ExecutePlatformAutomationRun::dispatch($run->id)->afterCommit();

        return $run;
    }

    private function targetMatches(string $triggerTarget, PlatformTarget $target): bool
    {
        if ($triggerTarget === $target->type->value) {
            return true;
        }

        // A subscription is a Workspace-level fact; a provider connection is a Business-level one.
        return ($triggerTarget === PlatformTargetType::Workspace->value && $target->type === PlatformTargetType::Subscription)
            || ($triggerTarget === PlatformTargetType::Business->value && $target->type === PlatformTargetType::Provider);
    }

    /**
     * @param  list<array{fact: string, op: string, value: string}>  $conditions
     * @param  array<string, string>  $facts
     */
    private function conditionsPass(array $conditions, array $facts): bool
    {
        foreach ($conditions as $c) {
            $actual = $facts[$c['fact']] ?? null;
            $wanted = array_map('trim', explode(',', (string) $c['value']));

            $ok = match ($c['op']) {
                'neq' => $actual !== null && ! in_array($actual, $wanted, true),
                'in', 'eq' => $actual !== null && in_array($actual, $wanted, true),
                default => false,
            };

            if (! $ok) {
                return false;
            }
        }

        return true;
    }
}
