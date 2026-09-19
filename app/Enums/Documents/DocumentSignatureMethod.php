<?php

namespace App\Enums\Documents;

/**
 * Implementation Contract 17 §5.5/§6.5 — first-party typed-signature evidence.
 * A TECHNICAL signing record; no legal-sufficiency claim is made. A vendor or
 * other method would add a case here.
 */
enum DocumentSignatureMethod: string
{
    case Typed = 'typed';
}
