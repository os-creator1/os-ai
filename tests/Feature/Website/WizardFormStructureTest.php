<?php

namespace Tests\Feature\Website;

use App\Enums\Website\WebsiteAssetPurpose;
use App\Models\Website;
use App\Models\WebsiteAsset;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireSeeder;
use Database\Seeders\WebsiteTemplateSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Independent-review correction round 2 — the gallery and custom-section
 * screens used to nest <form> tags (a gallery photo's update/cover/move/
 * remove forms, and a custom-section image's remove form, all inside the
 * page's own answer/autosave <form>). Nested forms are invalid HTML:
 * browsers ignore or implicitly close the outer form at the first inner
 * closing tag, so the real DOM a browser builds does not match what the
 * Blade source appears to show — `assertSee()` can never catch this,
 * since the markup text itself looks fine; only actually parsing the
 * response as a DOM and walking real element/form relationships proves
 * the structure a browser would build.
 *
 * Uses PHP's built-in `ext-dom` (DOMDocument/DOMXPath, already a
 * composer.json-declared requirement) rather than symfony/dom-crawler,
 * which is not an installed dependency of this project.
 */
class WizardFormStructureTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private function dom(string $html): DOMXPath
    {
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();

        return new DOMXPath($document);
    }

    /**
     * Walks up from a node to find its nearest ancestor <form>, exactly
     * as a browser's own form-association algorithm would for an element
     * with no explicit `form=` attribute.
     */
    private function ancestorFormAction(\DOMNode $node): ?string
    {
        $current = $node->parentNode;

        while ($current !== null) {
            if ($current instanceof \DOMElement && strtolower($current->tagName) === 'form') {
                return $current->getAttribute('action');
            }

            $current = $current->parentNode;
        }

        return null;
    }

    public function test_no_form_is_nested_inside_another_on_the_gallery_step(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->seed(WebsiteTemplateSeeder::class);
        $this->seed(PhotoboothWebsiteSetupQuestionnaireSeeder::class);

        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);
        $website = Website::where('business_id', $business->id)->sole();
        WebsiteAsset::create([
            'website_id' => $website->id, 'disk' => 'public', 'path' => 'images/websites/x/a.png',
            'mime_type' => 'image/png', 'size' => 100, 'purpose' => WebsiteAssetPurpose::Gallery->value,
        ]);

        $html = $this->get(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'gallery']))
            ->assertOk()
            ->getContent();

        $xpath = $this->dom($html);
        $forms = $xpath->query('//form');
        $this->assertGreaterThan(1, $forms->length, 'Precondition: the gallery step must render more than one form.');

        // No <form> may be a descendant of another <form> — this is the
        // literal, DOM-level proof that none are nested.
        foreach (iterator_to_array($forms) as $form) {
            $nestedForms = (new DOMXPath($form->ownerDocument))->query('.//form', $form);
            $this->assertSame(0, $nestedForms->length, 'A <form> must never contain another <form> as a descendant.');
        }
    }

    public function test_continue_button_belongs_to_the_answer_form_not_a_gallery_management_form(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->seed(WebsiteTemplateSeeder::class);
        $this->seed(PhotoboothWebsiteSetupQuestionnaireSeeder::class);
        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);
        $website = Website::where('business_id', $business->id)->sole();
        WebsiteAsset::create([
            'website_id' => $website->id, 'disk' => 'public', 'path' => 'images/websites/x/a.png',
            'mime_type' => 'image/png', 'size' => 100, 'purpose' => WebsiteAssetPurpose::Gallery->value,
        ]);

        $html = $this->get(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'gallery']))->getContent();
        $xpath = $this->dom($html);

        $continueButtons = $xpath->query("//button[contains(text(), 'Continue')]");
        $this->assertGreaterThan(0, $continueButtons->length);
        $continueAction = $this->ancestorFormAction($continueButtons->item(0));
        $this->assertStringContainsString('/answers', (string) $continueAction, 'Continue must submit to the autosave/answers endpoint.');

        $removeButtons = $xpath->query("//form[contains(@action, '/gallery/')]//button[contains(text(), 'Remove')]");
        $this->assertGreaterThan(0, $removeButtons->length, 'Precondition: at least one gallery remove button must render.');
        $removeAction = $this->ancestorFormAction($removeButtons->item(0));
        $this->assertStringContainsString('/gallery/', (string) $removeAction);
        $this->assertStringNotContainsString('/answers', (string) $removeAction, 'A gallery photo action must never be the same form as Continue.');
    }

    public function test_improve_with_ai_button_is_associated_with_the_answer_form_via_the_form_attribute_not_nesting(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->seed(WebsiteTemplateSeeder::class);
        $this->seed(PhotoboothWebsiteSetupQuestionnaireSeeder::class);
        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);

        // Fast-forward to custom_section (last step) via completeAllRequiredSteps-equivalent inline posting is unnecessary —
        // reach it directly once the response exists, since show() resolves whichever step is requested and currently visible only
        // after being reached in order; instead exercise goToStep is not exposed directly, so walk through required steps quickly.
        $params = fn (string $step) => [$workspace->uid, $business->uid, $step];
        $freshRevision = fn () => \App\Models\QuestionnaireResponse::where('business_id', $business->id)->where('status', 'in_progress')->sole()->answers_revision;
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('business_name')), ['value' => 'X', 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('phone')), ['value' => '6305550100', 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('email')), ['value' => 'x@x.test', 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('service_area_cities')), ['value' => 'Chicago', 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('primary_cta')), ['value' => 'quote_request', 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('brand_personality')), ['value' => 'playful', 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('booth_types')), ['items' => [['name' => 'Booth']], 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('services_event_types')), ['items' => [], 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('packages')), ['items' => [['name' => 'Pkg']], 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('offers_backdrops')), ['value' => '0', 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('gallery')), ['value' => null, 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('testimonials')), ['items' => [], 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('faq_items')), ['items' => [], 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('about_story')), ['value' => 'Story', 'answers_revision' => $freshRevision()]);
        $this->post(route('customer.workspaces.businesses.website.setup.autosave', $params('contact_form_fields')), ['value' => ['name'], 'answers_revision' => $freshRevision()]);

        $html = $this->get(route('customer.workspaces.businesses.website.setup.step', [$workspace->uid, $business->uid, 'custom_section']))->assertOk()->getContent();
        $xpath = $this->dom($html);

        $answerForms = $xpath->query("//form[contains(@id, 'answer-form')]");
        $this->assertSame(1, $answerForms->length);
        $answerFormId = $answerForms->item(0)->getAttribute('id');

        $improveButtons = $xpath->query("//button[contains(text(), 'Improve with AI')]");
        $this->assertGreaterThan(0, $improveButtons->length);
        $improveButton = $improveButtons->item(0);

        // The button is NOT a descendant of a form pointed at the improve
        // action (proving it is not itself wrapped in a second, nested
        // form) — it is a sibling that associates with the answer form
        // purely via the `form` attribute, submitting THAT form's fields.
        $this->assertSame($answerFormId, $improveButton->getAttribute('form'), 'Improve with AI must submit the answer form\'s own fields via form=, never a nested form of its own.');
        $this->assertStringContainsString('improve', (string) $improveButton->getAttribute('formaction'));

        $nestedForms = $xpath->query(".//form", $improveButton);
        $this->assertSame(0, $nestedForms->length);
    }
}
