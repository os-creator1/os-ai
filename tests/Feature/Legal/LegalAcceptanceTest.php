<?php

namespace Tests\Feature\Legal;

use App\Library\Legal\LegalAcceptanceService;
use App\Library\Legal\LegalCompany;
use App\Library\Legal\LegalDocuments;
use App\Models\LegalAcceptance;
use App\Models\LegalDocumentSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Accepted legal documents keep the exact text, version and company details they were accepted with. */
class LegalAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private const OLD = 'Bičių g. 23, LT-11111 Vilnius, Lithuania';

    protected function setUp(): void
    {
        parent::setUp();
        config(['marketing.enabled' => true, 'marketing.hosts' => [], 'legal.acceptance_enabled' => true]);
    }

    private function user(bool $admin = false): User
    {
        return User::create([
            'first_name' => 'T', 'last_name' => 'U', 'email' => 'u'.uniqid('', true).'@example.test',
            'status' => true, 'is_admin' => $admin, 'is_customer' => ! $admin, 'active_portal' => $admin ? 'admin' : 'customer',
        ]);
    }

    private function service(): LegalAcceptanceService
    {
        return app(LegalAcceptanceService::class);
    }

    private function version(): string
    {
        return app(LegalDocuments::class)->meta('terms-of-service')['version'];
    }

    public function test_no_acceptance_exists_for_any_account_by_default_and_viewing_is_not_acceptance(): void
    {
        $this->user();
        $this->get('/terms-of-service')->assertOk();

        $this->assertSame(0, LegalAcceptance::query()->count());
        $this->assertSame(0, LegalDocumentSnapshot::query()->count());
    }

    public function test_valid_acceptance_is_recorded_with_context_workspace_and_offer(): void
    {
        $user = $this->user();
        $rendered = app(LegalDocuments::class)->render('terms-of-service');

        $a = $this->service()->record($user, 'terms-of-service', $this->version(), 'subscription_checkout', 42, 'core-28d', $rendered['hash']);

        $this->assertSame($user->id, (int) $a->user_id);
        $this->assertSame(42, (int) $a->workspace_id);
        $this->assertSame('subscription_checkout', $a->context);
        $this->assertSame('core-28d', $a->offer_ref);
        $this->assertSame($this->version(), $a->document_version);
        $this->assertNotNull($a->accepted_at);
        $this->assertSame($rendered['hash'], $a->snapshot->content_hash);
        $this->assertTrue($this->service()->hasAccepted($user->id, 'terms-of-service'));
    }

    public function test_address_change_updates_public_pages_but_not_the_accepted_snapshot(): void
    {
        $user = $this->user();
        $owner = $this->user(true);
        $accepted = $this->service()->record($user, 'terms-of-service', $this->version(), 'registration');
        $originalHash = $accepted->snapshot->content_hash;

        app(LegalCompany::class)->update($owner->id, [
            'brand_name' => 'MotionGrove', 'legal_entity' => 'Jazmin medija, MB', 'company_number' => '306304073',
            'registered_address' => 'Virtual Office 5, Vilnius', 'country' => 'Lithuania', 'legal_email' => 'legal@getmotiongrove.com',
        ], 1);

        $this->get('/terms-of-service')->assertOk()->assertSee('Virtual Office 5, Vilnius')->assertDontSee('Bičių g. 23');

        $fresh = LegalAcceptance::query()->findOrFail($accepted->id);
        $this->assertStringContainsString(self::OLD, $fresh->snapshot->rendered_html);
        $this->assertStringNotContainsString('Virtual Office 5', $fresh->snapshot->rendered_html);
        $this->assertSame($originalHash, $fresh->snapshot->content_hash);
        $this->assertSame($this->version(), $fresh->document_version);
        $this->assertSame(1, (int) $fresh->snapshot->company_profile_id);

        // A routine address update does not demand re-acceptance...
        $this->assertTrue($this->service()->hasAccepted($user->id, 'terms-of-service'));

        // ...and a later acceptance freezes its own, different, snapshot without touching the first.
        $second = $this->service()->record($this->user(), 'terms-of-service', $this->version(), 'registration');
        $this->assertNotSame($originalHash, $second->snapshot->content_hash);
        $this->assertStringContainsString('Virtual Office 5', $second->snapshot->rendered_html);
        $this->assertSame(2, LegalDocumentSnapshot::query()->count());
    }

    public function test_a_material_version_change_is_distinguishable_and_requires_new_acceptance(): void
    {
        $user = $this->user();
        $v1 = $this->service()->record($user, 'terms-of-service', $this->version(), 'registration');

        config(['legal.documents.terms-of-service.version' => '2.0']);

        $this->assertFalse($this->service()->hasAccepted($user->id, 'terms-of-service'));
        $v2 = $this->service()->record($user, 'terms-of-service', '2.0', 'registration');

        $this->assertNotSame($v1->document_version, $v2->document_version);
        $this->assertNotSame($v1->snapshot_id, $v2->snapshot_id);
        $this->assertSame('1.0-draft', $v1->fresh()->snapshot->version);
    }

    public function test_forged_or_stale_client_values_are_rejected_and_nothing_is_stored(): void
    {
        $user = $this->user();

        foreach ([
            fn () => $this->service()->record($user, 'terms-of-service', '9.9', 'registration'),
            fn () => $this->service()->record($user, 'terms-of-service', $this->version(), 'registration', null, null, str_repeat('0', 64)),
            fn () => $this->service()->record($user, 'terms-of-service', $this->version(), 'forged-context'),
            fn () => $this->service()->record($user, 'no-such-document', $this->version(), 'registration'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A forged acceptance was recorded.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, LegalAcceptance::query()->count());
    }

    public function test_documents_with_unverified_facts_and_a_disabled_switch_cannot_be_accepted(): void
    {
        $user = $this->user();
        $docs = app(LegalDocuments::class);

        foreach (['privacy-policy', 'data-processing-addendum', 'cookie-policy', 'security'] as $slug) {
            $this->assertFalse($this->service()->isOffered($slug), $slug);
            $this->expectsValidation(fn () => $this->service()->record($user, $slug, $docs->meta($slug)['version'], 'registration'));
        }

        config(['legal.acceptance_enabled' => false]);
        $this->assertFalse($this->service()->isOffered('terms-of-service'));
        $this->expectsValidation(fn () => $this->service()->record($user, 'terms-of-service', $this->version(), 'registration'));
        $this->assertSame(0, LegalAcceptance::query()->count());
    }

    private function expectsValidation(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_registration_page_still_renders_with_legal_links(): void
    {
        $this->get('/register')->assertOk()
            ->assertSee(url('terms-of-service'), false)
            ->assertSee(url('privacy-policy'), false);
    }
}
