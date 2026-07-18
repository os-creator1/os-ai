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
}
