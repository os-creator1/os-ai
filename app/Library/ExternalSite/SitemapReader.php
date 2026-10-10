<?php

namespace App\Library\ExternalSite;

/**
 * Reads a sitemap.xml body into URLs, defensively: a document type declaration
 * (the vector for entity-expansion attacks) is refused outright, the network is
 * never touched while parsing, and the number of URLs is capped. Returns the page
 * URLs of a `urlset`, or the child sitemap URLs of a `sitemapindex`.
 */
final class SitemapReader
{
    /**
     * @return array{kind: string, urls: list<string>} kind = urlset | sitemapindex | invalid
     */
    public static function read(string $xml, int $maxUrls): array
    {
        if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            return ['kind' => 'invalid', 'urls' => []];
        }

        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $doc instanceof \SimpleXMLElement) {
            return ['kind' => 'invalid', 'urls' => []];
        }

        $root = strtolower($doc->getName());
        $urls = [];

        if ($root === 'urlset') {
            foreach ($doc->children() as $url) {
                $loc = trim((string) ($url->loc ?? ''));

                if ($loc !== '' && count($urls) < $maxUrls) {
                    $urls[] = $loc;
                }
            }

            return ['kind' => 'urlset', 'urls' => $urls];
        }

        if ($root === 'sitemapindex') {
            foreach ($doc->children() as $sitemap) {
                $loc = trim((string) ($sitemap->loc ?? ''));

                if ($loc !== '' && count($urls) < $maxUrls) {
                    $urls[] = $loc;
                }
            }

            return ['kind' => 'sitemapindex', 'urls' => $urls];
        }

        return ['kind' => 'invalid', 'urls' => []];
    }
}
