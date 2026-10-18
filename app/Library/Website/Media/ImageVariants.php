<?php

namespace App\Library\Website\Media;

use Illuminate\Support\Facades\Log;

/**
 * Website V1 final — the ONE responsive-image authority for Website media
 * (Website assets, and the Business-owned backdrop / package pictures the
 * Website also renders).
 *
 * Derivatives are WebP files stored NEXT TO the original, under a `v/`
 * sub-directory, named `<original-basename>-<width>x<height>.webp`. The
 * original is always kept untouched (editing / reprocessing; and every
 * existing reference to it keeps working). Because the name carries the
 * geometry, a variant set is discovered from the file system alone — no
 * schema change, shared automatically by every row that points at the same
 * original (a package picture mirrored into a Website asset shares its
 * Business image's variants), and an older image with no variants simply has
 * an empty set, so every renderer falls back to the original.
 *
 * Rules: never upscale (a variant is strictly narrower than the source, plus
 * one at the source's own width when the source is at most the largest step —
 * a re-encode, not an enlargement); preserve aspect ratio and transparency;
 * honour a JPEG's EXIF orientation (browsers do for the original, GD does
 * not); skip animated/vector/unsupported sources (the original is used); and
 * keep a variant only when it is actually smaller than the original file.
 */
final class ImageVariants
{
    /** Hero, gallery, services, backdrops, banners. */
    public const WIDTHS = [480, 768, 1280, 1920];

    /** A logo is shown at most ~210 CSS px wide: 1x and 2x are plenty. */
    public const LOGO_WIDTHS = [240, 480];

    public const QUALITY = 80;

