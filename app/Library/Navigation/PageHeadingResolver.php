<?php

namespace App\Library\Navigation;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Decides whether the shared title bar (panels.breadcrumb) should print its own heading.
 *
 * Many customer pages render their own purpose-built heading (with subtitle, tabs and
 * actions). Printing the shell's heading as well shows the title twice. A page owns its
 * heading when its rendered content has a visible page-level heading — any <h1>, or an
 * <h2>–<h4> whose text equals the page title. Headings inside dialogs, templates and
 * hidden regions never count, so modal and card titles cannot suppress the shell heading.
 *
 * A page may also decide explicitly with `@section('page-heading', 'own')` (it renders its
 * own heading) or `@section('page-heading', 'shared')` (always keep the shell heading).
 * Pages with no local heading are unaffected and keep the shared one. Presentation only:
 * the browser <title>, breadcrumbs and View As banner are not touched by this.
 */
final class PageHeadingResolver
{
    public const OWN = 'own';

    public const SHARED = 'shared';

    /** Ancestors whose headings are not the page's heading. */
    private const IGNORED_ANCESTOR = "ancestor::*[
        self::template or self::dialog or self::script or self::style or self::noscript
        or @hidden or @aria-hidden='true' or @role='dialog' or @role='alertdialog'
        or contains(concat(' ', normalize-space(@class), ' '), ' modal ')
        or contains(concat(' ', normalize-space(@class), ' '), ' offcanvas ')
        or contains(concat(' ', normalize-space(@class), ' '), ' d-none ')
        or contains(concat(' ', normalize-space(@class), ' '), ' visually-hidden ')
        or contains(concat(' ', normalize-space(@class), ' '), ' sr-only ')
        or contains(concat(' ', normalize-space(@class), ' '), ' dropdown-menu ')
    ]";

    public static function ownsHeading(string $content, string $title, ?string $explicit = null): bool
    {
        $explicit = $explicit !== null ? strtolower(trim($explicit)) : null;

        if ($explicit === self::OWN) {
            return true;
        }

        if ($explicit === self::SHARED || ! preg_match('/<h[1-4][\s>]/i', $content)) {
            return false;
        }

        $wanted = self::normalise($title);

        // Cheap pre-pass: most pages have no <h1> and no heading equal to the title, and then
        // the (comparatively costly) DOM parse below is skipped. It can only ever say "no" early;
        // whether a candidate really is the page heading is still decided by the DOM pass.
        if (! self::hasCandidate($content, $wanted)) {
            return false;
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><body>' . $content . '</body>', LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $headings = (new DOMXPath($dom))->query('//h1[not(' . self::IGNORED_ANCESTOR . ')] | //h2[not(' . self::IGNORED_ANCESTOR . ')] | //h3[not(' . self::IGNORED_ANCESTOR . ')] | //h4[not(' . self::IGNORED_ANCESTOR . ')]');

        foreach ($headings ?: [] as $heading) {
            /** @var DOMElement $heading */
            if (self::hiddenInline($heading)) {
                continue;
            }

            if ($heading->tagName === 'h1') {
                return true;
            }

            if ($wanted !== '' && self::normalise($heading->textContent) === $wanted) {
                return true;
            }
        }

        return false;
    }

    private static function hasCandidate(string $content, string $wanted): bool
    {
        if (preg_match('/<h1[\s>]/i', $content) === 1) {
            return true;
        }

        if ($wanted === '' || preg_match_all('#<h[2-4](?:\s[^>]*)?>(.*?)</h[2-4]>#is', $content, $matches) < 1) {
            return false;
        }

        foreach ($matches[1] as $inner) {
            if (self::normalise($inner) === $wanted) {
                return true;
            }
        }

        return false;
    }

    private static function hiddenInline(DOMElement $el): bool
    {
        $class = ' ' . preg_replace('/\s+/', ' ', $el->getAttribute('class')) . ' ';

        return str_contains($class, ' visually-hidden ') || str_contains($class, ' sr-only ')
            || preg_match('/display\s*:\s*none/i', $el->getAttribute('style')) === 1;
    }

    private static function normalise(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? ''));
    }
}
