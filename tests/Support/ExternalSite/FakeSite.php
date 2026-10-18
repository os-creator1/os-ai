<?php

namespace Tests\Support\ExternalSite;

use App\Library\ExternalSite\Fixtures\FixtureExternalSiteTransport;
use App\Library\ExternalSite\Fixtures\FixtureHostResolver;

/**
 * A controllable external website for the audit tests: pages, redirects, errors,
 * firewall refusals, robots.txt and sitemaps, served from memory through the
 * FixtureExternalSiteTransport (no network, ever).
 *
 * `healthy()` builds a small local-business site that has NOTHING wrong with it, so
 * a test makes exactly one thing worse and asserts exactly what the audit says
 * about that one thing.
 */
final class FakeSite
{
    public const IP = '93.184.216.34';

    public readonly string $base;

    public function __construct(
        private readonly FixtureExternalSiteTransport $transport,
        FixtureHostResolver $resolver,
        public readonly string $host = 'bizfixture.example',
    ) {
        $resolver->set($host, [self::IP])->set('www.'.$host, [self::IP]);
        $this->base = 'https://'.$host;
    }

    public function url(string $path = '/'): string
    {
        return $this->base.$path;
    }

    /**
     * One HTML page. Options (all optional): title, description, h1 (string|list<string>|null for none), body (extra HTML),
     * links (list of [href, text]), noindex, canonical (null = itself, false = none, string = that address), og (bool),
     * viewport (bool), jsonld (string raw JSON), images (list of ['src', 'alt' => string|null]), head (raw), words (int).
     *
     * @param  array<string, mixed>  $o
     */
    public function page(string $path, array $o = []): self
    {
        $title = array_key_exists('title', $o) ? $o['title'] : 'Page '.trim($path, '/').' | Bizfixture Studio';
        $description = array_key_exists('description', $o) ? $o['description'] : 'This page describes '.trim($path, '/').' at Bizfixture Studio, with enough detail to be a useful summary in search results.';
        $h1 = array_key_exists('h1', $o) ? $o['h1'] : ($path === '/' ? 'Welcome to Bizfixture Studio' : ucfirst(trim($path, '/')).' at Bizfixture Studio');
        $canonical = array_key_exists('canonical', $o) ? $o['canonical'] : $this->url($path);
        $head = '<meta charset="utf-8">';

        if ($title !== null) {
            $head .= '<title>'.htmlspecialchars((string) $title).'</title>';
        }

        if ($description !== null) {
            $head .= '<meta name="description" content="'.htmlspecialchars((string) $description).'">';
        }

        if ($o['viewport'] ?? true) {
            $head .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
        }

        if ($canonical !== false) {
            foreach ((array) $canonical as $c) {
                $head .= '<link rel="canonical" href="'.htmlspecialchars((string) $c).'">';
            }
        }

        if ($o['og'] ?? true) {
            $head .= '<meta property="og:title" content="x"><meta property="og:image" content="'.$this->url('/img/share.jpg').'">';
        }

        if (! empty($o['noindex'])) {
            $head .= '<meta name="robots" content="noindex, follow">';
        }

        if (! empty($o['jsonld'])) {
            $head .= '<script type="application/ld+json">'.$o['jsonld'].'</script>';
        }

        $head .= $o['head'] ?? '';

        $body = '<nav>';
        foreach ($o['links'] ?? [] as $link) {
            $body .= '<a href="'.htmlspecialchars((string) $link[0]).'">'.htmlspecialchars((string) ($link[1] ?? '')).'</a> ';
        }
        $body .= '</nav>';

        foreach ((array) $h1 as $heading) {
            $body .= '<h1>'.htmlspecialchars((string) $heading).'</h1>';
        }

        $body .= '<p>'.trim(str_repeat('We are a friendly local team and we love helping our neighbours with every project. ', (int) (($o['words'] ?? 220) / 15))).'</p>';

        foreach ($o['images'] ?? [] as $image) {
            $body .= '<img src="'.htmlspecialchars((string) $image['src']).'"'.(array_key_exists('alt', $image) && $image['alt'] !== null ? ' alt="'.htmlspecialchars((string) $image['alt']).'"' : '').'>';
        }

        $body .= $o['body'] ?? '';

        $this->raw($path, 200, ['content-type' => 'text/html; charset=utf-8'], '<!doctype html><html lang="en"><head>'.$head.'</head><body>'.$body.'</body></html>');

        return $this;
    }