    private const RASTER_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    /**
     * Writes the derivatives for a stored original. Never throws: a failure
     * simply leaves the original as the only source.
     *
     * @param  array<int, int>  $steps
     * @return array<int, array{w: int, h: int, path: string, size: int}>
     */
    public function generate(string $relativePath, array $steps = self::WIDTHS): array
    {
        try {
            return $this->doGenerate($relativePath, $steps);
        } catch (\Throwable $e) {
            Log::warning('Website image variants were not generated.', ['path' => $relativePath, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * The derivatives that exist on disk for an original, narrowest first.
     *
     * @return array<int, array{w: int, h: int, path: string}>
     */
    public function existing(string $relativePath): array
    {
        $directory = public_path(dirname($relativePath) . '/v');
        $base = pathinfo($relativePath, PATHINFO_FILENAME);
        $variants = [];

        foreach (glob($directory . DIRECTORY_SEPARATOR . $base . '-*.webp') ?: [] as $file) {
            if (preg_match('/-(\d+)x(\d+)\.webp$/', basename($file), $m) === 1) {
                $variants[(int) $m[1]] = ['w' => (int) $m[1], 'h' => (int) $m[2], 'path' => dirname($relativePath) . '/v/' . basename($file)];
            }
        }

        ksort($variants);

        return array_values($variants);
    }

    /** Removes every derivative of an original (never the original). */
    public function delete(string $relativePath): void
    {
        $directory = public_path(dirname($relativePath) . '/v');
        $base = pathinfo($relativePath, PATHINFO_FILENAME);

        foreach (glob($directory . DIRECTORY_SEPARATOR . $base . '-*.webp') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function isSupported(string $relativePath): bool
    {
        return in_array(strtolower(pathinfo($relativePath, PATHINFO_EXTENSION)), self::RASTER_EXTENSIONS, true);
    }

    /**
     * The widths worth producing for a source of this width: every step that
     * is narrower than the source, plus the source's own width when it is no
     * wider than the largest step (so a 1,600px photo is still served as a
     * 1,600px WebP, never as a heavy original).
     *
     * @param  array<int, int>  $steps
     * @return array<int, int>
     */
    public function plannedWidths(int $sourceWidth, array $steps = self::WIDTHS): array
    {
        sort($steps);
        $widths = array_values(array_filter($steps, fn (int $w) => $w < $sourceWidth));

        if ($sourceWidth <= end($steps)) {
            $widths[] = $sourceWidth;
        }

        return array_values(array_unique($widths));
    }

    /**
     * @param  array<int, int>  $steps
     * @return array<int, array{w: int, h: int, path: string, size: int}>
     */
    private function doGenerate(string $relativePath, array $steps): array
    {
        if (! $this->isSupported($relativePath) || ! function_exists('imagewebp')) {
            return [];
        }

        $absolute = public_path($relativePath);
        $info = is_file($absolute) ? @getimagesize($absolute) : false;

        if ($info === false || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            return [];
        }

        $contents = (string) file_get_contents($absolute);

        // Animated WebP cannot be resized by GD; leave it as the original.
        if ($info[2] === IMAGETYPE_WEBP && str_contains(substr($contents, 0, 128), 'ANIM')) {
            return [];
        }

        // Decoding needs roughly width*height*5 bytes; never risk the request.
        $limit = $this->memoryLimitBytes();
        if ($limit > 0 && ($info[0] * $info[1] * 5) > $limit * 0.5) {
            return [];
        }

        $source = @imagecreatefromstring($contents);

        if ($source === false) {
            return [];
        }

        try {
            // Keep transparency through the resize.
            imagealphablending($source, false);
            imagesavealpha($source, true);

            if ($info[2] === IMAGETYPE_JPEG) {
                $source = $this->applyExifOrientation($source, $contents);
            }

            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
            $originalSize = strlen($contents);
            $written = [];

            foreach ($this->plannedWidths($sourceWidth, $steps) as $width) {
                $height = max(1, (int) round($sourceHeight * $width / $sourceWidth));
                $variantPath = dirname($relativePath) . '/v/' . pathinfo($relativePath, PATHINFO_FILENAME) . "-{$width}x{$height}.webp";
                $destination = public_path($variantPath);

                if (! is_dir(dirname($destination)) && ! mkdir(dirname($destination), 0755, true) && ! is_dir(dirname($destination))) {
                    continue;
                }

                $canvas = $width === $sourceWidth ? $source : imagescale($source, $width, $height, IMG_BICUBIC);

                if ($canvas === false) {
                    continue;
                }

                imagepalettetotruecolor($canvas);
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);

                $ok = imagewebp($canvas, $destination, self::QUALITY);

                if ($canvas !== $source) {
                    imagedestroy($canvas);
                }

                $size = $ok && is_file($destination) ? (int) filesize($destination) : 0;

                // A derivative that is not smaller than the original is no optimisation.
                if ($size === 0 || ($width === $sourceWidth && $size >= $originalSize)) {
                    @unlink($destination);

                    continue;
                }

                $written[] = ['w' => $width, 'h' => $height, 'path' => $variantPath, 'size' => $size];
            }

            return $written;
        } finally {
            imagedestroy($source);
        }
    }

    private function applyExifOrientation(\GdImage $image, string $contents): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($contents));
        $orientation = (int) ($exif['Orientation'] ?? 1);

        $rotated = match ($orientation) {
            2 => $this->flip($image, IMG_FLIP_HORIZONTAL),
            3 => imagerotate($image, 180, 0),
            4 => $this->flip($image, IMG_FLIP_VERTICAL),
            5 => $this->flip(imagerotate($image, -90, 0), IMG_FLIP_HORIZONTAL),
            6 => imagerotate($image, -90, 0),
            7 => $this->flip(imagerotate($image, 90, 0), IMG_FLIP_HORIZONTAL),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated === false ? $image : $rotated;
    }

    private function flip(\GdImage|false $image, int $mode): \GdImage|false
    {
        if ($image !== false) {
            imageflip($image, $mode);
        }

        return $image;
    }

    private function memoryLimitBytes(): int
    {
        $limit = trim((string) ini_get('memory_limit'));

        if ($limit === '' || $limit === '-1') {
            return 0;
        }

        $value = (int) $limit;

        return match (strtolower(substr($limit, -1))) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
