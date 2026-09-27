<?php

namespace App\Enums\Website;

/**
 * The bounded set of field types a WebsiteForm's `fields` JSON config may
 * use. This enum is the ONLY source of truth — an unknown value fails
 * validation outright, mirroring WebsiteSectionType's own discipline.
 */
enum WebsiteFormFieldType: string
{
    case Text = 'text';
    case Email = 'email';
    case Tel = 'tel';
    case Textarea = 'textarea';
    case Date = 'date';
}
