<?php

namespace App\Library\Forms\Embed;

use App\Enums\Forms\FormDeploymentSource;
use App\Enums\Forms\FormLifecycleState;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Form;
use App\Models\FormDeployment;

/**
 * The seam that lets the Website place a Forms-module form on a page, WITHOUT a
 * second form implementation and without the Website owning any form logic.
 *
 * THE REFERENCE. A Website-source FormDeployment (FormDeploymentSource::Website)
 * is "this form, at this Location, offered to Website pages". Its public `uid` is
 * the stable, embeddable reference a Website component stores
 * (`data.forms_module_deployment_uid`). Because a deployment is Location-bound,
 * the reference carries the Location with it — a page never chooses one — and a
 * submission through it is a normal Forms submission (same service, same version
 * pinning, same Contact resolution, same event).
 *
 * THE CONTRACT. `resolve()` is what a Website snapshot builder calls at publish
 * time for each referenced uid: it re-proves, from persistence, that the
 * deployment is a Website-source row of THIS Business, enabled, with an active
 * Form and an active Location of the same Business, and returns plain data
 * (`url`, `title`, `height`). It returns null — never throws — for anything else,
 * so a stale reference renders nothing instead of leaking another Business's form.
 * The renderer contract is the `public.forms._embed` partial fed that data.
 *
 * The Website's shared wiring (registering the component, embedding the resolved
 * array in the published snapshot) is deliberately NOT done here; see
 * docs/automation/FORMS-VISUAL-BUILDER-V1.md §Website placement.
 */
final class FormWebsiteEmbed
{
    public const DEFAULT_HEIGHT = 760;

    /**
     * @return ?array{uid: string, url: string, title: string, height: int, form_uid: string, location_uid: string}
     */
    public function resolve(Business $business, string $deploymentUid): ?array
    {
        $deployment = FormDeployment::query()
            ->where('uid', $deploymentUid)
            ->where('source', FormDeploymentSource::Website->value)
            ->where('is_enabled', true)
            ->first();

        if ($deployment === null) {
            return null;
        }

        $form = Form::query()->where('id', $deployment->form_id)->where('business_id', $business->id)->first();
        $location = BusinessLocation::query()->where('id', $deployment->business_location_id)->where('business_id', $business->id)->first();

        if ($form === null || $location === null || $form->lifecycle_state !== FormLifecycleState::Active || ! $location->isActive()) {
            return null;
        }

        return [
            'uid' => (string) $deployment->uid,
            'url' => route('public.forms.show', [$deployment->uid]),
            'title' => (string) $form->name,
            'height' => self::DEFAULT_HEIGHT,
            'form_uid' => (string) $form->uid,
            'location_uid' => (string) $location->uid,
        ];
    }
}