    /** @param array<string, string> $headers */
    public function raw(string $path, int $status, array $headers = [], string $body = ''): self
    {
        $this->transport->set($this->url($path), ['status' => $status, 'headers' => $headers, 'body' => $body]);

        return $this;
    }

    public function redirect(string $from, string $to, int $status = 301): self
    {
        return $this->raw($from, $status, ['location' => $to]);
    }

    public function missing(string $path, int $status = 404): self
    {
        return $this->raw($path, $status, ['content-type' => 'text/html'], '<html><head><title>Not found</title></head><body>Not found</body></html>');
    }

    /** A firewall / bot-protection refusal (what a CDN answers an automated visitor). */
    public function blocked(string $path, int $status = 403, bool $cloudflare = true): self
    {
        return $this->raw($path, $status, ['content-type' => 'text/html', 'server' => $cloudflare ? 'cloudflare' : 'nginx'] + ($cloudflare && $status === 403 ? ['cf-mitigated' => 'challenge'] : []), $cloudflare ? '<html><head><title>Just a moment...</title></head><body>Checking your browser before accessing the site. cf-chl-bypass</body></html>' : 'Forbidden');
    }

    public function robots(string $body): self
    {
        return $this->raw('/robots.txt', 200, ['content-type' => 'text/plain'], $body);
    }

    /** @param list<string> $paths */
    public function sitemap(array $paths, string $at = '/sitemap.xml'): self
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($paths as $path) {
            $xml .= '<url><loc>'.(str_starts_with($path, 'http') ? $path : $this->url($path)).'</loc></url>';
        }

        return $this->raw($at, 200, ['content-type' => 'application/xml'], $xml.'</urlset>');
    }

    /**
     * A small local-business website with NOTHING wrong with it: home, about, services, contact; titles and descriptions
     * unique and well sized; one H1 each; canonical, sharing tags, viewport; images with alt text; LocalBusiness data and a
     * phone link; a sitemap listing the four pages; every page linked from the home page and the home page from every page.
     */
    public function healthy(): self
    {
        $nav = [['/', 'Home'], ['/about', 'About us'], ['/services', 'Our services'], ['/contact', 'Contact us']];
        $schema = json_encode(['@context' => 'https://schema.org', '@type' => 'LocalBusiness', 'name' => 'Bizfixture Studio', 'address' => ['@type' => 'PostalAddress', 'streetAddress' => '1 Main St', 'addressLocality' => 'Austin']]);

        $this->robots("User-agent: *\nDisallow: /wp-admin\nSitemap: ".$this->url('/sitemap.xml')."\n");
        $this->sitemap(['/', '/about', '/services', '/contact']);

        $this->page('/', ['title' => 'Bizfixture Studio | Photography in Austin', 'description' => 'Friendly local photography studio in Austin offering portraits, events and family sessions with prints delivered fast.', 'links' => $nav, 'jsonld' => $schema,
            'body' => '<p>Call us on <a href="tel:+15125550142">(512) 555-0142</a> in Austin.</p>', 'images' => [['src' => '/img/hero.jpg', 'alt' => 'Our studio in Austin']]]);
        $this->page('/about', ['title' => 'About Bizfixture Studio | Our Austin team', 'description' => 'Meet the photographers behind Bizfixture Studio, a small Austin team focused on relaxed, natural portraits and events.', 'links' => $nav]);
        $this->page('/services', ['title' => 'Photography services and pricing | Bizfixture', 'description' => 'Portrait, family and event photography packages with clear pricing, delivery times and what is included in each session.', 'links' => $nav]);
        $this->page('/contact', ['title' => 'Contact Bizfixture Studio | Book a session', 'description' => 'Call, email or use the form to book a photography session with Bizfixture Studio in Austin; we reply within one working day.', 'links' => $nav, 'body' => '<p>Phone <a href="tel:5125550142">512-555-0142</a>.</p>']);

        foreach (['/img/hero.jpg', '/img/share.jpg'] as $image) {
            $this->raw($image, 200, ['content-type' => 'image/jpeg', 'content-length' => '90000'], 'JPEGDATA');
        }

        return $this;
    }
}
