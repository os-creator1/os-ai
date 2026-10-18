<?php

namespace App\Library\Website\Forms;

use App\Enums\Forms\FormDeploymentSource;
use App\Enums\Website\WebsiteSectionType;
use App\Library\Forms\Embed\FormWebsiteEmbed;
use App\Library\Forms\FormSubmissionReader;
use App\Models\Business;
use App\Models\Form;
use App\Models\FormDeployment;
use App\Models\Website;

/**
 * Website V1 closure — the Website-side consumer of the Forms module's embed seam
 * (App\Library\Forms\Embed\FormWebsiteEmbed, docs/automation/FORMS-VISUAL-BUILDER-V1.md §8a).
 *
 * A `forms_module_form` section stores ONE thing: the stable, public `uid` of a
 * Website-source FormDeployment ("this form, at this Location, offered to Website
 * pages") — never a raw id and never a URL. This class answers the three questions the
 * Website needs about such a reference, and owns no form logic of its own:
 *
 *  - options():   what may an owner pick right now (enabled, active form + location of
 *                 THIS Business, at a Location the owner can see);
 *  - knownUids(): which references belong to this Business at all (validation at draft
 *                 and publish time — a foreign uid is refused outright);
 *  - resolve():   the live, re-proven answer (FormWebsiteEmbed::resolve), null when the
 *                 reference is stale (form switched off, location closed, ...).
 */
final class WebsiteFormsModuleReferences
{
    public function __construct(
        private readonly FormWebsiteEmbed $embed,
        private readonly FormSubmissionReader $reader,
    ) {}

    /**
     * @return array<int, array{uid: string, label: string}>
     */
    public function options(Business $business, int $userId): array
    {
        $visible = $this->reader->visibleLocations($business, $userId);

        if ($visible === []) {
            return [];
        }

        $options = [];

        foreach (FormDeployment::query()
            ->where('source', FormDeploymentSource::Website->value)
            ->where('is_enabled', true)
            ->whereIn('form_id', Form::where('business_id', $business->id)->pluck('id'))
            ->whereIn('business_location_id', array_keys($visible))
            ->orderBy('id')
            ->get() as $deployment) {
            $resolved = $this->embed->resolve($business, (string) $deployment->uid);

            if ($resolved === null) {
                continue;
            }

            $location = $visible[$deployment->business_location_id];
            $options[] = ['uid' => $resolved['uid'], 'label' => $resolved['title'] . ' — ' . ($location->name ?: $location->city ?: 'Location')];
        }

        return $options;
    }

    /**
     * Every Website-source reference of this Business, in any state. A stale one is allowed to
     * stay on a page (it renders nothing); a uid of another Business never is.
     *
     * @return array<int, string>
     */
    public function knownUids(Business $business): array
    {
        return FormDeployment::query()
            ->where('source', FormDeploymentSource::Website->value)
            ->whereIn('form_id', Form::where('business_id', $business->id)->pluck('id'))
            ->pluck('uid')
            ->map(fn ($uid) => (string) $uid)
            ->all();
    }

    /**
     * What a page renders for one `forms_module_form` section's data: the frozen `resolved` array on a
     * published page, a live re-resolution in the owner's Preview. Null = render nothing.
     *
     * @param  array<string, mixed>  $data
     * @return ?array{uid: string, url: string, title: string, height: int, form_uid: string, location_uid: string}
     */
    public function renderable(array $data, Website $website, bool $isPreview): ?array
    {
        if ($isPreview) {
            return $this->resolve($website->business, (string) ($data['forms_module_deployment_uid'] ?? ''));
        }

        return is_array($data['resolved'] ?? null) ? $data['resolved'] : null;
    }

    /** @return ?array{uid: string, url: string, title: string, height: int, form_uid: string, location_uid: string} */
    public function resolve(Business $business, string $uid): ?array
    {
        return $this->embed->resolve($business, $uid);
    }

    /**
     * Placed Forms-module forms of this Website's DRAFT pages that no longer resolve.
     *
     * @return array<int, array{page: string, uid: string}>
     */
    public function unresolvedOn(Website $website): array
    {
        $business = $website->business;
        $stale = [];

        foreach ($website->pages()->get() as $page) {
            foreach ($page->sections ?? [] as $section) {
                if (($section['type'] ?? null) !== WebsiteSectionType::FormsModuleForm->value) {
                    continue;
                }

                $uid = (string) ($section['data']['forms_module_deployment_uid'] ?? '');

                if ($uid !== '' && $this->resolve($business, $uid) === null) {
                    $stale[] = ['page' => (string) $page->title, 'uid' => $uid];
                }
            }
        }

        return $stale;
    }
}
