<?php

namespace App\Enums\Messaging;

/**
 * Text messaging setup/number/compliance hub — the plain, customer-facing
 * lifecycle of a Business's carrier registration (10DLC brand+campaign, or
 * toll-free verification). Never "10DLC" itself; the customer-facing copy
 * says "Messaging registration" throughout.
 */
enum MessagingRegistrationStatus: string
{
    case NotStarted = 'not_started';
    case Pending = 'pending';
    case Rejected = 'rejected';
    case Approved = 'approved';
}
