<?php

namespace App\Enums\Seo;

/**
 * Contract 18 §8.6 — how the person was asked. A RECORDED FACT the user
 * reports; SEO sends nothing on any channel.
 */
enum SeoReviewRequestChannel: string
{
    case Sms = 'sms';
    case Email = 'email';
    case InPerson = 'in_person';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Sms => 'Text message',
            self::Email => 'Email',
            self::InPerson => 'In person',
            self::Other => 'Other',
        };
    }
}
