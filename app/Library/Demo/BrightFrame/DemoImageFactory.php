<?php

namespace App\Library\Demo\BrightFrame;

use Illuminate\Http\UploadedFile;

/**
 * Deterministic, text-free illustrations drawn with GD, used in place of
 * photography so the demo needs no stock images or external fetches. Same
 * seed, same bytes, so a re-run finds the assets it already stored.
 */
final class DemoImageFactory
{
    /** name => [top colour, bottom colour, accent, bokeh colour] */
    private const PALETTES = [
        'wedding' => [[247, 222, 226], [196, 150, 170], [255, 255, 255], [255, 236, 214]],
        'corporate' => [[16, 54, 82], [8, 110, 130], [120, 226, 220], [90, 190, 255]],
        'birthday' => [[255, 183, 77], [233, 30, 99], [255, 255, 255], [255, 241, 118]],
        'prom' => [[20, 18, 60], [96, 52, 148], [255, 215, 120], [214, 168, 255]],
        'glam' => [[44, 30, 24], [150, 108, 60], [255, 226, 160], [255, 200, 110]],
        'neon' => [[10, 12, 30], [0, 120, 160], [0, 255, 200], [255, 60, 200]],
        'garden' => [[196, 226, 190], [70, 130, 100], [255, 255, 255], [255, 244, 190]],
        'hero' => [[22, 28, 72], [124, 58, 237], [255, 214, 102], [255, 160, 220]],
    ];

    public static function names(): array
    {
        return array_keys(self::PALETTES);
    }

    public static function scene(string $palette, int $width, int $height, int $seed, string $filename): UploadedFile
    {
        [$top, $bottom, $accent, $bokeh] = self::PALETTES[$palette];
        $im = imagecreatetruecolor($width, $height);

        for ($y = 0; $y < $height; $y++) {
            $t = $y / max(1, $height - 1);
            imagefilledrectangle($im, 0, $y, $width, $y, imagecolorallocate(
                $im,
                (int) ($top[0] + ($bottom[0] - $top[0]) * $t),
                (int) ($top[1] + ($bottom[1] - $top[1]) * $t),
                (int) ($top[2] + ($bottom[2] - $top[2]) * $t),
            ));
        }

        // Soft bokeh lights.
        imagealphablending($im, true);
        mt_srand($seed);
        for ($i = 0; $i < 46; $i++) {
            $r = mt_rand((int) ($height * 0.02), (int) ($height * 0.09));
            $c = imagecolorallocatealpha($im, $bokeh[0], $bokeh[1], $bokeh[2], mt_rand(95, 120));
            imagefilledellipse($im, mt_rand(0, $width), mt_rand(0, (int) ($height * 0.75)), $r * 2, $r * 2, $c);
        }

        // A photo strip: three framed shots, each with two simple silhouettes.
        $stripW = (int) ($height * 0.34);
        $stripH = (int) ($height * 0.86);
        $x0 = (int) ($width * (0.5 + (($seed % 3) - 1) * 0.16)) - intdiv($stripW, 2);
        $y0 = (int) ($height * 0.07);
        imagefilledrectangle($im, $x0 + 8, $y0 + 10, $x0 + $stripW + 8, $y0 + $stripH + 10, imagecolorallocatealpha($im, 0, 0, 0, 100));
        imagefilledrectangle($im, $x0, $y0, $x0 + $stripW, $y0 + $stripH, imagecolorallocate($im, ...$accent));

        $pad = (int) ($stripW * 0.07);
        $frameH = (int) (($stripH - $pad * 4) / 3);
        for ($i = 0; $i < 3; $i++) {
            $fy = $y0 + $pad + $i * ($frameH + $pad);
            $mix = 0.25 + 0.2 * $i;
            $frame = imagecolorallocate($im,
                (int) ($top[0] * (1 - $mix) + $bottom[0] * $mix),
                (int) ($top[1] * (1 - $mix) + $bottom[1] * $mix),
                (int) ($top[2] * (1 - $mix) + $bottom[2] * $mix));
            imagefilledrectangle($im, $x0 + $pad, $fy, $x0 + $stripW - $pad, $fy + $frameH, $frame);

            imagesetclip($im, $x0 + $pad, $fy, $x0 + $stripW - $pad, $fy + $frameH);
            $skin = imagecolorallocate($im, 236 - $i * 14, 190 - $i * 12, 160 - $i * 10);
            $cloth = imagecolorallocate($im, ($bokeh[0] + $i * 40) % 256, ($bokeh[1] + $i * 70) % 256, ($bokeh[2] + $i * 25) % 256);
            foreach ([0.3, 0.7] as $k => $cx) {
                $px = $x0 + $pad + (int) (($stripW - 2 * $pad) * $cx);
                $head = (int) ($frameH * 0.19);
                $lean = ($k === 0 ? 1 : -1) * (int) ($frameH * 0.03) * ($i + 1);
                imagefilledellipse($im, $px + $lean, $fy + (int) ($frameH * 0.45), (int) ($head * 1.7), (int) ($head * 1.9), $skin);
                imagefilledellipse($im, $px, $fy + (int) ($frameH * 0.98), (int) ($head * 2.5), (int) ($frameH * 0.7), $cloth);
            }

            imagesetclip($im, 0, 0, $width - 1, $height - 1);
        }

        $path = tempnam(sys_get_temp_dir(), 'bf-');
        imagejpeg($im, $path, 86);
        imagedestroy($im);

        return new UploadedFile($path, $filename, 'image/jpeg', null, true);
    }

    /** A transparent wordmark-style logo: a lens circle and a bar, no text. */
    public static function logo(): UploadedFile
    {
        $im = imagecreatetruecolor(960, 280);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagealphablending($im, true);

        imagefilledellipse($im, 140, 140, 220, 220, imagecolorallocate($im, 124, 58, 237));
        imagefilledellipse($im, 140, 140, 130, 130, imagecolorallocate($im, 255, 255, 255));
        imagefilledellipse($im, 140, 140, 70, 70, imagecolorallocate($im, 255, 193, 7));
        imagefilledrectangle($im, 300, 96, 900, 128, imagecolorallocate($im, 22, 28, 72));
        imagefilledrectangle($im, 300, 156, 760, 184, imagecolorallocate($im, 124, 58, 237));

        $path = tempnam(sys_get_temp_dir(), 'bf-');
        imagepng($im, $path, 6);
        imagedestroy($im);

        return new UploadedFile($path, 'brightframe-logo.png', 'image/png', null, true);
    }
}
