<?php

namespace App\Library\Website\Seo;

use App\Models\Website;
use Illuminate\Validation\ValidationException;

/** Sets a Website's owner-defined old-address redirects. Takes effect with the next publish (frozen in the revision). */
final class WebsiteRedirectManager
{
    public const MAX = 50;

    /** @param array<string, string> $map old path => target page path ('' = home); each is a bare path, no host */
    public function set(Website $website, array $map): void
    {
        if (count($map) > self::MAX) {
            throw ValidationException::withMessages(['redirects' => ['At most '.self::MAX.' redirects.']]);
        }

        $clean = [];
        foreach ($map as $old => $target) {
            $old = strtolower(trim((string) $old, '/'));
            $target = strtolower(trim((string) $target, '/'));
            foreach ([$old, $target] as $path) {
                if ($path !== '' && preg_match('#^[a-z0-9][a-z0-9\-_/]{0,118}$#', $path) !== 1) {
                    throw ValidationException::withMessages(['redirects' => ["\"{$path}\" is not a valid site path (letters, digits, - _ / only; no host or query)."]]);
                }
            }
            if ($old === '') {
                throw ValidationException::withMessages(['redirects' => ['The home page cannot be redirected.']]);
            }
            $clean[$old] = $target;
        }

        $website->forceFill(['custom_redirects' => $clean === [] ? null : $clean])->save();
    }
}
