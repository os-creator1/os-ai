<?php

namespace App\Enums\Documents;

/**
 * Implementation Contract 17 §5.2 — Blueprint §18's six-state lifecycle
 * (draft -> sent -> signed/paid -> expired/void). Payment progress is a
 * separate axis derived from schedule items (§5.9); there is deliberately no
 * partially_paid state. Written only by the later canonical managers.
 */
enum DocumentStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Signed = 'signed';
    case Paid = 'paid';
    case Expired = 'expired';
    case Void = 'void';
}
