<?php

namespace App\Library\AgencyOutreach;

use App\Library\Merge\MergeContext;
use App\Library\Merge\MergeFieldRegistry;
use App\Library\Merge\MergeFieldResolver;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectingSetting;
use App\Models\Business;
use App\Models\Workspace;
use Throwable;

/**
 * Turns script text into the message a prospect receives — through the ONE
 * canonical merge engine (App\Library\Merge\MergeFieldResolver), never a second
 * substitution routine (contract §3).
 *
 * The context is the Agency's own Business, the prospect and the Agency's
 * Outreach settings. The engine itself proves the prospect and settings belong to
 * that Business's Workspace and treats a mismatch as absent, so a mis-built call
 * cannot render another Agency's copy or another Agency's prospect.
 *
 * NEVER LEAKS A TOKEN. An unknown or empty token renders blank (engine
 * contract); when the Agency's Business cannot be resolved a transient,
 * unsaved Business stands in so `{{agency.name}}` still resolves from the
 * saved settings; and anything the engine could not handle is stripped at the end,
 * so a raw `{{...}}` never reaches a stranger's phone.
 *
 * Static for the same reason as the Business resolver: callable as
 * `OutreachScriptRenderer::render()` or on an injected instance.
 */
final class OutreachScriptRenderer
{
    private const LEFTOVER_TOKEN = '/\{\{[^{}]*\}\}/';

    public static function render(string $template, Workspace $workspace, ?AgencyProspect $prospect = null): string
    {
        $text = '';

        try {
            $context = self::context($workspace, $prospect);
            $text = app(MergeFieldResolver::class)->render($template, $context);
        } catch (Throwable) {
            $text = $template;
        }

        $text = preg_replace(self::LEFTOVER_TOKEN, '', $text) ?? '';

        return self::tidy($text);
    }

    /**
     * Merge tokens in `$text` this Agency cannot resolve, for the editor to flag.
     * Only the Agency and prospect groups are offered in a script.
     *
     * @return list<string>
     */
    public static function unknownTokens(string $text, Workspace $workspace): array
    {
        try {
            $business = AgencyOutreachBusinessResolver::forWorkspace($workspace) ?? self::standIn($workspace);

            return app(MergeFieldResolver::class)->unknownTokens(
                $text,
                $business,
                [MergeFieldRegistry::GROUP_AGENCY, MergeFieldRegistry::GROUP_PROSPECT],
            );
        } catch (Throwable) {
            return [];
        }
    }

    private static function context(Workspace $workspace, ?AgencyProspect $prospect): MergeContext
    {
        $settings = AgencyProspectingSetting::query()->where('workspace_id', (int) $workspace->id)->first();
        $business = AgencyOutreachBusinessResolver::forWorkspace($workspace);

        if ($business === null) {
            $business = self::standIn($workspace, $settings);
        }

        return new MergeContext(
            business: $business,
            contact: null,
            prospect: $prospect,
            outreach: $settings,
        );
    }

    /**
     * An unsaved Business that carries only the Workspace id and the Agency's
     * name, so agency-scoped tokens still resolve while the real Business is not
     * resolvable. It is never persisted and has id 0, so no contact, location or
     * custom-field lookup can match anything.
     */
    private static function standIn(Workspace $workspace, ?AgencyProspectingSetting $settings = null): Business
    {
        $business = new Business();
        $business->forceFill([
            'id' => 0,
            'workspace_id' => (int) $workspace->id,
            'name' => trim((string) $settings?->agency_name),
        ]);

        return $business;
    }

    /** Removes the gaps a blank token leaves, without touching deliberate line breaks. */
    private static function tidy(string $text): string
    {
        $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;
        $text = preg_replace('/[ \t]+([,.;:!?])/', '$1', $text) ?? $text;
        $text = preg_replace('/[ \t]+(\r?\n)/', '$1', $text) ?? $text;

        return trim($text);
    }
}
