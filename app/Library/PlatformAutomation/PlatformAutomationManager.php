<?php

namespace App\Library\PlatformAutomation;

use App\Enums\PlatformAutomation\PlatformAutomationStatus;
use App\Models\PlatformAutomation;
use App\Models\PlatformAutomationVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of Platform automation definitions. Every definition change is an
 * immutable version row, so a run can always be traced to exactly what was
 * configured when it fired.
 */
class PlatformAutomationManager
{
    public function __construct(private readonly PlatformDefinitionValidator $validator)
    {
    }

    /**
     * @param  array<string, mixed>  $input  name, description, trigger_type, definition
     */
    public function create(array $input, int $actorUserId, ?string $recipeKey = null): PlatformAutomation
    {
        $name = $this->name($input['name'] ?? '');
        $trigger = (string) ($input['trigger_type'] ?? '');
        $definition = $this->validated($trigger, (array) ($input['definition'] ?? []));

        return DB::transaction(function () use ($input, $name, $trigger, $definition, $actorUserId, $recipeKey) {
            $automation = PlatformAutomation::create([
                'uid' => (string) Str::uuid(),
                'name' => $name,
                'description' => $this->description($input['description'] ?? null),
                'status' => PlatformAutomationStatus::Draft->value,
                'trigger_type' => $trigger,
                'definition' => $definition,
                'version' => 1,
                'recipe_key' => $recipeKey,
                'created_by_user_id' => $actorUserId,
                'updated_by_user_id' => $actorUserId,
            ]);

            $this->snapshot($automation, $actorUserId);

            return $automation;
        });
    }

    /** @param  array<string, mixed>  $input */
    public function update(PlatformAutomation $automation, array $input, int $actorUserId): PlatformAutomation
    {
        $this->assertNotArchived($automation);

        $name = $this->name($input['name'] ?? $automation->name);
        $trigger = (string) ($input['trigger_type'] ?? $automation->trigger_type);
        $definition = $this->validated($trigger, (array) ($input['definition'] ?? $automation->definition));

        return DB::transaction(function () use ($automation, $input, $name, $trigger, $definition, $actorUserId) {
            $locked = PlatformAutomation::query()->lockForUpdate()->findOrFail($automation->id);
            $changed = $locked->trigger_type !== $trigger || self::canonical($locked->definition) !== self::canonical($definition);

            $locked->forceFill([
                'name' => $name,
                'description' => $this->description($input['description'] ?? $locked->description),
                'trigger_type' => $trigger,
                'definition' => $definition,
                'updated_by_user_id' => $actorUserId,
            ]);

            if ($changed) {
                $locked->version = $locked->version + 1;
            }

            $locked->save();

            if ($changed) {
                $this->snapshot($locked, $actorUserId);
            }

            return $locked;
        });
    }

    public function enable(PlatformAutomation $automation, int $actorUserId): PlatformAutomation
    {
        $this->assertNotArchived($automation);
        // Re-validate: a definition saved before a trigger was withdrawn must not be enabled.
        $this->validated($automation->trigger_type, (array) $automation->definition);

        return $this->setStatus($automation, PlatformAutomationStatus::Enabled, $actorUserId);
    }

    public function disable(PlatformAutomation $automation, int $actorUserId): PlatformAutomation
    {
        $this->assertNotArchived($automation);

        return $this->setStatus($automation, PlatformAutomationStatus::Disabled, $actorUserId);
    }

    public function archive(PlatformAutomation $automation, int $actorUserId): PlatformAutomation
    {
        $automation->forceFill(['archived_at' => now()]);

        return $this->setStatus($automation, PlatformAutomationStatus::Archived, $actorUserId);
    }

    /** A copy is always a fresh DRAFT: duplicating never switches anything on. */
    public function duplicate(PlatformAutomation $automation, int $actorUserId): PlatformAutomation
    {
        return $this->create([
            'name' => Str::limit('Copy of ' . $automation->name, 120, ''),
            'description' => $automation->description,
            'trigger_type' => $automation->trigger_type,
            'definition' => $automation->definition,
        ], $actorUserId, $automation->recipe_key);
    }

    public function createFromRecipe(string $recipeKey, int $actorUserId): PlatformAutomation
    {
        $recipe = PlatformAutomationRecipes::all()[$recipeKey] ?? null;

        if ($recipe === null || ! $recipe['available']) {
            throw ValidationException::withMessages(['recipe' => [$recipe['unavailable_reason'] ?? 'Unknown recipe.']]);
        }

        return $this->create([
            'name' => $recipe['name'],
            'description' => $recipe['description'],
            'trigger_type' => $recipe['trigger_type'],
            'definition' => $recipe['definition'],
        ], $actorUserId, $recipeKey);
    }

    /** Key-order-independent form: MySQL reorders JSON object keys, so identity on arrays would lie. */
    private static function canonical(mixed $value): string
    {
        $sort = function (mixed $v) use (&$sort) {
            if (! is_array($v)) {
                return $v;
            }
            $v = array_map($sort, $v);
            if (! array_is_list($v)) {
                ksort($v);
            }

            return $v;
        };

        return (string) json_encode($sort($value));
    }

    private function setStatus(PlatformAutomation $automation, PlatformAutomationStatus $status, int $actorUserId): PlatformAutomation
    {
        $automation->forceFill(['status' => $status->value, 'updated_by_user_id' => $actorUserId])->save();

        return $automation;
    }

    private function snapshot(PlatformAutomation $automation, int $actorUserId): void
    {
        PlatformAutomationVersion::create([
            'automation_id' => $automation->id,
            'version_number' => $automation->version,
            'trigger_type' => $automation->trigger_type,
            'definition' => $automation->definition,
            'definition_hash' => hash('sha256', self::canonical([$automation->trigger_type, $automation->definition])),
            'created_by_user_id' => $actorUserId,
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(string $trigger, array $definition): array
    {
        $result = $this->validator->check($trigger, $definition);

        if ($result['errors'] !== []) {
            throw ValidationException::withMessages(['definition' => $result['errors']]);
        }

        return $result['definition'];
    }

    private function name(mixed $name): string
    {
        $name = trim((string) $name);

        if ($name === '' || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['name' => ['Give the automation a name (up to 120 characters).']]);
        }

        return $name;
    }

    private function description(mixed $description): ?string
    {
        $description = trim((string) $description);

        return $description === '' ? null : Str::limit($description, 500, '');
    }

    private function assertNotArchived(PlatformAutomation $automation): void
    {
        if ($automation->status === PlatformAutomationStatus::Archived) {
            throw ValidationException::withMessages(['automation' => ['An archived automation cannot be changed.']]);
        }
    }
}
