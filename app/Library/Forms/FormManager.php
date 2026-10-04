<?php

namespace App\Library\Forms;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Enums\Forms\FormDeploymentSource;
use App\Enums\Forms\FormLifecycleState;
use App\Library\Forms\Exceptions\FormRuleException;
use App\Library\Forms\Exceptions\FormStaleVersionException;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Form;
use App\Models\FormDeployment;
use App\Models\FormVersion;
use Illuminate\Support\Facades\DB;

/**
 * Forms V1 — the ONE writer of form definitions, versions and deployments.
 * Mirrors `CatalogItemManager` for its shape: every method that receives an
 * existing row re-loads it FRESH, UNDER LOCK, by primary key alone, and
 * compares that row's own persisted `business_id` against the Business the
 * caller claims to be acting on — a caller's copy of a model is never trusted
 * for ownership, and a Location is re-derived from persistence and proven to
 * belong to the form's Business before it is bound.
 *
 * It enforces NONE of tenancy, capability, entitlement or Location ACL — those
 * are the HTTP boundary's job (AuthorizesFormsRequests). Its own job is the
 * rules a domain manager must apply whatever authorized the call.
 *
 * VERSIONING. A definition is edited in place from the customer's view, but a
 * new immutable `FormVersion` is written whenever the content hash changes, and
 * `forms.current_version` moves to it. Saving an unchanged form writes nothing.
 * New submissions use the current version; every old submission keeps pointing
 * at the version it was answered against.
 *
 * CONCURRENCY. Every mutation locks the `forms` row first, so two edits cannot
 * both claim version N+1, and two deployment toggles for one form serialize.
 * The unique indexes on (form_id, version) and (form_id, location, source) are
 * the real backstop beneath that lock.
 */
final class FormManager
{
    public function __construct(private readonly FormDefinitionNormalizer $definitions)
    {
    }

