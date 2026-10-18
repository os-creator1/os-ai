<?php

namespace App\Enums\Business;

enum BusinessIndustry: string
{
    case PhotoBoothService = 'photo_booth_service';
    case EventServices = 'event_services';
    case Photographer = 'photographer';
    case WeddingVendor = 'wedding_vendor';
    case HomeServices = 'home_services';
    case ProfessionalServices = 'professional_services';
    case Other = 'other';

    /** Owner-facing niche name (e.g. "Recommended for Photo booth"). */
    public function label(): string
    {
        return match ($this) {
            self::PhotoBoothService => 'Photo booth',
            self::EventServices => 'Event services',
            self::Photographer => 'Photography',
            self::WeddingVendor => 'Wedding',
            self::HomeServices => 'Home services',
            self::ProfessionalServices => 'Professional services',
            self::Other => 'Other',
        };
    }
}
