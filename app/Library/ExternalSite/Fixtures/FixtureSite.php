<?php

namespace App\Library\ExternalSite\Fixtures;

/**
 * The fake "external website" used by tests and browser acceptance (driver=fake;
 * refused in production). A small kids-ceramics-studio site on a reserved
 * example domain, deliberately flawed in the ways the audit reports:
 *
 *   /            good: title, description, one h1, canonical, sharing preview, structured data
 *   /classes     same title as home, no description, links to a broken page, an image without alt text
 *   /about       no main heading, no canonical, no sharing preview
 *   /birthday    marked noindex, two main headings
 *   /contact     a very long title
 *   /old-page    404 (linked from /classes)
 *   /home        301 redirect to /
 *   /private/*   disallowed by robots.txt: must never be fetched
 *   /extra       listed only in sitemap.xml
 *
 * No real network is ever involved: the fixture transport answers from this map.
 */
final class FixtureSite
{
    public const HOST = 'studio-fixture.example';

    public const BASE = 'https://studio-fixture.example';

    public const PUBLIC_IP = '93.184.216.34';

    /**
     * @return array<string, array{status: int, headers: array<string, string>, body: string}>
     */
    public static function responses(): array
    {
        $html = fn (string $body): array => ['status' => 200, 'headers' => ['content-type' => 'text/html; charset=utf-8'], 'body' => $body];

        $page = static fn (string $head, string $body): string => '<!doctype html><html lang="en"><head><meta charset="utf-8">'.$head.'</head><body>'.$body.'</body></html>';

        $goodHead = '<title>Clay Kids Studio | Ceramics classes for children</title>'
            .'<meta name="description" content="Weekly ceramics classes for children aged 5 to 12, birthday workshops and holiday camps in a friendly local studio.">'
            .'<link rel="canonical" href="'.self::BASE.'/">'
            .'<meta property="og:title" content="Clay Kids Studio">'
            .'<script type="application/ld+json">{"@context":"https://schema.org","@type":"LocalBusiness","name":"Clay Kids Studio"}</script>';

        return [
            self::BASE.'/robots.txt' => ['status' => 200, 'headers' => ['content-type' => 'text/plain'], 'body' => "User-agent: *\nDisallow: /private\nSitemap: ".self::BASE."/sitemap.xml\n"],
            self::BASE.'/sitemap.xml' => ['status' => 200, 'headers' => ['content-type' => 'application/xml'], 'body' => '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<url><loc>'.self::BASE.'/</loc></url><url><loc>'.self::BASE.'/classes</loc></url><url><loc>'.self::BASE.'/extra</loc></url>'
                .'<url><loc>https://other-domain.example/not-ours</loc></url></urlset>'],

            self::BASE.'/' => $html($page($goodHead,
                '<h1>Ceramics classes for kids</h1><p>Welcome to our studio where children learn to make pottery.</p>'
                .'<nav><a href="/classes">Classes</a> <a href="/about">About</a> <a href="/birthday">Birthdays</a> <a href="/contact">Contact</a> <a href="/home">Home</a> <a href="/private/secret">Private</a> <a href="https://facebook.com/clay-kids">Facebook</a> <a href="/brochure.pdf">Brochure</a></nav>')),
            self::BASE.'/classes' => $html($page('<title>Clay Kids Studio | Ceramics classes for children</title><link rel="canonical" href="'.self::BASE.'/classes"><meta property="og:title" content="Classes">',
                '<h1>Our classes</h1><p>Weekly classes for different ages.</p><img src="/img/class.jpg"><a href="/old-page">See the old schedule</a> <a href="/contact">Contact us</a>')),
            self::BASE.'/about' => $html($page('<title>About the studio</title><meta name="description" content="Meet the people behind the studio and learn how we teach ceramics to young children in small groups.">',
                '<h2>Our story</h2><p>We started in a small room with one kiln.</p><a href="/">Home</a>')),
            self::BASE.'/birthday' => $html($page('<title>Birthday workshops</title><meta name="description" content="Celebrate with a ceramics birthday workshop for up to ten children, including clay, glazing and firing.">'
                .'<meta name="robots" content="noindex, follow"><link rel="canonical" href="'.self::BASE.'/birthday"><meta property="og:title" content="Birthdays">',
                '<h1>Birthday workshops</h1><h1>Book a party</h1><p>Parties for up to ten children.</p>')),
            self::BASE.'/contact' => $html($page('<title>Contact Clay Kids Studio about ceramics classes for children, birthday workshops and holiday camps in our local studio</title>'
                .'<meta name="description" content="Find the studio address, opening hours and how to book a trial class for your child.">'
                .'<link rel="canonical" href="'.self::BASE.'/contact"><meta property="og:title" content="Contact">',
                '<h1>Contact us</h1><p>Visit us at the studio.</p>')),
            self::BASE.'/extra' => $html($page('<title>Holiday workshops</title><meta name="description" content="School holiday ceramics workshops for children, with a different project every day of the week.">'
                .'<link rel="canonical" href="'.self::BASE.'/extra"><meta property="og:title" content="Holidays">', '<h1>Holiday workshops</h1><p>Fun all holiday.</p>')),
            self::BASE.'/home' => ['status' => 301, 'headers' => ['location' => self::BASE.'/'], 'body' => ''],
            self::BASE.'/old-page' => ['status' => 404, 'headers' => ['content-type' => 'text/html'], 'body' => '<html><body>Not found</body></html>'],
            self::BASE.'/private/secret' => $html('<html><body>Should never be fetched</body></html>'),
            self::BASE.'/brochure.pdf' => ['status' => 200, 'headers' => ['content-type' => 'application/pdf'], 'body' => '%PDF-1.4 fake'],
        ];
    }

    /** @return array<string, list<string>> host => public addresses */
    public static function hosts(): array
    {
        return [self::HOST => [self::PUBLIC_IP], 'www.'.self::HOST => [self::PUBLIC_IP]];
    }
}