    /**
     * Creates a DRAFT form and its first version.
     *
     * @param  array<string, mixed>  $input  name, intro, submit_label, success_message, fields, create_opportunity, opportunity_pipeline_id
     */
    public function create(Business $business, array $input, ?int $actorUserId = null): Form
    {
        $name = $this->definitions->name($input['name'] ?? null);
        $content = $this->definitions->content($business, $input);

        return DB::transaction(function () use ($business, $name, $content, $actorUserId): Form {
            $form = new Form(['business_id' => $business->id, 'name' => $name, 'created_by_user_id' => $actorUserId]);
            $form->forceFill([
                'lifecycle_state' => FormLifecycleState::Draft,
                'current_version' => 1,
            ])->save();

            $this->writeVersion($form, 1, $content, $actorUserId);

            return $form->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Business $business, Form $form, array $input, ?int $actorUserId = null, ?int $expectedVersion = null): Form
    {
        $name = $this->definitions->name($input['name'] ?? null);
        // The current version's questions: a mapping it already holds may be kept
        // even if its custom field has since been archived (never newly added).
        $content = $this->definitions->content($business, $input, $form->currentVersion()?->fields ?? []);

        return DB::transaction(function () use ($business, $form, $name, $content, $actorUserId, $expectedVersion): Form {
            $locked = $this->lock($business, $form);

            // The visual builder autosaves: it names the version it was editing, and
            // a save on top of any other version is refused under the lock (a stale
            // tab must never silently overwrite a newer edit). A caller that does not
            // name one (the classic form post) keeps the last-write-wins behaviour.
            if ($expectedVersion !== null && $expectedVersion !== (int) $locked->current_version) {
                throw new FormStaleVersionException((int) $locked->current_version);
            }

            $locked->forceFill(['name' => $name]);

            $current = FormVersion::query()
                ->where('form_id', $locked->id)
                ->where('version', $locked->current_version)
                ->firstOrFail();

            if ($current->content_hash !== $this->definitions->hash($content)) {
                $next = $locked->current_version + 1;
                $this->writeVersion($locked, $next, $content, $actorUserId);
                $locked->forceFill(['current_version' => $next]);
            }

            $locked->save();

            return $locked->fresh();
        });
    }

    /** Draft or Inactive -> Active. Already Active is a no-op. */
    public function activate(Business $business, Form $form): Form
    {
        return DB::transaction(function () use ($business, $form): Form {
            $locked = $this->lock($business, $form);

            if ($locked->lifecycle_state === FormLifecycleState::Active) {
                return $locked;
            }

            $locked->forceFill([
                'lifecycle_state' => FormLifecycleState::Active,
                'activated_at' => $locked->activated_at ?? now(),
            ])->save();

            return $locked->fresh();
        });
    }

    /** Active -> Inactive. A draft has nothing to switch off; Inactive is a no-op. */
    public function deactivate(Business $business, Form $form): Form
    {
        return DB::transaction(function () use ($business, $form): Form {
            $locked = $this->lock($business, $form);

            if ($locked->lifecycle_state === FormLifecycleState::Draft) {
                throw new FormRuleException('This form has not been activated yet, so there is nothing to switch off.');
            }

            if ($locked->lifecycle_state === FormLifecycleState::Active) {
                $locked->forceFill(['lifecycle_state' => FormLifecycleState::Inactive])->save();
            }

            return $locked->fresh();
        });
    }

    /**
     * Offer (or stop offering) the form at one Location, from one source.
     *
     * The Location is RE-DERIVED from persistence by id and must belong to the
     * form's own Business — a Location of another Business is refused, never
     * bound. A new deployment needs an ACTIVE Location; an existing one may
     * always be switched off, even if its Location has since been archived.
     *
     * @return ?FormDeployment null only when asked to disable a deployment that never existed
     */
    public function setDeployment(
        Business $business,
        Form $form,
        BusinessLocation $location,
        bool $enabled,
        FormDeploymentSource $source = FormDeploymentSource::DirectLink,
    ): ?FormDeployment {
        return DB::transaction(function () use ($business, $form, $location, $enabled, $source): ?FormDeployment {
            $locked = $this->lock($business, $form);

            $authoritative = BusinessLocation::query()
                ->where('id', $location->id)
                ->where('business_id', $locked->business_id)
                ->first();

            if ($authoritative === null) {
                throw new FormRuleException('That location is not part of this Business.');
            }

            $deployment = FormDeployment::query()
                ->where('form_id', $locked->id)
                ->where('business_location_id', $authoritative->id)
                ->where('source', $source->value)
                ->lockForUpdate()
                ->first();

            if ($deployment === null) {
                if (! $enabled) {
                    return null;
                }

                if ($authoritative->lifecycle_state !== BusinessLocationLifecycleState::Active) {
                    throw new FormRuleException('An archived location cannot offer a form.');
                }

                return FormDeployment::create([
                    'form_id' => $locked->id,
                    'business_location_id' => $authoritative->id,
                    'source' => $source->value,
                    'is_enabled' => true,
                ]);
            }

            if ($enabled && $authoritative->lifecycle_state !== BusinessLocationLifecycleState::Active) {
                throw new FormRuleException('An archived location cannot offer a form.');
            }

            if ((bool) $deployment->is_enabled !== $enabled) {
                $deployment->forceFill(['is_enabled' => $enabled])->save();
            }

            return $deployment;
        });
    }

    /**
     * Re-loads the form fresh and under lock by primary key, and proves it
     * belongs to $business by its OWN persisted business_id.
     */
    private function lock(Business $business, Form $form): Form
    {
        $locked = Form::query()->whereKey($form->id)->lockForUpdate()->first();

        if ($locked === null || (int) $locked->business_id !== (int) $business->id) {
            throw new FormRuleException('That form is not part of this Business.');
        }

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function writeVersion(Form $form, int $version, array $content, ?int $actorUserId): FormVersion
    {
        return FormVersion::create([
            'form_id' => $form->id,
            'version' => $version,
            'content_hash' => $this->definitions->hash($content),
            'intro' => $content['intro'],
            'submit_label' => $content['submit_label'],
            'success_message' => $content['success_message'],
            'pages' => $content['pages'],
            'design' => $content['design'] ?? null,
            'fields' => $content['fields'],
            'create_opportunity' => $content['create_opportunity'],
            'opportunity_pipeline_id' => $content['opportunity_pipeline_id'],
            'created_by_user_id' => $actorUserId,
        ]);
    }
}
