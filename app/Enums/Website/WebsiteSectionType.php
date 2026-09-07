<?php

namespace App\Enums\Website;

/**
 * Website Generation + Hosting Slice A contract §7 — the bounded, code-
 * backed Website Component Library. This enum is the ONLY source of
 * truth for what a section's "type" may be; no stored class/Blade/JS
 * name ever exists in the database. An unknown value fails validation
 * outright (contract §16/§19) — there is no dynamic-include fallback.
 */
enum WebsiteSectionType: string
{
    case Hero = 'hero';
    case Text = 'text';
    case ImageText = 'image_text';
    case Services = 'services';
    case Testimonials = 'testimonials';
    case Faq = 'faq';
    case Cta = 'cta';
    case ContactDetails = 'contact_details';
}
