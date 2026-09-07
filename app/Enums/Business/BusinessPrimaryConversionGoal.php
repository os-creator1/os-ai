<?php

namespace App\Enums\Business;

/**
 * Website Guided Generation contract §4.2 -- `business_knowledge_
 * profiles.primary_conversion_goal` is enum-backed to exactly these
 * five values.
 */
enum BusinessPrimaryConversionGoal: string
{
    case Call = 'call';
    case QuoteRequest = 'quote_request';
    case ConsultationBooking = 'consultation_booking';
    case CalendarBooking = 'calendar_booking';
    case ExternalBookingLink = 'external_booking_link';
}
