<?php

namespace App\Enums\GoogleAds;

/**
 * Contract §10 — which public surface the converting visitor entered through.
 */
enum LeadAttributionEntrySurface: string
{
    case PublicForm = 'public_form';
    case WebsiteForm = 'website_form';
    case Booking = 'booking';
}
