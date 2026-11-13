<?php

namespace App\Library\AgencySite;

use App\Library\Branding\AgencyWhiteLabelManager;
use App\Models\AgencySite;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * White Label — a narrow, explicit way to remove the OLD event/photo-booth demo copy from a draft that has since
 * moved to an industry niche.
 *
 * An Agency draft stores an answer only when that field was edited. A saved answer that is still IDENTICAL to the
 * general MotionGrove default is a pinned copy of the platform's event demo (it was saved while that was the default,
 * or by an old preset); it overrides the niche and keeps the event wording on the site. Those — and only those — are
 * reset by apply(), through the ordinary validated save, so the field follows the niche again.
 *
 * Anything the Agency really wrote is never touched. Custom text that merely mentions event-demo wording is only
 * REPORTED (`review`) so the owner can rewrite it; branding, links, calendar, plans and every other answer are
 * untouched by construction (the save call names exactly the pinned paths).
 */
final class LegacySampleCleaner
{
    /** Wording that belongs to the platform's event / photo-booth demo and nowhere in a dog-business site. */
    public const EVENT_TERMS = '/Lumen Events|lumenevents|wedding|photo[\s-]?booth|\bbooths?\b|\bparty\b|\bparties\b|\bgala\b|birthday|corporate events?|event services|Riverside Gala|Nguyen|Unforgettable events/iu';

    public function __construct(
        private readonly SiteContentBuilder $builder,
        private readonly SiteContextFactory $contexts,
        private readonly SiteEditorPresenter $presenter,
        private readonly AgencySiteManager $manager,
        private readonly AgencyWhiteLabelManager $whiteLabel,
    ) {
    }

    /**
     * @return array{pinned: list<string>, review: list<string>} saved paths that are verbatim copies of the general
     *         default (safe to reset), and saved paths with custom text that still mentions event-demo wording
     */
    public function analyse(AgencySite $site): array
    {
        $values = AgencySiteManager::values($site);
        $context = $this->contexts->make($site, $values);
        [$general] = $this->builder->build([], $context);
        $index = SiteSchema::fieldIndex();
        $pinned = [];
        $review = [];

        foreach ($values as $path => $saved) {
            $field = $index[$path] ?? null;

            if ($field === null || (str_starts_with($path, 'x.') && $path !== 'x.industries')) {
                continue;
            }

            $default = $this->presenter->defaultFor($field, $general);

            if (json_encode($this->presenter->forEditor($field, $saved, $general)) === json_encode($default)) {
                $pinned[] = $path;
            } elseif (preg_match(self::EVENT_TERMS, (string) json_encode($saved, JSON_UNESCAPED_UNICODE)) === 1) {
                $review[] = $path;
            }
        }

        return ['pinned' => $pinned, 'review' => $review];
    }

    /**
     * Reset the pinned copies for the industry the owner has SAVED. The niche is never supplied by the caller: it is read
     * from the latest draft under the lock, so a stale page (or request) can neither pick nor overwrite the selection,
     * and a draft on General is refused untouched. Returns what was removed, so the previous draft can be restored by
     * re-saving it, and logs the same.
     *
     * Which values are removable is decided against the LATEST draft while holding the same workspace write lock
     * AgencySiteManager::save() takes (the lock is re-entrant inside this transaction, so the save below does not wait
     * on itself). A custom answer saved from another tab a moment ago is therefore seen and kept; it can never be
     * mistaken for the stale copy it replaced. $site only identifies the draft: its loaded values are never trusted.
     *
     * @return array{niche: string, removed: array<string, mixed>, review: list<string>}
     */
    public function apply(int $actorUserId, Workspace $agency, AgencySite $site): array
    {
        return DB::transaction(function () use ($actorUserId, $agency): array {
            // Same lock, same order as every other draft write: workspace row first, then the draft.
            $locked = Workspace::query()->whereKey($agency->id)->lockForUpdate()->firstOrFail();
            $this->whiteLabel->assertCanManage($locked, $actorUserId);

            $latest = $this->manager->find($locked);
            abort_if($latest === null, 404);

            $values = AgencySiteManager::values($latest);
            $niche = (string) ($values['x.niche'] ?? NichePresets::GENERAL);

            if ($niche === NichePresets::GENERAL || ! array_key_exists($niche, NichePresets::NICHES)) {
                throw ValidationException::withMessages(['niche' => 'Choose an industry first: the old examples are only replaced for the industry saved on this draft.']);
            }

            $analysis = $this->analyse($latest);
            $removed = array_intersect_key($values, array_flip($analysis['pinned']));

            // x.niche is deliberately NOT written: the saved selection is what this cleanup follows.
            $this->manager->save($actorUserId, $locked, array_fill_keys($analysis['pinned'], null));

            Log::info('agency-site.legacy-samples-cleared', ['agency_workspace_id' => $locked->id, 'niche' => $niche, 'previous_niche' => $values['x.niche'] ?? null, 'removed' => $removed]);

            return ['niche' => $niche, 'removed' => $removed, 'review' => $analysis['review']];
        });
    }
}
