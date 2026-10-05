<?php

namespace Tests\Feature\Forms;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Forms\FormDeploymentSource;
use App\Library\Forms\Embed\FormWebsiteEmbed;
use App\Library\Forms\FormManager;
use App\Library\Forms\FormOperationToken;
use App\Library\Forms\FormSubmissionService;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Form;
use App\Models\FormDeployment;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\TestCase;

/**
 * Forms visual builder, completion pass: draft editing of a never-published form
 * (one working version, rewritten in place, closed for good by activation) and the
 * Website reference seam.
 */
class FormBuilderDraftAndWebsiteSeamTest extends TestCase
{
    use CreatesFormsFixtures;
    use RefreshDatabase;

    private $owner;

    private Business $business;

    private $workspace;

    private BusinessLocation $downtown;

    private BusinessLocation $uptown;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->owner, $this->business, $this->workspace] = $this->formsTenant();
        $this->downtown = $this->formsLocation($this->business, 'Downtown');
        $this->uptown = $this->formsLocation($this->business, 'Uptown');
        $this->formsPipeline($this->business);
        $this->authenticateAs($this->owner);
    }

    private function save(Form $form, array $document)
    {
        return $this->postJson($this->formsRoute('builder.save', $this->workspace, $this->business, [$form->uid]), $document);
    }

    private function versionsOf(Form $form): int
    {
        return FormVersion::where('form_id', $form->id)->count();
    }

    // ------------------------------------------------------------- draft editing

    public function test_editing_a_never_activated_form_rewrites_its_draft_version_in_place(): void
    {
        $form = $this->makeForm($this->business);
        $before = $form->currentVersion()->content_hash;

        $document = $this->builderDocumentFor($form);
        $document['fields'][] = ['key' => 'budget', 'label' => 'Budget', 'type' => 'currency', 'page' => 'page_1'];
        $document['submit_label'] = 'Go';
        $response = $this->save($form, $document)->assertOk()->assertJson(['status' => 'saved', 'version' => 1]);

        $this->assertSame(1, $this->versionsOf($form), 'a draft working version is not multiplied by editing');
        $version = $form->fresh()->currentVersion();
        $this->assertContains('budget', array_column($version->fields, 'key'));
        $this->assertSame('Go', $version->submit_label);
        $this->assertNotSame($before, $version->content_hash);
        $this->assertSame($version->content_hash, $response->json('hash'));
        $this->assertSame(
            app(\App\Library\Forms\FormDefinitionNormalizer::class)->hash(app(\App\Library\Forms\FormDefinitionNormalizer::class)->content($this->business, $document)),
            $version->content_hash,
            'the stored hash is the hash of the stored content'
        );
    }

    public function test_a_stale_tab_on_a_draft_form_is_detected_by_content_not_only_by_version_number(): void
    {
        $form = $this->makeForm($this->business);
        $tabA = $this->builderDocumentFor($form);
        $tabB = $this->builderDocumentFor($form);

        $tabA['intro'] = 'Written in tab A';
        $this->save($form, $tabA)->assertOk();

        $tabB['intro'] = 'Written in tab B';
        $this->save($form, $tabB)->assertStatus(409)->assertJson(['status' => 'conflict', 'current_version' => 1]);

        $this->assertSame('Written in tab A', $form->fresh()->currentVersion()->intro);
    }

    public function test_activation_closes_draft_editing_for_good(): void
    {
        $form = $this->makeForm($this->business);
        $document = $this->builderDocumentFor($form);
        $document['intro'] = 'Final draft';
        $this->save($form, $document)->assertOk();
        $this->assertSame(1, $this->versionsOf($form));

        app(FormManager::class)->activate($this->business, $form);
        $published = FormVersion::where('form_id', $form->id)->firstOrFail();
        $publishedFields = $published->fields;

        $next = $this->builderDocumentFor($form);
        $next['intro'] = 'After publishing';
        $this->save($form, $next)->assertOk()->assertJson(['version' => 2]);

        $this->assertSame('Final draft', FormVersion::find($published->id)->intro, 'the published version is untouched');
        $this->assertSame($publishedFields, FormVersion::find($published->id)->fields);

        // Switching it off again does not reopen in-place editing.
        app(FormManager::class)->deactivate($this->business, $form);
        $again = $this->builderDocumentFor($form);
        $again['intro'] = 'While switched off';
        $this->save($form, $again)->assertOk()->assertJson(['version' => 3]);
    }

    public function test_a_form_that_has_a_submission_is_never_rewritten_even_if_it_looks_unpublished(): void
    {
        [$form, $deployment] = [null, null];
        $form = $this->makeForm($this->business, [], true);
        $deployment = $this->deploy($this->business, $form, $this->downtown);
        app(FormSubmissionService::class)->submit($deployment->uid, $this->submitInput($deployment));
        DB::table('forms')->where('id', $form->id)->update(['activated_at' => null]);   // defensive: pretend it was never activated

        $document = $this->builderDocumentFor($form);
        $document['intro'] = 'Should be a new version';
        $this->save($form, $document)->assertOk()->assertJson(['version' => 2]);

        $this->assertSame(2, $this->versionsOf($form));
        $this->assertSame(1, FormSubmission::count());
    }

    public function test_the_classic_update_still_writes_a_version_for_a_draft(): void
    {
        $form = $this->makeForm($this->business);

        app(FormManager::class)->update($this->business, $form, $this->leadFormInput(['intro' => 'Classic edit']));

        $this->assertSame(2, $this->versionsOf($form), 'only the visual builder opts in to draft editing');
    }

    // ------------------------------------------------------------ Website seam

    private function enableWebsite(Form $form, BusinessLocation $location, bool $enabled = true)
    {
        return $this->post($this->formsRoute('locations.set', $this->workspace, $this->business, [$form->uid, $location->uid]), ['enabled' => $enabled ? 1 : 0, 'source' => 'website']);
    }

    public function test_a_form_is_made_available_to_website_pages_as_a_location_bound_reference(): void
    {
        $form = $this->makeForm($this->business, [], true);

        $this->enableWebsite($form, $this->downtown)->assertRedirect();

        $reference = FormDeployment::where('form_id', $form->id)->where('source', FormDeploymentSource::Website->value)->firstOrFail();
        $this->assertSame((int) $this->downtown->id, (int) $reference->business_location_id);
        $this->assertTrue((bool) $reference->is_enabled);
        $this->assertSame(0, FormDeployment::where('form_id', $form->id)->where('source', FormDeploymentSource::DirectLink->value)->count(), 'a Website reference is not a direct link');

        $resolved = app(FormWebsiteEmbed::class)->resolve($this->business, $reference->uid);
        $this->assertSame(route('public.forms.show', [$reference->uid]), $resolved['url']);
        $this->assertSame($form->uid, $resolved['form_uid']);
        $this->assertSame($this->downtown->uid, $resolved['location_uid']);

        $html = view('public.forms._embed', ['embed' => $resolved])->render();
        $this->assertStringContainsString('<iframe src="'.route('public.forms.show', [$reference->uid]).'"', $html);
        $this->assertSame('', trim(view('public.forms._embed', ['embed' => null])->render()));
    }

    public function test_a_website_submission_is_a_normal_forms_submission_at_the_references_location(): void
    {
        $form = $this->makeForm($this->business, [], true);
        $this->enableWebsite($form, $this->uptown)->assertRedirect();
        $reference = FormDeployment::where('source', 'website')->firstOrFail();

        $this->get(route('public.forms.show', [$reference->uid]))->assertOk();
        $submission = app(FormSubmissionService::class)->submit($reference->uid, $this->submitInput($reference, [FormSubmissionService::TOKEN_FIELD => FormOperationToken::issue($reference)]))->submission;

        $this->assertSame((int) $this->uptown->id, (int) $submission->business_location_id);
        $this->assertSame('website', $submission->source);
        $this->assertNotNull($submission->contact_id);
    }

    public function test_the_reference_resolves_nothing_for_anything_that_is_not_a_live_website_reference_of_this_business(): void
    {
        [, $other] = $this->formsTenant(WorkspacePlanTier::Core, 'Other Business');
        $form = $this->makeForm($this->business, [], true);
        $direct = $this->deploy($this->business, $form, $this->downtown);
        $this->enableWebsite($form, $this->uptown)->assertRedirect();
        $reference = FormDeployment::where('source', 'website')->firstOrFail();
        $embed = app(FormWebsiteEmbed::class);

        $this->assertNotNull($embed->resolve($this->business, $reference->uid));
        $this->assertNull($embed->resolve($other, $reference->uid), 'another Business cannot resolve my reference');
        $this->assertNull($embed->resolve($this->business, $direct->uid), 'a direct link is not a Website reference');
        $this->assertNull($embed->resolve($this->business, 'not-a-uid'));

        $this->enableWebsite($form, $this->uptown, false)->assertRedirect();
        $this->assertNull($embed->resolve($this->business, $reference->uid), 'switched off');

        $this->enableWebsite($form, $this->uptown)->assertRedirect();
        $this->assertNotNull($embed->resolve($this->business, $reference->uid));
        app(FormManager::class)->deactivate($this->business, $form);
        $this->assertNull($embed->resolve($this->business, $reference->uid), 'an inactive form shows nothing on a Website page');
    }

    public function test_a_forged_source_or_foreign_location_cannot_create_a_reference(): void
    {
        [, $other] = $this->formsTenant(WorkspacePlanTier::Core, 'Other Business');
        $theirs = $this->formsLocation($other, 'Elsewhere');
        $form = $this->makeForm($this->business, [], true);

        $this->post($this->formsRoute('locations.set', $this->workspace, $this->business, [$form->uid, $this->downtown->uid]), ['enabled' => 1, 'source' => 'carrier_pigeon'])->assertSessionHasErrors('source');
        $this->post($this->formsRoute('locations.set', $this->workspace, $this->business, [$form->uid, $theirs->uid]), ['enabled' => 1, 'source' => 'website'])->assertNotFound();

        $this->assertSame(0, FormDeployment::where('form_id', $form->id)->count());
    }

    public function test_integrate_offers_a_location_choice_and_the_website_reference(): void
    {
        $form = $this->makeForm($this->business, [], true);
        $this->deploy($this->business, $form, $this->downtown);
        $this->enableWebsite($form, $this->downtown)->assertRedirect();
        $reference = FormDeployment::where('source', 'website')->firstOrFail();

        $html = $this->get($this->formsRoute('edit', $this->workspace, $this->business, [$form->uid]))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="forms-integrate-location"', $html, 'two locations => a location selector');
        $this->assertStringContainsString('data-role="forms-website-reference-uid"', $html);
        $this->assertStringContainsString('value="'.$reference->uid.'"', $html);
        $this->assertStringContainsString('data-role="forms-integrate-embed"', $html, 'the iframe snippet is preserved');
        $this->assertStringContainsString('Make available to Website pages', $html);
    }
}
