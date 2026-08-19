<?php

namespace App\Library\Theme;

use App\Library\Theme\Exceptions\InvalidThemePresetOperationException;
use App\Library\Theme\Exceptions\UnsafeThemeContrastException;
use App\Models\PlatformThemeFont;
use App\Models\PlatformThemePreset;
use App\Models\PlatformThemePresetEvent;
use App\Repositories\Contracts\PlatformThemeFontRepository;
use App\Repositories\Contracts\PlatformThemePresetRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Design System M2 Slice 1 contract §6.13/§6.14/§9 item 14. Orchestrates
 * every preset lifecycle operation, the lockForUpdate activation mutex,
 * and DB::afterCommit cache invalidation. This is the single place all
 * preset business rules live — controllers never touch the repositories
 * directly for a mutating operation.
 */
class PlatformThemeManager
{
    public const CACHE_KEY = 'platform_theme:active_style_block';

    public function __construct(
        private readonly PlatformThemePresetRepository $presets,
        private readonly PlatformThemeFontRepository $fonts,
        private readonly ThemeColorDerivationService $derivationService,
        private readonly ThemeContrastValidator $contrastValidator,
    ) {
    }

    /**
     * §6.3: returns null (fail-safe — render nothing, let compiled
     * defaults stand) whenever no active preset exists or its JSON is
     * unusable, so "missing/invalid config" and "Factory active"
     * produce visually identical output.
     *
     * Design System M2 Slice 2 contract §7 Part A: also fails safe when
     * `platform_theme_presets` does not exist yet — the Installer's very
     * first page renders before migrations run. The check is inside this
     * cache closure (not outside it) so it only ever runs while the
     * cache is genuinely cold, never on every request. §7 Part B
     * (`invalidateCache()`, called from `InstallerController::database()`
     * once migrations/seeding finish) is what stops this pre-database
     * `null` from outliving the install.
     */
    public function currentStyleBlock(): ?string
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            if (! Schema::hasTable('platform_theme_presets')) {
                return null;
            }

            $active = $this->presets->findActive();

            if ($active === null || empty($active->derived_tokens_json)) {
                return null;
            }

