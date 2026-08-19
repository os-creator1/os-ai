<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\ActivatePlatformThemePresetRequest;
use App\Http\Requests\Admin\CreatePlatformThemePresetRequest;
use App\Http\Requests\Admin\DuplicatePlatformThemePresetRequest;
use App\Http\Requests\Admin\RenamePlatformThemePresetRequest;
use App\Http\Requests\Admin\UpdatePlatformThemePresetRequest;
use App\Library\Theme\Exceptions\InvalidThemePresetOperationException;
use App\Library\Theme\Exceptions\UnsafeThemeContrastException;
use App\Library\Theme\PlatformThemeManager;
use App\Library\Theme\ThemeColorDerivationService;
use App\Library\Theme\ThemeContrastValidator;
use App\Models\PlatformThemePreset;
use App\Repositories\Contracts\PlatformThemeFontRepository;
use App\Repositories\Contracts\PlatformThemePresetRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Design System M2 Slice 1 contract §9 item 20. Every mutating action
 * delegates entirely to PlatformThemeManager (§6.13/§6.14) — this
 * controller never touches the preset repository for a write.
 */
class PlatformThemePresetController extends AdminBaseController
{
    public function __construct(
        private readonly PlatformThemeManager $themeManager,
        private readonly PlatformThemePresetRepository $presets,
        private readonly PlatformThemeFontRepository $fonts,
        private readonly ThemeContrastValidator $contrastValidator,
        private readonly ThemeColorDerivationService $derivationService,
    ) {
    }

    public function index(): View
    {
        $this->authorize('manage theme');

        return view('admin.theme-settings.index', [
            'presets' => $this->presets->allOrderedByName(),
            'uploadedFonts' => $this->fonts->allOrderedByName()->pluck('original_filename', 'id'),
            'breadcrumbs' => $this->breadcrumbs(),
        ]);
    }

    public function store(CreatePlatformThemePresetRequest $request): RedirectResponse
    {
        $this->authorize('manage theme');

        $factory = $this->presets->findFactory();

        $preset = $this->themeManager->create(
            $factory->input_tokens_json,
            $factory->font_choice,
            $factory->font_bundled_key,
            $factory->font_uploaded_id,
            $request->validated('name'),
            Auth::id()
        );

        return redirect()
            ->route('admin.theme-presets.index', ['preset' => $preset->uid])
            ->with('success', 'Preset created.');
    }

    public function show(PlatformThemePreset $preset): JsonResponse
    {
        $this->authorize('manage theme');

        return response()->json([
            'uid' => $preset->uid,
            'name' => $preset->name,
            'status' => $preset->status,
            'is_factory' => $preset->is_factory,
            'input_tokens' => $preset->input_tokens_json,
            'overrides' => $preset->overrides_json,
            'derived_tokens' => $preset->derived_tokens_json,
            'font_choice' => $preset->font_choice,
            'font_bundled_key' => $preset->font_bundled_key,
            'font_uploaded_id' => $preset->font_uploaded_id,
            'created_by_user_id' => $preset->created_by_user_id,
            'created_at' => $preset->created_at?->toDateTimeString(),
            'updated_by_user_id' => $preset->updated_by_user_id,
            'updated_at' => $preset->updated_at?->toDateTimeString(),
        ]);
    }

    public function update(UpdatePlatformThemePresetRequest $request, PlatformThemePreset $preset): RedirectResponse
    {
        $this->authorize('manage theme');

        $inputTokens = collect($request->validated())
            ->except(['overrides', 'font_choice', 'font_bundled_key', 'font_uploaded_id'])
            ->toArray();

        try {
            $updated = $this->themeManager->edit(
                $preset,
                $inputTokens,
                $request->validated('overrides'),
                $request->validated('font_choice'),
                $request->validated('font_bundled_key'),
                $request->validated('font_uploaded_id'),
                Auth::id()
            );
        } catch (InvalidThemePresetOperationException $e) {
            return back()->withErrors(['preset' => $e->getMessage()]);
        } catch (UnsafeThemeContrastException $e) {
            return back()->withErrors(['contrast' => $e->getContrastErrors()]);
        }

        $warnings = $this->contrastValidator->validate($updated->derived_tokens_json)['warnings'];

        return redirect()
            ->route('admin.theme-presets.index', ['preset' => $updated->uid])
            ->with('success', 'Preset saved.')
            ->with('warnings', $warnings);
    }

    /**
     * §6.7: debounced, no-persist contrast check backing the editor's
     * inline errors/warnings — the exact same PHP validator, never
     * duplicated in JS, and never mutating the preset or shared cache.
     */
    public function validateTokens(UpdatePlatformThemePresetRequest $request): JsonResponse
    {
        $this->authorize('manage theme');

        $inputTokens = collect($request->validated())
            ->except(['overrides', 'font_choice', 'font_bundled_key', 'font_uploaded_id'])
            ->toArray();

        $derived = $this->derivationService->deriveFullTokenSet($inputTokens);
        $overrides = $request->validated('overrides');
        if (! empty($overrides)) {
            $derived = array_merge($derived, $overrides);
        }

        return response()->json($this->contrastValidator->validate($derived));
    }

    public function duplicate(DuplicatePlatformThemePresetRequest $request, PlatformThemePreset $preset): RedirectResponse
    {
        $this->authorize('manage theme');

        $duplicate = $this->themeManager->duplicate($preset, Auth::id());

        return redirect()
            ->route('admin.theme-presets.index', ['preset' => $duplicate->uid])
            ->with('success', "Duplicated as \"{$duplicate->name}\".");
    }

    public function rename(RenamePlatformThemePresetRequest $request, PlatformThemePreset $preset): RedirectResponse
    {
        $this->authorize('manage theme');

        try {
            $this->themeManager->rename($preset, $request->validated('name'), Auth::id());
        } catch (InvalidThemePresetOperationException $e) {
            return back()->withErrors(['preset' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.theme-presets.index', ['preset' => $preset->uid])
            ->with('success', 'Preset renamed.');
    }

    public function activate(ActivatePlatformThemePresetRequest $request, PlatformThemePreset $preset): RedirectResponse
    {
        $this->authorize('manage theme');

        try {
            $this->themeManager->activate($preset, Auth::id());
        } catch (InvalidThemePresetOperationException $e) {
            return back()->withErrors(['preset' => $e->getMessage()]);
        } catch (UnsafeThemeContrastException $e) {
            return back()->withErrors(['contrast' => $e->getContrastErrors()]);
        }

        return redirect()->route('admin.theme-presets.index')->with('success', "\"{$preset->name}\" is now active.");
    }

    public function destroy(PlatformThemePreset $preset): RedirectResponse
    {
        $this->authorize('manage theme');

        try {
            $this->themeManager->delete($preset, Auth::id());
        } catch (InvalidThemePresetOperationException $e) {
            return back()->withErrors(['preset' => $e->getMessage()]);
        }

        return redirect()->route('admin.theme-presets.index')->with('success', 'Preset deleted.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function breadcrumbs(): array
    {
        return [
            ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
            ['name' => 'Theme Settings'],
        ];
    }
}
