<?php

namespace App\Library\ExternalSite\Rebuild;

use App\Library\Website\GuidedGeneration\WebsiteGenerationCoordinator;
use App\Library\Website\Seo\WebsiteRedirectManager;
use App\Models\ExternalRebuildPlan;
use App\Models\Website;
use App\Models\WebsitePage;
use Illuminate\Validation\ValidationException;

/**
 * Applies the owner's CONFIRMED redirect and title/description decisions to the DRAFT of the new site. Redirects live in
 * the Website's draft redirect map and only become live inside a published revision, so nothing here can change what the
 * public sees, switch a domain, publish, or touch the external site. Existing owner-defined redirects are never
 * overwritten. Runs under the Website's generation lease so it can never interleave with a build.
 */
final class ExternalRebuildApplier
{
    public function __construct(
        private readonly ExternalRebuildRedirectPlan $redirects,
        private readonly WebsiteRedirectManager $manager,
        private readonly WebsiteGenerationCoordinator $coordinator,
    ) {
    }

    /** @return array{redirects: int, seo: int} */
    public function apply(ExternalRebuildPlan $plan, Website $website): array
    {
        if (! $plan->isConfirmed() || (int) $plan->business_id !== (int) $website->business_id) {
            throw ValidationException::withMessages(['plan' => ['Confirm the plan before applying it to your draft.']]);
        }

        return $this->coordinator->runExclusive($website, function () use ($plan, $website) {
            $drafts = WebsitePage::where('website_id', $website->id)->get();
            $rows = $this->redirects->build($plan, $drafts);
            $decisions = (array) ($plan->decisions['pages'] ?? []);

            $map = (array) ($website->custom_redirects ?? []);
            $added = 0;

            foreach ($rows as $row) {
                if ($row['state'] === 'redirect' && ! array_key_exists($row['path'], $map)) {
                    $map[$row['path']] = (string) $row['target'];
                    $added++;
                }
            }

            if ($added > 0) {
                $this->manager->set($website, $map);
            }

            $seo = 0;
            $bySlug = $drafts->keyBy(fn ($p) => (bool) $p->is_home ? '' : (string) $p->slug);

            foreach ($rows as $row) {
                $keep = (bool) ($decisions[$row['key']]['keep_seo'] ?? false);
                $target = $row['state'] === 'redirect' || $row['state'] === 'kept' ? $row['target'] : null;

                if (! $keep || $target === null || $row['claim_flags'] !== [] || ! $bySlug->has($target)) {
                    continue;
                }

                if (mb_strlen($row['title']) < 10 || mb_strlen($row['title']) > 70 || mb_strlen($row['meta_description']) > 160) {
                    continue;
                }

                $bySlug[$target]->forceFill(['seo_title' => $row['title'], 'meta_description' => $row['meta_description'] !== '' ? $row['meta_description'] : $bySlug[$target]->meta_description])->save();
                $seo++;
            }

            $plan->forceFill(['applied_at' => now()])->save();

            return ['redirects' => $added, 'seo' => $seo];
        });
    }
}
