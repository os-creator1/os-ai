<?php

namespace App\Enums\GoogleAds;

/**
 * Contract §10 — the conversion event a touch is recorded for.
 */
enum LeadAttributionSubjectType: string
{
    case FormSubmission = 'form_submission';
    case WebsiteFormSubmission = 'website_form_submission';
    case Appointment = 'appointment';
}
