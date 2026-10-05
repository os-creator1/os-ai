<?php

namespace Tests\Support\WebsiteAcceptance;

use Illuminate\Http\UploadedFile;

/**
 * Website V1 full-site acceptance — the ONE deterministic, realistic
 * fixture Business. Every value below is a FIXTURE (a made-up but plausible
 * Chicago photo booth company; the phone is a reserved 555 number, the
 * email a reserved example domain, the testimonials are invented sample
 * quotes). Nothing here is lorem ipsum: the acceptance audit looks for
 * placeholder copy and must find none in what the pipeline builds from this.
 */
final class PhotoBoothFixture
{
    public const NAME = 'Jazmin Photo Booth Co.';
    public const PHONE_DIGITS = '3125550147';
    public const PHONE_DISPLAY = '(312) 555-0147';
    public const EMAIL = 'hello@jazminphotobooth.example';
    public const DOMAIN = 'jazminphotobooth.test';
    public const CITY = 'Chicago';
    public const REGION = 'IL';

    /** Twelve nearby service areas, the owner's most important first. */
    public const AREAS = [
        'Chicago', 'Naperville', 'Evanston', 'Oak Park', 'Schaumburg', 'Aurora',
        'Skokie', 'Arlington Heights', 'Orland Park', 'Wheaton', 'Elgin', 'Oak Brook',
    ];

    /** The business "about" notes — also where the differentiators are stated, in the owner's words. */
    public const ABOUT = 'Jazmin Photo Booth Co. is a Chicago team that brings modern photo booths to weddings, corporate events, birthdays, graduations and brand activations. Every booking includes a friendly attendant, professional lighting, unlimited digital captures, instant sharing by text, email or QR code, custom photo templates and a private online event gallery.';

    /** @return array<int, array{name: string, description: string}> */
    public static function booths(): array
    {
        return [
            ['name' => 'Digital Photo Booth', 'description' => 'A sleek touchscreen booth with instant sharing, custom photo templates and professional lighting for guests of every age.'],
            ['name' => 'Glam Booth', 'description' => 'A beauty-lit booth with a soft ring light and polished studio look, popular for weddings, galas and milestone birthdays.'],
            ['name' => '360 Photo Booth', 'description' => 'A slow-motion video platform that captures guests from every angle, with branded overlays and instant sharing.'],
            ['name' => 'Roaming Photo Booth', 'description' => 'A mobile photographer with a pocket-size studio who moves through the room, ideal for large or tightly spaced venues.'],
        ];
    }

    /** @return array<int, array{name: string}> */
    public static function eventTypes(): array
    {
        return [['name' => 'Weddings'], ['name' => 'Corporate Events'], ['name' => 'Birthdays'], ['name' => 'Graduations'], ['name' => 'Brand Activations']];
    }

    /** @return array<int, array<string, mixed>> */
    public static function packages(): array
    {
        return [
            [
                'name' => 'Essential', 'price' => '699.00', 'currency_code' => 'USD', 'featured' => '0',
                'description' => 'Everything you need for a smaller celebration.',
                'features' => ['2 hours of booth time', 'Digital Photo Booth', 'Unlimited digital captures', 'Instant sharing by text, email or QR code', 'Attendant included'],
            ],
            [
                'name' => 'Signature', 'price' => '949.00', 'currency_code' => 'USD', 'featured' => '1',
                'description' => 'Our most popular package for weddings and larger events.',
                'features' => ['3 hours of booth time', 'Choice of Digital, Glam or 360 booth', 'Custom photo template design', 'Professional lighting', 'Private online event gallery', 'Attendant included'],
            ],
            [
                'name' => 'Luxe', 'price' => '1299.00', 'currency_code' => 'USD', 'featured' => '0',
                'description' => 'The full experience for galas, launches and brand activations.',
                'features' => ['4 hours of booth time', 'Two booths of your choice', 'Branded overlays and custom backdrop', 'Roaming photographer for one hour', 'Private online event gallery', 'Two attendants included'],
            ],
        ];
    }

