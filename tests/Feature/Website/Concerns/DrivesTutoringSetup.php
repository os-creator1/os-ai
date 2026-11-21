<?php

namespace Tests\Feature\Website\Concerns;

use App\Enums\Forms\FormDeploymentSource;
use App\Library\Forms\FormManager;
use App\Library\NicheBlueprint\NicheBlueprintInstaller;
use App\Models\BusinessBlueprintComponentInstallation;
use App\Models\Form;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\DB;

/**
 * Drives a TUTORING owner through the REAL guided setup over HTTP (the niche's seeded questionnaire, answered screen by screen)
 * and generation with the shared fake AI. The using class defines DOMAIN and TEMPLATES and uses CreatesWebsiteFixtures,
 * DrivesGuidedWizard and FakesGuidedWebsiteAi.
 */
trait DrivesTutoringSetup
{
    protected $deployment = null;

    protected ?Form $form = null;

    /** Seeds the shipped niche (vertical, four looks, questionnaire, blueprint), installs it, and lets the OWNER switch its student form on. */
    protected function tutoringTenant(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        User::query()->whereKey($this->platformAdminId())->update(['is_admin' => true]);
        $this->artisan('blueprint:seed-niche', ['niche' => 'tutoring_exam_prep', '--actor' => $this->platformAdminId()])->assertExitCode(0);

        $this->tenant(['name' => 'Petal Tutoring', 'industry' => 'professional_services'], ['city' => 'Petal', 'region' => 'MS', 'country_code' => 'US'], start: false);
        app(\App\Library\Business\BusinessKnowledgeProfileManager::class)->getOrCreate($this->business);
        DB::table('business_knowledge_profiles')->where('business_id', $this->business->id)->update(['vertical_key' => 'tutoring_exam_prep']);
        app(NicheBlueprintInstaller::class)->installForBusiness($this->business->fresh());

        $id = BusinessBlueprintComponentInstallation::query()->where('business_id', $this->business->id)->where('component_key', 'tutoring_form_student_inquiry')->where('state', 'installed')->value('installed_record_id');
        $this->assertNotNull($id, 'the Tutoring blueprint installed its student inquiry form');
        $this->form = Form::query()->where('business_id', $this->business->id)->findOrFail($id);
        $manager = app(FormManager::class);
        $manager->activate($this->business->fresh(), $this->form);
        $this->deployment = $manager->setDeployment($this->business->fresh(), $this->form->fresh(), $this->business->primaryLocation()->firstOrFail(), true, FormDeploymentSource::Website);

        $this->get($this->url('start'));
    }

    protected function group(array $values)
    {
        return $this->jpost($this->url('autosave', array_key_first($values)), [
            'answers_revision' => $this->wizardSession()->answers_revision,
            '__present' => array_keys($values),
            's' => collect($values)->map(fn ($v) => ['value' => $v])->all(),
        ]);
    }

    /** @param array<string, mixed> $o per-screen overrides; null skips a screen */
    protected function completeTutoringSetup(array $o = []): void
    {
        $screens = [
            'business_name' => fn () => $this->continueBasics(['business_name' => 'Petal Tutoring', 'phone' => '601-555-0142', 'email' => 'hello@petaltutoring.test']),
            'base_location' => fn () => $this->continueStep('base_location', ['value' => ['city' => 'Petal', 'region' => 'MS', 'country_code' => 'US']]),
            'service_area_cities' => fn () => $this->continueStep('service_area_cities', ['value' => ['Petal, MS', 'Hattiesburg, MS']]),
            'lesson_services' => fn () => $this->continueStep('lesson_services', ['items' => [
                ['key' => 'svc-math', 'name' => 'Mathematics Tutoring', 'description' => 'Weekly lessons that work through what the student is studying.', 'preset' => 'mathematics', 'visible' => '1'],
                ['key' => 'svc-exam', 'name' => 'Exam Preparation', 'description' => 'Practice with the topics and format of an upcoming exam.', 'preset' => 'exam_preparation', 'visible' => '1'],
            ]]),
            'ideal_customers' => fn () => $this->continueStep('ideal_customers', ['value' => 'Students in grades 6 to 12 and their parents.']),
            'how_lessons_work' => fn () => $this->continueStep('how_lessons_work', ['items' => [['name' => 'How a first lesson works', 'layout' => 'stacked', 'body' => 'We start by talking with the student about what they are studying and where they are stuck.']]]),
            'about_story' => fn () => $this->continueStep('about_story', ['value' => 'Petal Tutoring is a small local tutoring business offering mathematics and exam preparation lessons.']),
            'advantages' => fn () => $this->continueStep('advantages', ['value' => ['one_on_one', 'trial_lesson']]),
            'testimonials' => fn () => $this->continueStep('testimonials', ['items' => []]),
            'faq_items' => fn () => $this->continueStep('faq_items', ['items' => [
                ['question' => 'Do you offer a trial lesson?', 'answer' => 'Yes. Ask about a trial lesson when you send an inquiry.'],
                ['question' => 'Are lessons online or in person?', 'answer' => 'Both are available; tell us which you prefer.'],
            ]]),
            'gallery' => fn () => $this->continueStep('gallery', ['value' => '3']),
            // Consultation booking is asked only by tests that exercise it (an unanswered question touches nothing).
            'consultation_booking' => null,
            'consultation_days' => null,
            'consultation_hours' => null,
            'consultation_duration' => null,
            'consultation_notice' => null,
            'brand_personality' => fn () => $this->continueStep('brand_personality', ['value' => 'bold']),
            'primary_cta' => fn () => $this->continueStep('primary_cta', ['value' => 'quote_request']),
        ];

        foreach ($screens as $key => $send) {
            $override = array_key_exists($key, $o) ? $o[$key] : '__default__';

            if ($override === null) {
                continue;
            }

            if (is_callable($override)) {
                $send = $override;
            }

            if ($send === null) {
                continue;
            }

            $send()->assertOk();
        }
    }

    /** The owner's last Generate response (its flash message reports what setup did, e.g. with online booking). */
    protected $generateResponse = null;

    protected function generate(): Website
    {
        $this->fakeProvider();
        $this->generateResponse = $this->post($this->url('generate'), ['answers_revision' => $this->wizardSession()->answers_revision])
            ->assertRedirect(route('customer.workspaces.businesses.website.preview', [$this->workspace->uid, $this->business->uid]));

        return Website::where('business_id', $this->business->id)->firstOrFail();
    }

    /** The owner's own route, built BEFORE any custom-domain request (the URL generator keeps the last request's host). */
    protected function owner(string $route, array $extra = []): string
    {
        return route('customer.workspaces.businesses.website.' . $route, array_merge([$this->workspace->uid, $this->business->uid], $extra));
    }

    protected function site(string $path = '/'): string
    {
        return 'http://' . self::DOMAIN . $path;
    }

    protected function pageForm($page, callable $change): array
    {
        return [
            'title' => $page->title, 'slug' => $page->is_home ? '' : $page->slug, 'is_home' => $page->is_home ? '1' : '0',
            'sections' => json_encode($change($page->sections ?? [])), 'seo_title' => $page->seo_title,
            'meta_description' => $page->meta_description, 'noindex' => $page->noindex ? '1' : '0',
        ];
    }

    protected function publishAsOwner(array $urls): void
    {
        $this->post($urls['publish'])->assertRedirect();
        $this->get($urls['studio'])->assertOk();
        app('cache')->flush();
    }

}
