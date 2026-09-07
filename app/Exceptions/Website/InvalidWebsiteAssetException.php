<?php

namespace App\Exceptions\Website;

use Exception;

/**
 * Website Generation + Hosting Slice A contract §13. Mirrors
 * App\Library\Branding\Exceptions\InvalidBrandingAssetException exactly
 * — thrown on any upload validation/storage/integrity failure.
 */
class InvalidWebsiteAssetException extends Exception
{
}