    /** @return array<int, array{question: string, answer: string}> */
    public static function faqs(): array
    {
        return [
            ['question' => 'How far in advance should we book?', 'answer' => 'Popular Saturdays in spring and fall fill several months ahead, so we suggest reserving as soon as your date and venue are confirmed.'],
            ['question' => 'Is an attendant included?', 'answer' => 'Yes. Every package includes a friendly attendant who sets up, runs the booth and keeps the line moving.'],
            ['question' => 'How do guests get their photos?', 'answer' => 'Guests can share instantly by text, email or QR code, and every event gets a private online gallery afterward.'],
            ['question' => 'Can you match our event theme?', 'answer' => 'Yes. We design a custom photo template for your event and can add branded overlays on the Luxe package.'],
            ['question' => 'How much space does a booth need?', 'answer' => 'Most booths need about a ten by ten foot area and a standard power outlet. We confirm the layout with your venue.'],
            ['question' => 'Do you serve the suburbs?', 'answer' => 'Yes. We serve Chicago and the surrounding suburbs, including Naperville, Evanston, Oak Park and Schaumburg.'],
        ];
    }

    /** @return array<int, array{quote: string, author_name: string, author_title: string}> */
    public static function testimonials(): array
    {
        return [
            ['quote' => 'The Glam Booth was the highlight of our reception, and the attendant kept everyone laughing all night.', 'author_name' => 'Alexis M.', 'author_title' => 'Wedding client'],
            ['quote' => 'Our team loved the 360 Photo Booth at the launch party. Setup was quick and the clips were ready to share instantly.', 'author_name' => 'Daniel R.', 'author_title' => 'Corporate event client'],
            ['quote' => 'Easy to book, right on time and a great fit for my daughter\'s graduation party.', 'author_name' => 'Priya K.', 'author_title' => 'Graduation client'],
        ];
    }

    /**
     * A real, noisy, genuinely large JPEG so the responsive variants are
     * exercised (the original is far larger than any derivative).
     */
    public static function jpeg(int $width, int $height, string $name, int $seed): UploadedFile
    {
        $im = imagecreatetruecolor($width, $height);

        // A vertical dusk gradient, then seeded shapes: deterministic, photo-like weight.
        for ($y = 0; $y < $height; $y += 4) {
            $shade = (int) (40 + 120 * ($y / $height));
            imagefilledrectangle($im, 0, $y, $width, $y + 4, imagecolorallocate($im, $shade, 60 + intdiv($shade, 3), 140 - intdiv($shade, 2)));
        }

        mt_srand($seed);
        for ($i = 0; $i < 220; $i++) {
            $color = imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
            imagefilledellipse($im, mt_rand(0, $width), mt_rand(0, $height), mt_rand(20, max(40, intdiv($width, 4))), mt_rand(20, max(40, intdiv($height, 4))), $color);
        }

        $path = tempnam(sys_get_temp_dir(), 'accept-');
        imagejpeg($im, $path, 90);
        imagedestroy($im);

        return new UploadedFile($path, $name . '.jpg', 'image/jpeg', null, true);
    }

    /** A transparent-background logo, wide like a real wordmark. */
    public static function logo(): UploadedFile
    {
        $im = imagecreatetruecolor(1200, 360);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagealphablending($im, true);
        imagefilledellipse($im, 180, 180, 260, 260, imagecolorallocate($im, 201, 162, 74));
        imagefilledrectangle($im, 340, 120, 1100, 240, imagecolorallocate($im, 26, 31, 58));

        $path = tempnam(sys_get_temp_dir(), 'accept-');
        imagepng($im, $path, 6);
        imagedestroy($im);

        return new UploadedFile($path, 'jazmin-logo.png', 'image/png', null, true);
    }
}
