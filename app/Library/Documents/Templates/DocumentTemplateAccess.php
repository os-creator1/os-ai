<?php

namespace App\Library\Documents\Templates;

use App\Enums\Documents\DocumentTemplateStatus;
use App\Exceptions\Documents\DocumentTemplateRefusedException;
use App\Models\Business;
use App\Models\DocumentTemplate;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Implementation Contract 17B §6 — who may touch which template. Every rule
 * fails closed and every refusal for a template the Business may not touch is
 * the SAME ModelNotFoundException (HTTP 404), whether the uid is foreign,
 * forged, a platform template that was not recommended, or simply unknown:
 * there is no existence oracle between Businesses.
 *
 *   own template        read / edit / use / duplicate / archive / restore
 *                       (business_id match only)
 *   platform template   read and use ONLY when RecommendedPlatformTemplates
 *                       contains it for this Business; never written here
 *   anything else       404
 */
final class DocumentTemplateAccess
{
    public function __construct(private readonly RecommendedPlatformTemplates $recommended)
    {
    }

    /** A template of THIS Business (any status) by uid, else 404. For edit / duplicate / archive / restore. */
    public function owned(Business $business, string $uid): DocumentTemplate
    {
        return DocumentTemplate::query()
            ->where('uid', $uid)
            ->where('business_id', $business->id)
            ->first() ?? throw (new ModelNotFoundException())->setModel(DocumentTemplate::class);
    }

    /** Is this template the Business's own? (Never true for a platform template.) */
    public function isOwnedBy(DocumentTemplate $template, Business $business): bool
    {
        return $template->business_id !== null && (int) $template->business_id === (int) $business->id;
    }

    /**
     * A template the Business may READ (preview): its own (any status), or a
     * platform template it is recommended.
     */
    public function readable(Business $business, string $uid): DocumentTemplate
    {
        $own = DocumentTemplate::query()->where('uid', $uid)->where('business_id', $business->id)->first();

        return $own ?? $this->recommendedByUid($business, $uid) ?? throw (new ModelNotFoundException())->setModel(DocumentTemplate::class);
    }

    /**
     * A template the Business may USE to start a document: its own ACTIVE
     * template, or a recommended active platform template. An own template that
     * is archived (or still a draft) is refused with a message — it is the
     * caller's own row, so that reveals nothing.
     *
     * @throws DocumentTemplateRefusedException
     */
    public function usable(Business $business, string $uid): DocumentTemplate
    {
        $template = $this->readable($business, $uid);
        $this->assertUsable($business, $template);

        return $template;
    }

    /**
     * Re-check a template object (never trust a caller-supplied model).
     *
     * @throws ModelNotFoundException
     * @throws DocumentTemplateRefusedException
     */
    public function assertUsable(Business $business, DocumentTemplate $template): void
    {
        $allowed = $this->isOwnedBy($template, $business)
            || ($template->isPlatformOwned() && $this->recommended->forBusiness($business)->contains(fn ($t) => (int) $t->id === (int) $template->id));

        if (! $allowed) {
            throw (new ModelNotFoundException())->setModel(DocumentTemplate::class);
        }

        if ($template->status === DocumentTemplateStatus::Archived) {
            throw new DocumentTemplateRefusedException('This template is archived. Restore it to use it.');
        }

        if ($template->status !== DocumentTemplateStatus::Active) {
            throw new DocumentTemplateRefusedException('This template is not available.');
        }
    }

    private function recommendedByUid(Business $business, string $uid): ?DocumentTemplate
    {
        return $this->recommended->forBusiness($business)
            ->first(fn ($t) => $t instanceof DocumentTemplate && $t->business_id === null && (string) $t->uid === $uid);
    }
}
