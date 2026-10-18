<?php

namespace App\Library\Demo\BrightFrame;

/**
 * Every fact below is FICTIONAL. Phones use the reserved 555-01XX range,
 * emails use the reserved example.com domain, and nothing refers to a real
 * person, venue or business. The demo tenant is a made-up Charlotte photo
 * booth company.
 */
final class BrightFrameData
{
    public const WORKSPACE_NAME = 'BrightFrame Photo Booths (Sales Demo)';
    public const BUSINESS_NAME = 'BrightFrame Photo Booths';
    public const DEFAULT_LOGIN_EMAIL = 'demo@brightframe-photobooths.example';
    public const PHONE = '(704) 555-0142';
    public const PHONE_E164 = '+17045550142';
    public const EMAIL = 'hello@brightframe-photobooths.example';
    public const WEBSITE = 'https://brightframe-photobooths.example';
    public const CITY = 'Charlotte';
    public const REGION = 'NC';
    public const TIMEZONE = 'America/New_York';

    public const ABOUT = 'BrightFrame Photo Booths is a Charlotte team that brings modern photo booths to weddings, corporate events, birthdays, proms and brand activations across the Carolinas. Every booking includes a friendly attendant, studio lighting, unlimited digital captures, instant sharing by text, email or QR code, a custom photo template and a private online gallery.';

    /** @return array<int, array{name: string, price: string, description: string, primary?: bool}> */
    public static function services(): array
    {
        return [
            ['name' => 'Classic Digital Photo Booth', 'price' => '549.00', 'primary' => true, 'description' => 'A sleek touchscreen booth with instant sharing, custom photo templates and studio lighting for guests of every age.'],
            ['name' => 'Glam Mirror Booth', 'price' => '749.00', 'description' => 'A beauty-lit booth with a soft ring light and a polished studio look, popular for weddings, galas and milestone birthdays.'],
            ['name' => '360 Video Booth', 'price' => '849.00', 'description' => 'A slow-motion video platform that captures guests from every angle, with branded overlays and instant sharing.'],
            ['name' => 'Roaming Photographer', 'price' => '499.00', 'description' => 'A mobile photographer with a pocket studio who moves through the room, ideal for large or tightly spaced venues.'],
            ['name' => 'Branded Corporate Activation', 'price' => '1199.00', 'description' => 'Custom overlays, backdrops and lead capture for product launches, trade shows and company celebrations.'],
        ];
    }

    /** @return array<int, array{name: string, price_minor: int, featured: bool, description: string, features: array<int, string>}> */
    public static function packages(): array
    {
        return [
            ['name' => 'Essential', 'price_minor' => 69900, 'featured' => false, 'description' => 'Everything you need for a smaller celebration.', 'features' => ['2 hours of booth time', 'Classic Digital Photo Booth', 'Unlimited digital captures', 'Instant sharing by text, email or QR code', 'Attendant included']],
            ['name' => 'Signature', 'price_minor' => 94900, 'featured' => true, 'description' => 'Our most popular package for weddings and larger events.', 'features' => ['3 hours of booth time', 'Choice of Classic, Glam or 360 booth', 'Custom photo template design', 'Studio lighting', 'Private online event gallery', 'Attendant included']],
            ['name' => 'Luxe', 'price_minor' => 129900, 'featured' => false, 'description' => 'The full experience for galas, launches and brand activations.', 'features' => ['4 hours of booth time', 'Two booths of your choice', 'Branded overlays and custom backdrop', 'Roaming photographer for one hour', 'Private online event gallery', 'Two attendants included']],
        ];
    }

    /**
     * Contacts: [first, last, email, phone suffix, tag/source, note]. Phones are +1 704 555 01xx.
     *
     * @return array<int, array{first: string, last: string, email: string, phone: string, source: string}>
     */
    public static function contacts(): array
    {
        $people = [
            ['Maya', 'Thompson', 'wedding'], ['Jordan', 'Reyes', 'corporate'], ['Priya', 'Nair', 'wedding'], ['Marcus', 'Bennett', 'birthday'],
            ['Elena', 'Costa', 'wedding'], ['Daniel', 'Okafor', 'corporate'], ['Sophie', 'Lindqvist', 'prom'], ['Tyler', 'Brooks', 'birthday'],
            ['Hannah', 'Whitfield', 'wedding'], ['Andre', 'Dubois', 'corporate'], ['Camila', 'Herrera', 'quinceanera'], ['Noah', 'Fitzgerald', 'graduation'],
            ['Aisha', 'Rahman', 'wedding'], ['Peter', 'Kowalski', 'corporate'], ['Grace', 'Liu', 'birthday'], ['Owen', 'Matthews', 'wedding'],
            ['Isabella', 'Moretti', 'wedding'], ['Caleb', 'Sutton', 'graduation'], ['Natalie', 'Park', 'corporate'], ['Dominic', 'Alvarez', 'birthday'],
            ['Rachel', 'Goldberg', 'wedding'], ['Trevor', 'Haynes', 'corporate'], ['Zoe', 'Abernathy', 'prom'], ['Miguel', 'Santos', 'wedding'],
        ];

        $out = [];
        foreach ($people as $i => [$first, $last, $source]) {
            $out[] = [
                'first' => $first,
                'last' => $last,
                'email' => strtolower($first . '.' . $last) . '@example.com',
                'phone' => '1704555' . str_pad((string) (100 + $i), 4, '0', STR_PAD_LEFT),
                'source' => $source,
            ];
        }

        return $out;
    }
}
