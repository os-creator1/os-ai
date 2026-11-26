<?php

namespace Tests\Feature\Website\Acceptance;

use App\Library\Website\Design\WebsiteDesigns;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The same customer-facing setup routes as a real owner: choose template,
 * answer guided screens, upload media, Review and Generate. Uses a disposable
 * tenant and the existing deterministic fake AI, never live customer data.
 *
 * This is HTTP functional acceptance, NOT a browser screenshot or a
 * pixel-fidelity certificate.
 */
final class ThreeReferenceWizardJourneyTest extends TestCase
{
    use RefreshDatabase;
    use RunsOwnerJourney;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSmartWizard();
        $this->useDisposablePublicRoot();
    }

    protected function tearDown(): void
    {
        $this->removeDisposablePublicRoot();
        parent::tearDown();
    }

    protected function wizardUrl(object $workspace, object $business, string $route, array $extra = []): string
    {
        return 'http://127.0.0.1' . route(
            'customer.workspaces.businesses.website.' . $route,
            array_merge([$workspace->uid, $business->uid], $extra),
            false,
        );
    }

    public function test_kaleb_reference_setup_creates_a_deterministic_draft(): void
    {
        $this->verifyWizardBuild('photo_booth_reference_kaleb');
    }

    public function test_booths_reference_setup_creates_a_deterministic_draft(): void
    {
        $this->verifyWizardBuild('photo_booth_reference_booths');
    }

    public function test_greenbelt_reference_setup_creates_a_deterministic_draft(): void
    {
        $this->verifyWizardBuild('photo_booth_reference_greenbelt');
    }

    private function verifyWizardBuild(string $templateKey): void
    {
        [, $business, , $website] = $this->runOwnerJourneyThroughGenerate($templateKey, 8);
        $website = $website->fresh();

        $this->assertSame($templateKey, $website->template_key);
        $this->assertNull($website->published_revision_id, 'Generating does not automatically publish');
        $this->assertSame(1, $website->pages()->where('is_home', true)->count());
        $this->assertGreaterThanOrEqual(3, $website->pages()->count());

        $home = $website->pages()->where('is_home', true)->firstOrFail();
        $sections = $home->sections ?? [];
        $this->assertSame('hero', $sections[0]['type']);
        $this->assertTrue(collect($sections)->contains(fn ($section) => ($section['type'] ?? null) === 'cta'),
            'The actual wizard must generate an inquiry path');
        $this->assertSame(
            WebsiteDesigns::forTemplateKey($templateKey)->orderHomeSections($sections),
            $sections,
            'A reference design cannot shuffle customer-authored section order',
        );
    }
}
