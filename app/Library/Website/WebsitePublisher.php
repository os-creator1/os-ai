<?php

namespace App\Library\Website;

use App\Enums\Website\WebsiteStatus;
use App\Events\Website\WebsitePublished;
use App\Models\Website;
use App\Models\WebsiteAsset;
use App\Models\WebsiteRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Website Generation + Hosting Slice A contract §9.1/§10. The publish
 * algorithm and rollback, exactly as locked. No network operation runs
 * inside either transaction — no AI call, no outbound HTTP, no queue
 * dispatch requiring an external round trip before commit.
 * WebsitePublished (§9.2) is ShouldDispatchAfterCommit, so dispatching
 * it from inside the transaction is safe: Laravel itself defers running
 * any listener until the transaction actually commits.
 */
final class WebsitePublisher
{
    public function __construct(
        private readonly WebsiteSnapshotBuilder $snapshotBuilder,
        private readonly WebsiteSectionValidator $sectionValidator,
    ) {
    }

    /**
     * @throws ValidationException
     */
    public function publish(Website $website, int $actingUserId): WebsiteRevision
    {
        return DB::transaction(function () use ($website, $actingUserId) {
            // Locks the Website row for the duration of the transaction,
            // serializing concurrent publish attempts for the SAME
            // Website and making the version_number increment below
            // race-safe.
            $lockedWebsite = Website::where('id', $website->id)->lockForUpdate()->first();

            $this->validateDraft($lockedWebsite);

            $snapshot = $this->snapshotBuilder->build($lockedWebsite);

            $nextVersion = (int) WebsiteRevision::where('website_id', $lockedWebsite->id)->max('version_number') + 1;

            $revision = WebsiteRevision::create([
                'website_id' => $lockedWebsite->id,
                'version_number' => $nextVersion,
                'snapshot' => $snapshot,
                'schema_version' => WebsiteSnapshotBuilder::SCHEMA_VERSION,
                'created_by' => $actingUserId,
                'created_at' => now(),
            ]);

            $lockedWebsite->update([
                'published_revision_id' => $revision->id,
                'status' => WebsiteStatus::Published,
            ]);

            $this->markAssetsPublished($snapshot);

            WebsitePublished::dispatch($lockedWebsite->id, $revision->id, $lockedWebsite->business_id);

            return $revision;
        });
    }

    /**
     * @throws ValidationException
     */
    public function rollback(Website $website, string $revisionUid): WebsiteRevision
    {
        return DB::transaction(function () use ($website, $revisionUid) {
            $lockedWebsite = Website::where('id', $website->id)->lockForUpdate()->first();

            $revision = WebsiteRevision::where('website_id', $lockedWebsite->id)->where('uid', $revisionUid)->first();

            abort_unless($revision !== null, 404);

            $lockedWebsite->update([
                'published_revision_id' => $revision->id,
                'status' => WebsiteStatus::Published,
            ]);

            // Contract §10 — a rollback is, from WebsitePublished's own
            // perspective, indistinguishable from any other change of
            // the live revision: it emits the same event.
            WebsitePublished::dispatch($lockedWebsite->id, $revision->id, $lockedWebsite->business_id);

            return $revision;
        });
    }

    /**
     * @throws ValidationException
     */
    private function validateDraft(Website $website): void
    {
        $pages = $website->pages;

        if ($pages->isEmpty()) {
            throw ValidationException::withMessages([
                'website' => ['A Website must have at least one page before it can be published.'],
            ]);
        }

        if ($pages->where('is_home', true)->count() !== 1) {
            throw ValidationException::withMessages([
                'website' => ['A Website must have exactly one homepage before it can be published.'],
            ]);
        }

        $validAssetUids = WebsiteAsset::where('website_id', $website->id)->pluck('uid')->all();

        foreach ($pages as $page) {
            $this->sectionValidator->validate($page->sections ?? [], $validAssetUids, true);
        }
    }

    private function markAssetsPublished(array $snapshot): void
    {
        $uids = collect($snapshot['assets'] ?? [])->pluck('uid')->all();

        if (empty($uids)) {
            return;
        }

        WebsiteAsset::whereIn('uid', $uids)
            ->whereNull('first_published_at')
            ->update(['first_published_at' => now()]);
    }
}
