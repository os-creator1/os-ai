<?php

namespace App\Library\Branding;

use Illuminate\Http\Request;

/**
 * Agency V1 completion — the signed-in chrome's view of a managing Agency's
 * white-label brand: plain, already-normalized values for Blade, or null when
 * the platform/default identity applies.
 *
 * Authorization lives in ClientWorkspaceBrandResolver (persisted relationship,
 * enabled row, Agency eligibility, `white_label` entitlement). Normalization
 * lives in AuthBrandPresenter::forAgencyBrand() — the login screen's own
 * rules — so a Blade view never sees a raw stored value: the name is plain
 * text, the logo is an existing file under images/branding/, the accent is a
 * validated #rrggbb, and the support address passed FILTER_VALIDATE_EMAIL.
 *
 * Components call current() once per render; the expensive part is memoized
 * on the Request by the resolver.
 */
class ClientChromeBrand
{
    public function __construct(
        private readonly ClientWorkspaceBrandResolver $resolver,
        private readonly AuthBrandPresenter $presenter,
    ) {
    }

    /**
     * @return array{name: string, mark: string, logo: ?string, accent: ?string, accentContrast: ?string, tagline: ?string, support: ?string}|null
     */
    public function current(Request $request): ?array
    {
        $agency = $this->resolver->forRequest($request);

        if ($agency === null) {
            return null;
        }

        $brand = $this->presenter->forAgencyBrand($agency);
        $accent = $brand->tokens['--auth-brand-accent'] ?? null;

        $support = trim((string) $agency->supportEmail);

        return [
            'name' => $brand->displayName,
            'mark' => $brand->mark,
            'logo' => $brand->logoSrc,
            'accent' => $accent,
            'accentContrast' => $accent !== null ? self::contrastFor($accent) : null,
            'tagline' => $brand->tagline,
            'support' => $support !== '' && filter_var($support, FILTER_VALIDATE_EMAIL) !== false ? $support : null,
        ];
    }

    /**
     * Readable text on an arbitrary accent: dark on light colours, white on
     * dark ones (WCAG relative luminance, threshold at the contrast midpoint).
     */
    public static function contrastFor(string $hex): string
    {
        $channels = array_map(
            static function (string $pair): float {
                $value = hexdec($pair) / 255;

                return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            },
            str_split(ltrim($hex, '#'), 2),
        );

        $luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

        return $luminance > 0.179 ? '#111827' : '#ffffff';
    }
}
