<?php

namespace Tests\Feature\ExternalSite;

use App\Library\ExternalSite\Rebuild\ExternalConfirmedFacts;
use App\Library\Website\Setup\WizardPresentationAnswers;
use App\Library\Website\Setup\WizardPrefill;
use App\Models\ExternalRebuildPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesGuidedWizard;
use Tests\TestCase;

/**
 * Owner-confirmed facts from the existing website reach the wizard (as editable, display-only prefill) and, once the
 * owner submits those screens, the generator's inputs. Unconfirmed facts never do. Nothing here publishes or generates.
 */
class ExistingSiteWizardPrefillTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;
    use DrivesGuidedWizard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGuidedWizard();
    }

    private function plan(string $status = 'confirmed', ?int $businessId = null): ExternalRebuildPlan
    {
        $fact = fn (string $key, string $type, string $label, bool $confirmed) => ['key' => $key, 'type' => $type, 'label' => $label, 'source_path' => 'x', 'confirmed' => $confirmed];

        return ExternalRebuildPlan::create([
            'business_id' => $businessId ?? $this->business->id, 'source_host' => 'photoboothcompanyoftoronto.com', 'status' => $status, 'pages' => [],
            'facts' => [
                $fact('event:corporate', 'event_type', 'Corporate events', true),
                $fact('area:toronto', 'service_area', 'Toronto', true),
                $fact('event:wedding', 'event_type', 'Wedding events', false),
                $fact('area:etobicoke', 'service_area', 'Etobicoke', false),
                $fact('area:vaughan', 'service_area', 'Vaughan', false),
                // A tampered or invented label is never offered, even if marked confirmed.
                $fact('area:atlantis', 'service_area', 'Atlantis', true),
                $fact('event:scam', 'event_type', 'Free money', true),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function prefill(): array
    {
        $session = $this->wizardSession();

        return WizardPrefill::forSteps($this->business->fresh(), $session->version->steps(), (array) ($session->answers ?? []));
    }

    public function test_only_confirmed_facts_are_prefilled_and_never_invented_ones(): void
    {
        $this->tenant(location: ['city' => 'Toronto', 'region' => 'Ontario', 'country_code' => 'CA', 'service_area_cities' => ['Mississauga']]);
        $this->plan();

        $prefill = $this->prefill();

        // The Business's own area stays first; the confirmed one follows; unconfirmed and invented ones are absent.
        $this->assertSame(['Mississauga', 'Toronto'], $prefill['service_area_cities']);
        $events = collect($prefill['event_types']);
        $this->assertSame(['Corporate Events'], $events->pluck('name')->all());
        $this->assertSame('corporate', $events->first()['preset']);
        $this->assertNull($events->first()['starting_price'], 'No price, claim or text is copied from the old site.');
        $this->assertSame(['corporate'], ExternalConfirmedFacts::forBusiness($this->business)['events']);
        $this->assertSame(['Toronto'], ExternalConfirmedFacts::forBusiness($this->business)['areas']);
    }

    public function test_an_unconfirmed_plan_or_someone_elses_plan_prefills_nothing(): void
    {
        $this->tenant(location: ['city' => 'Toronto', 'region' => 'Ontario', 'country_code' => 'CA', 'service_area_cities' => ['Mississauga']]);

        $draft = $this->plan('draft');
        $this->assertSame(['Mississauga'], $this->prefill()['service_area_cities']);
        $this->assertArrayNotHasKey('event_types', $this->prefill());
        $draft->delete();

        // A confirmed plan that belongs to ANOTHER business.
        [, $other] = $this->entitledTenant();
        $this->plan('confirmed', $other->id);
        $this->assertSame(['areas' => [], 'events' => []], ExternalConfirmedFacts::forBusiness($this->business));
        $this->assertArrayNotHasKey('event_types', $this->prefill());
    }

    public function test_the_owners_saved_answers_always_win_over_the_prefill(): void
    {
        $this->tenant(location: ['city' => 'Toronto', 'region' => 'Ontario', 'country_code' => 'CA', 'service_area_cities' => ['Mississauga']]);
        $this->plan();

        $this->continueStep('service_area_cities', ['value' => ['Oakville']])->assertOk();
        $this->continueStep('event_types', ['items' => [['key' => 'mine', 'name' => 'Birthdays', 'description' => 'Mine.', 'visible' => '1']]])->assertOk();

        $this->assertArrayNotHasKey('service_area_cities', $this->prefill());
        $this->assertArrayNotHasKey('event_types', $this->prefill());
    }

    public function test_confirmed_facts_reach_the_generator_inputs_only_after_the_owner_submits_them(): void
    {
        $this->tenant(location: ['city' => 'Toronto', 'region' => 'Ontario', 'country_code' => 'CA', 'service_area_cities' => ['Mississauga']]);
        $this->plan();

        // The screens render with the prefill, but nothing is saved by looking at them.
        $this->assertLessThan(500, $this->get($this->url('step', 'service_area_cities'))->status());
        $this->assertLessThan(500, $this->get($this->url('step', 'event_types'))->status());
        $this->assertSame([], WizardPresentationAnswers::eventRows($this->wizardSession()));
        $this->assertNull(WizardPresentationAnswers::serviceAreas($this->wizardSession()));

        // The owner reviews and continues with exactly what was offered.
        $prefill = $this->prefill();
        $this->continueStep('service_area_cities', ['value' => $prefill['service_area_cities']])->assertOk();
        $this->continueStep('event_types', ['items' => collect($prefill['event_types'])->map(fn ($r) => ['key' => $r['key'], 'name' => $r['name'], 'description' => $r['description'], 'preset' => $r['preset'], 'visible' => '1'])->all()])->assertOk();

        $session = $this->wizardSession();
        $events = collect(WizardPresentationAnswers::eventRows($session))->pluck('name')->all();
        $areas = collect(WizardPresentationAnswers::serviceAreas($session))->map(fn ($a) => is_array($a) ? $a['name'] : $a)->all();

        $this->assertSame(['Corporate Events'], $events, 'Corporate service reaches the generator; the unconfirmed wedding fact does not.');
        $this->assertContains('Toronto', $areas);
        $this->assertContains('Mississauga', $areas);
        $this->assertNotContains('Etobicoke', $areas);
        $this->assertNotContains('Vaughan', $areas);
        $this->assertNotContains('Atlantis', $areas);
        $this->assertNull(\App\Models\Website::where('business_id', $this->business->id)->first()?->published_revision_id, 'Nothing is published.');
    }
}
