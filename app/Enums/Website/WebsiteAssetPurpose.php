<?php

namespace App\Enums\Website;

/**
 * Independent-review correction round 2 — the durable, non-user-editable
 * classification that replaces overloading `category_tag` as an
 * ownership/purpose boundary between three genuinely different kinds of
 * Website-owned image. Set once at creation by the code path that
 * creates the asset (WebsiteGalleryManager::uploadMany() for Gallery,
 * WebsiteWizardController::uploadCustomSectionImage() for CustomSection,
 * MediaBindingService::mirrorPackageImages() for PackageMirror) — never
 * through the customer-editable title/category_tag fields.
 */
enum WebsiteAssetPurpose: string
{
    /** General homepage/service photography and the Gallery page pool. */
    case Gallery = 'gallery';

    /** The wizard's optional custom-section media — never mixed into the general gallery/hero pool. */
    case CustomSection = 'custom_section';

    /** The owner's logo (Website V1 final) — site chrome referenced from the theme, never a gallery photo. */
    case Logo = 'logo';

    /** The owner's hero image (Website V1 final) — referenced from the theme, never a gallery photo. */
    case Hero = 'hero';

    /** A package's cover image, mirrored purely for section-validator purposes — never a customer-uploaded asset, never counted against upload limits. */
    case PackageMirror = 'package_mirror';
}
