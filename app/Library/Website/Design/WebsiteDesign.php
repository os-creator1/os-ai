<?php

namespace App\Library\Website\Design;

use Illuminate\Support\HtmlString;

/**
 * Website V1 final — one canonical website DESIGN: the structural identity a
 * template owns. A design decides the header layout, how the hero is laid
 * out, how services/packages/testimonials/FAQ/gallery are presented, the
 * footer, the typography family, the band rhythm and the order of a home
 * page's sections. Owners customise only content and the safe tokens
 * (logo, one brand colour, images, copy, CTA, contact) — never layout,
 * fonts or a free-form palette.
 *
 * Designs are original. Each one translates the *visual grammar* of one of
 * four reference sites (header structure, section rhythm, density, type
 * contrast) — never their copy, photographs, logos, reviews, markup or CSS.
 * The CSS for all four lives in public/css/website-design.css, scoped to
 * `.wd-<key>`; a design never loads a third-party font.
 */
final class WebsiteDesign
{
    /**
     * @param  array<string, string>  $variants  layout variant per surface
     * @param  array<string, string>  $tones  band tone per section type (light|dark|accent|tint)
     * @param  array<int, string>  $homeOrder  preferred home-page section order
     * @param  array{0: string, 1: string}  $swatch  preview/picker colours (background, accent)
     * @param  array<string, string>  $roleTones  band tone per semantic services role (events|process|advantages|areas), when a design tones them apart
     */
    public function __construct(
        public readonly string $key,
        public readonly string $templateKey,
        public readonly int $number,
        public readonly string $label,
        public readonly string $tagline,
        public readonly string $bestFor,
        public readonly array $variants,
        public readonly array $tones,
        public readonly array $homeOrder,
        public readonly array $swatch,
        public readonly bool $announcementBar,
        public readonly array $roleTones = [],
    ) {}

    public function variant(string $surface): string
    {
        return $this->variants[$surface] ?? 'default';
    }

    public function toneFor(string $sectionType, ?string $role = null): string
    {
        if ($role !== null && isset($this->roleTones[$role])) {
            return $this->roleTones[$role];
        }

        // A trust strip is a slim band under the hero: the template's tint tone sets it apart from both the hero and the sections below.
        return $this->tones[$sectionType] ?? ($sectionType === 'trust_strip' ? 'tint' : 'light');
    }

    /**
     * The template owns the order of a HOME page's sections: stable sort by
     * the design's own order; any type it does not list keeps its relative
     * place after the listed ones. Inner pages keep the order they were
     * generated in (a service page reads top to bottom as written).
     *
     * @param  array<int, array{type?: string, data?: array}>  $sections
     * @return array<int, array{type?: string, data?: array}>
     */
    public function orderHomeSections(array $sections): array
    {
        // Reference templates own a fixed, per-block sequence (including
        // interleaved services, images and testimonials). Sorting only by
        // section *type* would scramble that sequence and break fidelity.
        if ($this->variant('home_sequence') === 'authored') {
            $ordered = array_values($sections);
        } else {
            $rank = array_flip($this->homeOrder);
            $indexed = [];

            foreach (array_values($sections) as $position => $section) {
                $indexed[] = [$rank[$section['type'] ?? ''] ?? (count($rank) + 1), $position, $section];
            }

            usort($indexed, fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

            $ordered = array_map(fn (array $row) => $row[2], $indexed);
        }

        // The trust strip belongs directly under the hero, whatever order the template gives the other sections. (The event-category
        // ticker is a `trust_strip` too, but it is not the facts strip: it keeps the position its design gives it.)
        $stripAt = false;
        foreach ($ordered as $position => $candidate) {
            if (($candidate['type'] ?? null) === 'trust_strip' && ($candidate['data']['ref'] ?? null) !== 'marquee') {
                $stripAt = $position;

                break;
            }
        }

        if ($stripAt !== false) {
            $strip = array_splice($ordered, (int) $stripAt, 1)[0];
            $heroAt = array_search('hero', array_column($ordered, 'type'), true);
            array_splice($ordered, $heroAt === false ? (int) $stripAt : (int) $heroAt + 1, 0, [$strip]);
        }

        return $ordered;
    }

    /**
     * A heading with its last word set apart so the design can style it as
     * the accent word. Every part is escaped; nothing from the heading is
     * ever emitted raw.
     */
    public function accentHeading(string $heading): HtmlString
    {
        $heading = trim($heading);

        if ($this->variant('accent') === 'none') {
            return new HtmlString(e($heading));
        }

        if (! str_contains($heading, ' ')) {
            // A single word: the Kaleb reference sets it entirely in its script face; every other design leaves it plain.
            return new HtmlString(($this->variants['accent_titles'] ?? false) ? '<span class="wd-accent-word">' . e($heading) . '</span>' : e($heading));
        }

        $cut = (int) mb_strrpos($heading, ' ');

        return new HtmlString(e(mb_substr($heading, 0, $cut)) . ' <span class="wd-accent-word">' . e(mb_substr($heading, $cut + 1)) . '</span>');
    }
}
