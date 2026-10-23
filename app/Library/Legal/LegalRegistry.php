<?php

namespace App\Library\Legal;

use Illuminate\Support\Facades\Route;

/**
 * The single reader of the legal source files. Release state is computed here
 * (never trusted from the request): see config/legal.php for the gate.
 */
final class LegalRegistry
{
    /** @var array<string, LegalDocument> */
    private array $cache = [];

    public function __construct(private readonly LegalRenderer $renderer) {}

    /** @return array<string, LegalDocument> in Legal Center order */
    public function all(): array
    {
        $out = [];
        foreach (array_keys((array) config('legal.documents')) as $slug) {
            if ($doc = $this->find($slug)) {
                $out[$slug] = $doc;
            }
        }

        return $out;
    }

    public function find(string $slug): ?LegalDocument
    {
        if (isset($this->cache[$slug])) {
            return $this->cache[$slug];
        }

        $meta = config('legal.documents.'.$slug);
        $file = rtrim((string) config('legal.path'), '/').'/'.$slug.'.md';
        if (! is_array($meta) || ! is_file($file)) {
            return null;
        }

        return $this->cache[$slug] = $this->build($slug, $meta, (string) file_get_contents($file));
    }

    /** A previously released version, from resources/legal/archive/<slug>/<version>.md (read-only history). */
    public function archived(string $slug, string $version): ?LegalDocument
    {
        $meta = config('legal.documents.'.$slug);
        $file = rtrim((string) config('legal.path'), '/').'/archive/'.$slug.'/'.basename($version).'.md';
        if (! is_array($meta) || ! is_file($file)) {
            return null;
        }

        return $this->build($slug, ['version' => $version, 'status' => 'archived'] + $meta, (string) file_get_contents($file));
    }

    /** Public: approved and sealed. */
    public function published(string $slug): ?LegalDocument
    {
        $doc = $this->find($slug);

        return $doc && $doc->isPublished() ? $doc : null;
    }

    /** Servable on a page: published, or any document while staging preview is switched on. */
    public function visible(string $slug): ?LegalDocument
    {
        return $this->published($slug) ?? (config('legal.preview_drafts') ? $this->find($slug) : null);
    }

    /** @return array<string, LegalDocument> */
    public function visibleDocuments(): array
    {
        return array_filter($this->all(), fn (LegalDocument $d) => $this->visible($d->slug) !== null);
    }

    /**
     * The documents a customer must accept in a context, or null when ANY of
     * them is not published (then nothing is shown and nothing is recorded).
     *
     * @return array{accept: list<LegalDocument>, linked: list<LegalDocument>}|null
     */
    public function bundle(string $context): ?array
    {
        $cfg = config('legal.acceptance.'.$context);
        if (! is_array($cfg)) {
            return null;
        }

        $accept = [];
        foreach ($cfg['accept'] as $slug) {
            $doc = $this->published($slug);
            if ($doc === null) {
                return null;
            }
            $accept[] = $doc;
        }
        $linked = [];
        foreach ($cfg['linked'] ?? [] as $slug) {
            if ($doc = $this->published($slug)) {
                $linked[] = $doc;
            }
        }

        return ['accept' => $accept, 'linked' => $linked];
    }

    public function url(string $slug): string
    {
        // The privacy policy keeps the application's pre-existing named route (one URI, one registration).
        return $slug === 'privacy-policy' ? route('privacy-policy') : route('legal.'.$slug);
    }

    public function indexUrl(): string
    {
        return route('legal.index');
    }

    /** @return array{html: string, sections: list<array>, unresolved: list<string>} */
    public function render(LegalDocument $doc): array
    {
        return $this->renderer->render($doc->source, (array) config('legal.facts'));
    }

    private function build(string $slug, array $meta, string $source): LegalDocument
    {
        $facts = (array) config('legal.facts');
        $version = (string) ($meta['version'] ?? '0');
        $hash = LegalDocument::hashOf($source);
        $ledger = $this->ledger();

        $unresolved = $this->renderer->unresolved($this->renderer->substitute(LegalDocument::canonical($source), $facts));

        return new LegalDocument(
            slug: $slug,
            title: (string) $meta['title'],
            short: (string) ($meta['short'] ?? $meta['title']),
            category: (string) ($meta['category'] ?? 'Legal'),
            primary: (bool) ($meta['primary'] ?? false),
            version: $version,
            status: (string) ($meta['status'] ?? 'draft'),
            effectiveDate: $meta['effective_date'] ?? null,
            updatedAt: $meta['updated_at'] ?? null,
            approvedBy: $meta['approved_by'] ?? null,
            approvedAt: $meta['approved_at'] ?? null,
            description: (string) ($meta['description'] ?? ''),
            summary: array_values((array) ($meta['summary'] ?? [])),
            releaseNote: $meta['release_note'] ?? null,
            source: $source,
            hash: $hash,
            unresolved: array_values(array_unique($unresolved)),
            sealed: ($ledger[$slug.'@'.$version] ?? null) === $hash,
        );
    }

    /** @return array<string, string> "slug@version" => sha256 */
    public function ledger(): array
    {
        $file = rtrim((string) config('legal.path'), '/').'/hashes.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];

        return is_array($data) ? $data : [];
    }

    public function routeExists(string $slug): bool
    {
        return Route::has($slug === 'privacy-policy' ? 'privacy-policy' : 'legal.'.$slug);
    }
}
