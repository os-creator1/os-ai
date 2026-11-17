<?php

namespace App\Library\ExternalSite\Rebuild;

use App\Models\ExternalRebuildPlan;
use Illuminate\Support\Collection;

/**
 * The reviewable 301 redirect plan: for each old page, where the MotionGrove draft will answer for it. Pure: it only
 * compares the plan's old paths with the paths of the draft pages that actually exist, so a target is never invented.
 *
 * states:
 *   kept            the draft has a page at the very same path: nothing to redirect, the URL is preserved
 *   redirect        a different draft page clearly replaces it: a proposed 301
 *   needs_decision  no unambiguous draft page replaces it (or it is a legal/blog/other page): the owner decides
 *   conflict        the old path is already a DIFFERENT draft page's address, so it cannot also be redirected
 *   skipped         the owner left it out, or chose "no redirect"
 *   unsupported     the old path cannot be a redirect source (characters the redirect map does not allow)
 */
final class ExternalRebuildRedirectPlan
{
    public const AUTO = 'auto';

    public const NONE = 'none';

    public const HOME = 'home';

    /**
     * @param  Collection<int, \App\Models\WebsitePage>  $draftPages
     * @return list<array<string, mixed>>
     */
    public function build(ExternalRebuildPlan $plan, Collection $draftPages): array
    {
        $slugs = $draftPages->mapWithKeys(fn ($p) => [(bool) $p->is_home ? '' : (string) $p->slug => $p])->all();
        $decisions = (array) ($plan->decisions['pages'] ?? []);
        $rows = [];

        foreach ((array) $plan->pages as $page) {
            $decision = (array) ($decisions[$page['key']] ?? []);
            $include = (bool) ($decision['include'] ?? true);
            $choice = (string) ($decision['target'] ?? self::AUTO);
            $auto = $this->autoTarget($page, $draftPages);
            $target = match (true) {
                $choice === self::NONE => null,
                $choice === self::HOME => '',
                $choice !== self::AUTO && $choice !== '' => $choice,
                default => $auto,
            };

            $state = match (true) {
                ! $include || $choice === self::NONE => 'skipped',
                $page['path'] === '' => 'kept',
                ! $page['redirectable'] => 'unsupported',
                isset($slugs[$page['path']]) && ($target === null || $target === $page['path']) => 'kept',
                isset($slugs[$page['path']]) => 'conflict',
                $target === null => 'needs_decision',
                ! array_key_exists($target, $slugs) => 'needs_decision',
                default => 'redirect',
            };

            $rows[] = $page + ['state' => $state, 'target' => $state === 'redirect' ? $target : ($state === 'kept' ? $page['path'] : null), 'suggested' => $auto];
        }

        return $rows;
    }

    /**
     * The one unambiguous draft page that replaces this old page, or null. Never guesses between two candidates.
     *
     * @param  array<string, mixed>  $page
     * @param  Collection<int, \App\Models\WebsitePage>  $draftPages
     */
    public function autoTarget(array $page, Collection $draftPages): ?string
    {
        $inner = $draftPages->reject(fn ($p) => (bool) $p->is_home);
        $bySlug = fn (string ...$slugs) => $inner->first(fn ($p) => in_array((string) $p->slug, $slugs, true));

        $found = match ($page['role']) {
            'home' => $draftPages->first(fn ($p) => (bool) $p->is_home),
            'contact' => $bySlug('contact', 'photo-booth-contact'),
            'gallery' => $bySlug('gallery'),
            'backdrops' => $bySlug('backdrops'),
            'services' => $bySlug('services', 'packages'),
            'corporate', 'wedding', 'party' => $this->only($inner->filter(fn ($p) => ! str_starts_with((string) $p->slug, 'serving-') && $this->mentions($p, (string) $page['event']))),
            'area' => $this->only($inner->filter(fn ($p) => str_starts_with((string) $p->slug, 'serving-') && $this->mentions($p, (string) $page['area']))),
            default => null,
        };

        return $found === null ? null : ((bool) $found->is_home ? '' : (string) $found->slug);
    }

    private function only(Collection $candidates): mixed
    {
        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    private function mentions($draftPage, string $needle): bool
    {
        $needle = strtolower(str_replace(' ', '-', trim($needle)));

        return $needle !== '' && (str_contains((string) $draftPage->slug, $needle) || str_contains(strtolower(str_replace(' ', '-', (string) $draftPage->title)), $needle));
    }
}
