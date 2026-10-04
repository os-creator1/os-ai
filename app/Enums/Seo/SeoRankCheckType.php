<?php

namespace App\Enums\Seo;

/** Which provider endpoint a run uses. Values are stored and used in idempotency keys. */
enum SeoRankCheckType: string
{
    case Organic = 'organic';
    case Local = 'local';
}