            return $this->renderStyleBlock($active);
        });
    }

    private function renderStyleBlock(PlatformThemePreset $preset): string
    {
        $tokens = $preset->derived_tokens_json;

        $css = ':root{';
        foreach ($tokens as $name => $value) {
            $css .= "--{$name}:{$value};";
        }
        $css .= '}';

        $fontFace = '';
        if ($preset->font_choice === PlatformThemePreset::FONT_CHOICE_UPLOADED && $preset->font_uploaded_id) {
            $font = $this->fonts->findById($preset->font_uploaded_id);
            if ($font !== null) {
                $fontFace = "@font-face{font-family:'PlatformThemeFont';src:url('/theme-font/{$font->safe_filename}') format('woff2');font-display:swap;}";
                $css .= ':root{--font-family-app:\'PlatformThemeFont\', -apple-system, BlinkMacSystemFont, \'Segoe UI\', Helvetica, Arial, sans-serif;}';
            }
        } elseif ($preset->font_choice === PlatformThemePreset::FONT_CHOICE_BUNDLED
            && $preset->font_bundled_key === 'system-ui') {
            $css .= ':root{--font-family-app:system-ui, -apple-system, BlinkMacSystemFont, \'Segoe UI\', Helvetica, Arial, sans-serif;}';
        }

        return '<style id="platform-theme-overrides">' . $fontFace . $css . '</style>';
    }

    /**
     * Design System M2 Slice 2 contract §7 Part B: made public so
     * `InstallerController::database()` can force a cache miss on the
     * very next render once migrations/seeding complete, closing the
     * window where the pre-database `null` from `currentStyleBlock()`
     * (§7 Part A) would otherwise be cached forever. `DB::afterCommit`
     * still applies — with no active transaction (the Installer's own
     * call site) Laravel runs the callback immediately; from within
     * `activate()`'s transaction it still defers correctly.
     */
    public function invalidateCache(): void
    {
        DB::afterCommit(function () {
            Cache::forget(self::CACHE_KEY);
        });
    }

    public function create(array $inputTokens, string $font_choice, ?string $fontBundledKey, ?int $fontUploadedId, string $name, ?int $actorUserId): PlatformThemePreset
    {
        $derived = $this->derivationService->deriveFullTokenSet($inputTokens);

        $preset = $this->presets->create([
            'name' => $name,
            'status' => PlatformThemePreset::STATUS_DRAFT,
            'is_factory' => false,
            'input_tokens_json' => $inputTokens,
            'overrides_json' => null,
            'derived_tokens_json' => $derived,
            'font_choice' => $font_choice,
            'font_bundled_key' => $fontBundledKey,
            'font_uploaded_id' => $fontUploadedId,
            'created_by_user_id' => $actorUserId,
            'updated_by_user_id' => $actorUserId,
        ]);

        $this->recordEvent($preset->id, PlatformThemePresetEvent::EVENT_CREATED, null, $actorUserId);

        return $preset;
    }

    public function duplicate(PlatformThemePreset $source, ?int $actorUserId): PlatformThemePreset
    {
        $name = $this->uniqueCopyName($source->name);

        $duplicate = $this->presets->create([
            'name' => $name,
            'status' => PlatformThemePreset::STATUS_DRAFT,
            'is_factory' => false,
            'input_tokens_json' => $source->input_tokens_json,
            'overrides_json' => $source->overrides_json,
            'derived_tokens_json' => $source->derived_tokens_json,
            'font_choice' => $source->font_choice,
            'font_bundled_key' => $source->font_bundled_key,
            'font_uploaded_id' => $source->font_uploaded_id,
            'created_by_user_id' => $actorUserId,
            'updated_by_user_id' => $actorUserId,
        ]);

        $this->recordEvent(
            $duplicate->id,
            PlatformThemePresetEvent::EVENT_DUPLICATED,
            ['source_preset_id' => $source->id],
            $actorUserId
        );

        return $duplicate;
    }

    private function uniqueCopyName(string $baseName): string
    {
        $candidate = "{$baseName} copy";
        $suffix = 2;
        while ($this->presets->query()->where('name', $candidate)->exists()) {
            $candidate = "{$baseName} copy {$suffix}";
            $suffix++;
        }

        return $candidate;
    }

    /**
     * @throws InvalidThemePresetOperationException
     * @throws UnsafeThemeContrastException
     */
    public function edit(PlatformThemePreset $preset, array $inputTokens, ?array $overrides, string $fontChoice, ?string $fontBundledKey, ?int $fontUploadedId, ?int $actorUserId): PlatformThemePreset
    {
        if ($preset->is_factory) {
            throw new InvalidThemePresetOperationException('The Factory preset cannot be edited directly.');
        }
        if ($preset->isActive()) {
            throw new InvalidThemePresetOperationException('The active preset cannot be edited in place. Duplicate or create a new preset, edit that, then activate it.');
        }

        $derived = $this->derivationService->deriveFullTokenSet($inputTokens);
        if (! empty($overrides)) {
            $derived = array_merge($derived, $overrides);
        }

        $result = $this->contrastValidator->validate($derived);
        if (! empty($result['errors'])) {
            throw new UnsafeThemeContrastException($result['errors']);
        }

        $preset = $this->presets->update($preset, [
            'status' => $preset->isDraft() ? PlatformThemePreset::STATUS_SAVED : $preset->status,
            'input_tokens_json' => $inputTokens,
            'overrides_json' => $overrides,
            'derived_tokens_json' => $derived,
            'font_choice' => $fontChoice,
            'font_bundled_key' => $fontBundledKey,
            'font_uploaded_id' => $fontUploadedId,
            'updated_by_user_id' => $actorUserId,
        ]);

        $this->recordEvent($preset->id, PlatformThemePresetEvent::EVENT_EDITED, null, $actorUserId);

        return $preset;
    }

    /**
     * @throws InvalidThemePresetOperationException
     */
    public function rename(PlatformThemePreset $preset, string $newName, ?int $actorUserId): PlatformThemePreset
    {
        if ($preset->is_factory) {
            throw new InvalidThemePresetOperationException('The Factory preset name is fixed and cannot be renamed.');
        }

        $oldName = $preset->name;
        $preset = $this->presets->update($preset, ['name' => $newName, 'updated_by_user_id' => $actorUserId]);

        $this->recordEvent(
            $preset->id,
            PlatformThemePresetEvent::EVENT_RENAMED,
            ['old_name' => $oldName, 'new_name' => $newName],
            $actorUserId
        );

        return $preset;
    }

    /**
     * §6.14: the transaction's first statement locks the Factory row —
     * a stable, always-existing mutex target — before anything else is
     * read or written, serializing every concurrent activation attempt.
     *
     * §4.6/§6.1: "return to the previously active preset" is not a
     * separate HTTP action — activating whichever preset the most recent
     * activation/rollback event recorded as `previous_active_preset_id`
     * is, mechanically, the same operation (§9 item 26), just recorded
     * with the distinct `rolled_back` event type instead of `activated`.
     *
     * @throws InvalidThemePresetOperationException
     * @throws UnsafeThemeContrastException
     */
    public function activate(PlatformThemePreset $target, ?int $actorUserId): PlatformThemePreset
    {
        return DB::transaction(function () use ($target, $actorUserId) {
            $this->presets->lockFactoryForUpdate();

            $freshTarget = $this->presets->query()->find($target->id);

            if ($freshTarget === null) {
                throw new InvalidThemePresetOperationException('The preset to activate no longer exists.');
            }
            if ($freshTarget->status === PlatformThemePreset::STATUS_DRAFT) {
                throw new InvalidThemePresetOperationException('A Draft preset cannot be activated directly — save it first.');
            }

            $result = $this->contrastValidator->validate($freshTarget->derived_tokens_json);
            if (! empty($result['errors'])) {
                throw new UnsafeThemeContrastException($result['errors']);
            }

            $isRollback = $this->isReturnToPreviousActive($freshTarget->id);

            $previousActive = $this->presets->findActive();
            $previousActiveId = $previousActive?->id;

            if ($previousActive !== null && $previousActive->id !== $freshTarget->id) {
                $this->presets->update($previousActive, ['status' => PlatformThemePreset::STATUS_SAVED]);
            }

            $activated = $this->presets->update($freshTarget, ['status' => PlatformThemePreset::STATUS_ACTIVE]);

            $this->invalidateCache();

            $this->recordEvent(
                $activated->id,
                $isRollback ? PlatformThemePresetEvent::EVENT_ROLLED_BACK : PlatformThemePresetEvent::EVENT_ACTIVATED,
                ['previous_active_preset_id' => $previousActiveId],
                $actorUserId
            );

            return $activated;
        });
    }

    private function isReturnToPreviousActive(int $targetPresetId): bool
    {
        $lastSwitch = PlatformThemePresetEvent::query()
            ->whereIn('event_type', [PlatformThemePresetEvent::EVENT_ACTIVATED, PlatformThemePresetEvent::EVENT_ROLLED_BACK])
            ->orderByDesc('id')
            ->first();

        if ($lastSwitch === null) {
            return false;
        }

        return ($lastSwitch->metadata_json['previous_active_preset_id'] ?? null) === $targetPresetId;
    }

    /**
     * @throws InvalidThemePresetOperationException
     */
    public function delete(PlatformThemePreset $preset, ?int $actorUserId): void
    {
        if ($preset->is_factory) {
            throw new InvalidThemePresetOperationException('The Factory preset cannot be deleted.');
        }
        if ($preset->isActive()) {
            throw new InvalidThemePresetOperationException('The active preset cannot be deleted.');
        }

        $finalName = $preset->name;
        $presetId = $preset->id;

        $this->presets->delete($preset);

        $this->recordEvent($presetId, PlatformThemePresetEvent::EVENT_DELETED, ['name' => $finalName], $actorUserId);
    }

    /**
     * §6.16: prevent deletion uniformly whenever a font is referenced by
     * the active preset or by any other saved preset — never silently
     * reconcile.
     *
     * @throws InvalidThemePresetOperationException
     */
    public function deleteFont(PlatformThemeFont $font, ?int $actorUserId): void
    {
        $referencing = $this->presets->findAllReferencingFont($font->id);

        if ($referencing->isNotEmpty()) {
            $names = $referencing->pluck('name')->implode(', ');
            throw new InvalidThemePresetOperationException(
                "This font is still referenced by: {$names}. Switch those presets to a different font before deleting it."
            );
        }

        $this->fonts->softDelete($font);
    }

    private function recordEvent(int $presetId, string $eventType, ?array $metadata, ?int $actorUserId): void
    {
        $event = new PlatformThemePresetEvent([
            'preset_id' => $presetId,
            'event_type' => $eventType,
            'metadata_json' => $metadata,
            'actor_user_id' => $actorUserId,
        ]);
        $event->created_at = now();
        $event->save();
    }
}
