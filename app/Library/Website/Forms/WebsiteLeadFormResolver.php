<?php

namespace App\Library\Website\Forms;

use App\Enums\Forms\FormDeploymentSource;
use App\Enums\NicheBlueprint\BlueprintComponentInstallationState;
use App\Library\Forms\Embed\FormWebsiteEmbed;
use App\Library\NicheBlueprint\Adapters\FormComponentAdapter;
use App\Library\NicheBlueprint\Niches\DogBoardingBlueprint;
use App\Library\NicheBlueprint\Niches\KidsCeramicsBlueprint;
use App\Library\NicheBlueprint\Niches\TutoringBlueprint;
use App\Models\Business;
use App\Models\BusinessBlueprintComponentInstallation;
use App\Models\Form;
use App\Models\FormDeployment;

/**
 * Which Forms-module form is THIS business's website lead form, and is it live on Website pages right now?
 *
 * The answer comes only from the business's own blueprint installation records (a `form` component the niche blueprint
 * installed for THIS business) and its real Website-source FormDeployment — never from a form's display name, and never from
 * the AI. Niche blueprints ship more than one form (an event questionnaire, a teacher application, a meet & greet), so only the
 * component keys listed in LEAD_FORM_KEYS are ever considered the website lead form, in preference order; a business whose
 * niche has none of them gets no module form (the caller falls back to a plain contact block).
 *
 * Nothing here activates, deploys or edits a form. A draft form, or an active one with no enabled Website-source deployment,
 * resolves to null and is reported as "needs setup" so the owner decides — blueprint installs are drafts by design.
 */
final class WebsiteLeadFormResolver
{
    /**
     * Preference order. Each key is owned by its niche blueprint: the Photo Booth Business Pack v3 lead form, then the older
     * Photo Booth Blueprint v2 availability form, then each other niche's own customer-inquiry form. Questionnaires and
     * staff-facing forms (event questionnaire, teacher application, meet & greet) are deliberately absent.
     *
     * @var array<int, string>
     */
    public const LEAD_FORM_KEYS = [
        'pb3_form_lead',
        'photo_booth_form_availability',
        DogBoardingBlueprint::FORM_STAY,
        KidsCeramicsBlueprint::FORM,
        TutoringBlueprint::STUDENT_FORM,
    ];

    public const STATE_READY = 'ready';

    public const STATE_NEEDS_SETUP = 'needs_setup';

    public const STATE_NOT_INSTALLED = 'not_installed';

    public function __construct(private readonly FormWebsiteEmbed $embed)
    {
    }

    /**
     * The live, proven reference to place on a page, or null.
     *
     * @return ?array{uid: string, url: string, title: string, height: int, form_uid: string, location_uid: string, component_key: string}
     */
    public function resolve(Business $business): ?array
    {
        return $this->inspect($business)['embed'];
    }

    /**
     * @return array{state: string, message: string, embed: ?array, component_key: ?string}
     */
    public function inspect(Business $business): array
    {
        $installed = $this->installedLeadForms($business);

        if ($installed === []) {
            return ['state' => self::STATE_NOT_INSTALLED, 'message' => 'No lead form is installed for this business yet, so the website shows your phone and email instead.', 'embed' => null, 'component_key' => null];
        }

        foreach ($installed as $key => $form) {
            $deployments = FormDeployment::query()
                ->where('form_id', $form->id)
                ->where('source', FormDeploymentSource::Website->value)
                ->where('is_enabled', true)
                ->orderBy('id')
                ->get()
                ->sortBy(fn (FormDeployment $deployment) => $deployment->location?->is_primary ? 0 : 1)
                ->values();

            foreach ($deployments as $deployment) {
                $resolved = $this->embed->resolve($business, (string) $deployment->uid);

                if ($resolved !== null) {
                    return ['state' => self::STATE_READY, 'message' => '', 'embed' => $resolved + ['component_key' => $key], 'component_key' => $key];
                }
            }
        }

        $name = $installed[array_key_first($installed)]->name;

        return [
            'state' => self::STATE_NEEDS_SETUP,
            'message' => "Your lead form \"{$name}\" is not live on your website yet. Activate it and offer it to Website pages (Forms → Deployments) so visitors can submit it; until then your pages show your phone and email.",
            'embed' => null,
            'component_key' => array_key_first($installed),
        ];
    }

    /**
     * This business's installed lead-form components, in LEAD_FORM_KEYS order, each proven to be a Form OF THIS BUSINESS.
     *
     * @return array<string, Form>
     */
    private function installedLeadForms(Business $business): array
    {
        $rows = BusinessBlueprintComponentInstallation::query()
            ->where('business_id', $business->id)
            ->where('component_type', FormComponentAdapter::TYPE)
            ->where('state', BlueprintComponentInstallationState::Installed->value)
            ->whereIn('component_key', self::LEAD_FORM_KEYS)
            ->whereNotNull('installed_record_id')
            ->get()
            ->keyBy('component_key');

        $forms = [];

        foreach (self::LEAD_FORM_KEYS as $key) {
            $row = $rows->get($key);

            if ($row === null) {
                continue;
            }

            // The installation record names a form id; it is only trusted when that form belongs to this business.
            $form = Form::query()->where('id', $row->installed_record_id)->where('business_id', $business->id)->first();

            if ($form !== null) {
                $forms[$key] = $form;
            }
        }

        return $forms;
    }
}
