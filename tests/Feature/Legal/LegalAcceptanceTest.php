<?php

namespace Tests\Feature\Legal;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Legal\LegalAcceptanceRecorder;
use App\Library\Legal\LegalDocument;
use App\Models\LegalAcceptance;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\UserRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use LogicException;
use Tests\Feature\PlatformBilling\Concerns\CreatesPlatformSubscriptions;
use Tests\TestCase;

/**
 * Acceptance evidence: only published documents are ever presented or recorded, acceptance is affirmative and
 * version/hash-exact, rows are append-only and scoped to the accepting user and workspace.
 * Database backed (disposable test database only — see AGENTS.md).
 */
class LegalAcceptanceTest extends TestCase
{
    use CreatesPlatformSubscriptions;
    use RefreshDatabase;

    private string $tmp = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeStripe();
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
        config(['account.can_register' => true, 'legal.preview_drafts' => false]);
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '') {
            File::deleteDirectory($this->tmp);
        }
        parent::tearDown();
    }

    /** Publish the given slugs (clean fixture text) and point the signup bundles at them. */
    private function publish(array $slugs = ['terms-of-service', 'refund-policy', 'acceptable-use', 'agency-terms', 'privacy-policy']): void
    {
        $this->tmp = sys_get_temp_dir().'/legal-acc-'.uniqid();
        File::makeDirectory($this->tmp);
        $ledger = [];
        foreach ($slugs as $slug) {
            $source = "## 1. Agreement\n\n1.1. Fixture text for $slug.\n";
            File::put($this->tmp."/$slug.md", $source);
            $ledger[$slug.'@1.0-draft'] = LegalDocument::hashOf($source);
            config([
                "legal.documents.$slug.status" => 'approved',
                "legal.documents.$slug.approved_by" => 'Release owner',
                "legal.documents.$slug.approved_at" => '2026-11-01',
                "legal.documents.$slug.effective_date" => '2026-11-15',
            ]);
        }
        File::put($this->tmp.'/hashes.json', json_encode($ledger));
        config(['legal.path' => $this->tmp]);
    }

    private function user(): User
    {
        return app(UserRepository::class)->store([
            'first_name' => 'Pat', 'last_name' => 'Rivera', 'email' => 'user'.uniqid().'@example.test',
            'password' => 'correct-horse-battery', 'status' => true, 'phone' => null, 'is_customer' => true,
        ], true);
    }

    private function request(bool $accept, string $fingerprint, string $context = 'signup_checkout'): Request
    {
        return Request::create('/x', 'POST', [
            LegalAcceptanceRecorder::FIELD => $accept ? '1' : '',
            LegalAcceptanceRecorder::FINGERPRINT_FIELD => $fingerprint,
        ], [], [], ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'UnitTestBrowser/1.0']);
    }

    public function test_nothing_is_presented_or_recorded_while_documents_are_unpublished(): void
    {
        $recorder = app(LegalAcceptanceRecorder::class);

        $this->assertNull($recorder->present('signup_checkout'));
        $this->assertNull($recorder->satisfied('signup_checkout', $this->request(true, 'x')));
        $this->assertSame([], $recorder->record($this->user(), 'signup_checkout', $this->request(true, 'x')));
        $this->assertSame(0, LegalAcceptance::query()->count());
    }

    public function test_one_unpublished_document_in_the_bundle_withholds_the_whole_bundle(): void
    {
        $this->publish(['terms-of-service', 'refund-policy']); // acceptable-use is still a draft

        $this->assertNull(app(LegalAcceptanceRecorder::class)->present('signup_checkout'));
    }

    public function test_acceptance_is_affirmative_and_bound_to_the_exact_texts_shown(): void
    {
        $this->publish();
        $recorder = app(LegalAcceptanceRecorder::class);
        $bundle = $recorder->present('signup_checkout');

        $this->assertNotNull($bundle);
        $this->assertFalse($recorder->satisfied('signup_checkout', $this->request(false, $bundle['fingerprint'])), 'unticked');
        $this->assertFalse($recorder->satisfied('signup_checkout', $this->request(true, 'stale')), 'texts changed since shown');
        $this->assertTrue($recorder->satisfied('signup_checkout', $this->request(true, $bundle['fingerprint'])));
    }

    public function test_acceptance_records_user_workspace_version_hash_context_offer_and_time(): void
    {
        $this->publish();
        $recorder = app(LegalAcceptanceRecorder::class);
        $bundle = $recorder->present('private_offer');
        $user = $this->user();
        $workspace = Workspace::query()->create(['name' => 'Acme', 'owner_user_id' => $user->id, 'is_active' => true]);

        $rows = $recorder->record($user, 'private_offer', $this->request(true, $bundle['fingerprint']), $workspace->id, null, 'offer-uid-1');

        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame($user->id, $row->user_id);
            $this->assertSame($user->email, $row->user_email);
            $this->assertSame($workspace->id, $row->workspace_id);
            $this->assertSame('1.0-draft', $row->document_version);
            $this->assertSame(64, strlen($row->content_hash));
            $this->assertSame('private_offer', $row->context);
            $this->assertSame('offer-uid-1', $row->offer_uid);
            $this->assertSame('203.0.113.9', $row->ip_address);
            $this->assertSame('UnitTestBrowser/1.0', $row->user_agent);
            $this->assertNotNull($row->accepted_at);
        }
        $this->assertEqualsCanonicalizing(['terms-of-service', 'refund-policy', 'acceptable-use'], array_column($rows, 'document_slug'));
        $this->assertTrue($recorder->hasAccepted($user, 'private_offer', 'offer-uid-1'));
        $this->assertFalse($recorder->hasAccepted($user, 'private_offer', 'another-offer'));
        $this->assertFalse($recorder->hasAccepted($this->user(), 'private_offer', 'offer-uid-1'), 'another user never inherits acceptance');
    }

    public function test_recording_is_idempotent_and_agency_adds_the_agency_addendum(): void
    {
        $this->publish();
        $recorder = app(LegalAcceptanceRecorder::class);
        $user = $this->user();
        $bundle = $recorder->present('agency_signup_checkout');
        $request = $this->request(true, $bundle['fingerprint']);

        $recorder->record($user, 'agency_signup_checkout', $request);
        $recorder->record($user, 'agency_signup_checkout', $request);

        $this->assertSame(4, LegalAcceptance::query()->count());
        $this->assertSame(1, LegalAcceptance::query()->where('document_slug', 'agency-terms')->count());
    }

    public function test_a_new_version_does_not_count_as_accepted_and_old_evidence_is_preserved(): void
    {
        $this->publish();
        $recorder = app(LegalAcceptanceRecorder::class);
        $user = $this->user();
        $recorder->record($user, 'signup_checkout', $this->request(true, $recorder->present('signup_checkout')['fingerprint']));
        $this->assertTrue($recorder->hasAccepted($user, 'signup_checkout'));

        // The text of a published document changes under a new version.
        $source = "## 1. Agreement\n\n1.1. Materially new terms.\n";
        File::put($this->tmp.'/terms-of-service.md', $source);
        config(['legal.documents.terms-of-service.version' => '2.0']);
        File::put($this->tmp.'/hashes.json', json_encode(['terms-of-service@2.0' => LegalDocument::hashOf($source)] + json_decode(File::get($this->tmp.'/hashes.json'), true)));

        $this->assertFalse(app(LegalAcceptanceRecorder::class)->hasAccepted($user, 'signup_checkout'), 'existing users are not silently treated as having accepted v2');
        $this->assertSame(3, LegalAcceptance::query()->where('document_version', '1.0-draft')->count(), 'prior accepted versions are preserved');
    }

    public function test_evidence_is_append_only(): void
    {
        $this->publish();
        $recorder = app(LegalAcceptanceRecorder::class);
        $row = $recorder->record($this->user(), 'signup_checkout', $this->request(true, $recorder->present('signup_checkout')['fingerprint']))[0];

        try {
            $row->forceFill(['document_version' => 'forged'])->save();
            $this->fail('update must be refused');
        } catch (LogicException) {
        }
        try {
            $row->delete();
            $this->fail('delete must be refused');
        } catch (LogicException) {
        }
        $this->assertSame('1.0-draft', $row->fresh()->document_version);
    }

    // ------------------------------------------------------------- signup flow

    public function test_signup_is_unchanged_while_documents_are_unpublished(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth, price: '297.00');
        $this->walk($this->signupForm(), 3);

        $this->get(route('register.payment'))->assertOk()->assertDontSee('data-role="legal-acceptance"', false);
        $this->post(route('register.payment.start'))->assertRedirectContains('checkout.stripe.test');
        $this->assertSame(0, LegalAcceptance::query()->count());
    }

    public function test_published_documents_must_be_ticked_and_are_never_prechecked(): void
    {
        $this->publish();
        $this->sellableTier(WorkspacePlanTier::Growth, price: '297.00');
        $this->walk($this->signupForm(), 3);

        $html = $this->get(route('register.payment'))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="legal-acceptance"', $html);
        $this->assertStringContainsString('every 28 days', $html);
        $this->assertDoesNotMatchRegularExpression('/name="accept_legal"[^>]*checked/', $html);

        // Unticked: refused before anything is created.
        $this->post(route('register.payment.start'))->assertRedirect(route('register.payment'))->assertSessionHasErrors(LegalAcceptanceRecorder::FIELD);
        $this->assertSame(0, User::query()->where('email', 'like', 'pat%')->count());
        $this->assertSame(0, LegalAcceptance::query()->count());

        // Ticked against the shown texts: one evidence row per document, scoped to the new account and workspace.
        preg_match('/name="legal_fingerprint" value="([0-9a-f]{64})"/', $html, $m);
        $this->post(route('register.payment.start'), [LegalAcceptanceRecorder::FIELD => '1', LegalAcceptanceRecorder::FINGERPRINT_FIELD => $m[1]])
            ->assertRedirectContains('checkout.stripe.test');

        $user = User::query()->where('email', 'like', 'pat%')->sole();
        $rows = LegalAcceptance::query()->get();
        $this->assertCount(3, $rows);
        $this->assertSame([$user->id], $rows->pluck('user_id')->unique()->values()->all());
        $this->assertSame([Workspace::query()->where('owner_user_id', $user->id)->value('id')], $rows->pluck('workspace_id')->unique()->values()->all());
        $this->assertSame(['signup_checkout'], $rows->pluck('context')->unique()->values()->all());
    }

    /** @return array<string, string> */
    private function signupForm(): array
    {
        return [
            'first_name' => 'Pat', 'last_name' => 'Rivera', 'email' => 'pat'.uniqid().'@example.test',
            'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery',
            'business_name' => 'Harbor Lane Studios', 'industry' => 'photo_booth_service', 'country_code' => 'US',
            'timezone' => 'UTC', 'currency_code' => 'USD', 'tier' => 'growth',
        ];
    }
}
