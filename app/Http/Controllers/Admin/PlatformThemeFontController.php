<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\UploadPlatformThemeFontRequest;
use App\Library\Theme\Exceptions\InvalidThemeFontException;
use App\Library\Theme\Exceptions\InvalidThemePresetOperationException;
use App\Library\Theme\PlatformThemeManager;
use App\Library\Theme\ThemeFontValidator;
use App\Models\PlatformThemeFont;
use App\Models\PlatformThemePreset;
use App\Repositories\Contracts\PlatformThemeFontRepository;
use App\Repositories\Contracts\PlatformThemePresetRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Design System M2 Slice 1 contract §6.9/§6.16/§6.18/§9 item 21. The
 * "replace" capability (§4.6) is the same `upload` action reused by the
 * client's file picker — no separate backend route, since a fresh
 * upload always produces its own content-addressed font row (§6.9).
 * serveActive() is the trust-boundary-split PUBLIC route: it never
 * trusts the requested filename alone, only whatever
 * platform_theme_presets WHERE status='active' actually references
 * (§3.7). servePreview() is the separate 'manage theme'-gated route
 * that may serve any preset's font to the in-editor preview (§6.18).
 */
class PlatformThemeFontController extends AdminBaseController
{
    public function __construct(
        private readonly PlatformThemeManager $themeManager,
        private readonly PlatformThemeFontRepository $fonts,
        private readonly PlatformThemePresetRepository $presets,
        private readonly ThemeFontValidator $fontValidator,
    ) {
    }

    public function upload(UploadPlatformThemeFontRequest $request): RedirectResponse
    {
        $this->authorize('manage theme');

        $file = $request->file('font');
        $contents = file_get_contents($file->getRealPath());
        $extension = strtolower($file->getClientOriginalExtension());

        try {
            $this->fontValidator->validateContents($contents, $extension);
        } catch (InvalidThemeFontException $e) {
            return back()->withErrors(['font' => $e->getMessage()]);
        }

        $safeFilename = $this->fontValidator->generateSafeFilename($contents, $extension);

        $existing = $this->fonts->findBySafeFilename($safeFilename);
        if ($existing !== null) {
            return redirect()
                ->route('admin.theme-presets.index')
                ->with('success', 'This font is already uploaded.')
                ->with('uploaded_font_id', $existing->id);
        }

        Storage::disk('local')->put("theme-fonts/{$safeFilename}", $contents);

        $font = $this->fonts->create([
            'safe_filename' => $safeFilename,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size_bytes' => strlen($contents),
            'storage_path' => "theme-fonts/{$safeFilename}",
            'uploaded_by_user_id' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.theme-presets.index')
            ->with('success', 'Font uploaded.')
            ->with('uploaded_font_id', $font->id);
    }

    public function destroy(PlatformThemeFont $font): RedirectResponse
    {
        $this->authorize('manage theme');

        try {
            $this->themeManager->deleteFont($font, Auth::id());
        } catch (InvalidThemePresetOperationException $e) {
            return back()->withErrors(['font' => $e->getMessage()]);
        }

        Storage::disk('local')->delete($font->storage_path);

        return redirect()->route('admin.theme-presets.index')->with('success', 'Font deleted.');
    }

    public function serveActive(string $safeFilename): Response
    {
        $active = $this->presets->findActive();

        if ($active === null
            || $active->font_choice !== PlatformThemePreset::FONT_CHOICE_UPLOADED
            || $active->font_uploaded_id === null
        ) {
            abort(404);
        }

        $font = $this->fonts->findById($active->font_uploaded_id);
        if ($font === null || $font->safe_filename !== $safeFilename) {
            abort(404);
        }

        return $this->streamFont($font);
    }

    public function servePreview(PlatformThemeFont $font): Response
    {
        $this->authorize('manage theme');

        return $this->streamFont($font);
    }

    private function streamFont(PlatformThemeFont $font): Response
    {
        $contents = Storage::disk('local')->get($font->storage_path);
        abort_if($contents === null, 404);

        $contentType = str_ends_with($font->safe_filename, '.woff2') ? 'font/woff2' : 'font/woff';

        return response($contents, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
