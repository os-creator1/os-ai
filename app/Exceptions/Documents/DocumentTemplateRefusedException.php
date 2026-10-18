<?php

namespace App\Exceptions\Documents;

use RuntimeException;

/**
 * Implementation Contract 17B §6 — a template action the caller IS allowed to
 * attempt but that the template's or document's state refuses (an archived
 * template, a legacy document, a document that is no longer a draft). The
 * message is customer-facing. HTTP 422. A template the caller may not touch at
 * all is NOT this exception: it is a ModelNotFoundException (404), so there is
 * no existence oracle between Businesses.
 */
final class DocumentTemplateRefusedException extends RuntimeException
{
}
