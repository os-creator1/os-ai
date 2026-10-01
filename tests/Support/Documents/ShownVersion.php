<?php

namespace Tests\Support\Documents;

use App\Models\BusinessDocument;
use App\Models\BusinessDocumentVersion;

/**
 * What a signer's page would have been rendered from: the uid of the document's
 * CURRENT issued version. DocumentManager::sign() requires this assertion so a
 * signature can never land on a version the signer was not shown.
 */
final class ShownVersion
{
    public static function uid(BusinessDocument $document): string
    {
        return (string) BusinessDocumentVersion::query()
            ->findOrFail(BusinessDocument::query()->whereKey($document->id)->value('current_version_id'))
            ->uid;
    }
}
