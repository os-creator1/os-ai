<?php

namespace Tests\Feature\Documents;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Library\Documents\DocumentManager;
use App\Library\Entitlement\EntitlementManager;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\TestCase;

/**
 * Implementation Contract 17 §6.3 / §6.3.1 / §12.C — THE ADVERSARIAL PUBLIC
 * SURFACE. This is the point of Sub-slice C: an unauthenticated, high-value
 * endpoint where a signature bound to mutable content, or a refusal that
 * leaks which thing went wrong, would be worthless.
 */
class PublicDocumentLinkTest extends TestCase
{
    use RefreshDatabase;
    use SendsDocuments;

    /** The canonical refusal every failure must match byte for byte. */
    private function baselineRefusal(): TestResponse
    {
        return $this->get(route('public.documents.show', ['uid' => 'no-such-document', 'token' => Str::random(64)]));
    }

    private function assertUniformRefusal(TestResponse $response, string $context): void
    {
        $baseline = $this->baselineRefusal();

        $response->assertNotFound();
        $this->assertSame(
            $baseline->getContent(),
            $response->getContent(),
            "[{$context}] must return the byte-identical uniform refusal."
        );
    }

    // -----------------------------------------------------------------
    // The happy path, so the refusals below mean something
    // -----------------------------------------------------------------

    public function test_a_valid_link_renders_the_frozen_issued_version(): void
    {
        $this->allowPublicEntitlement();
        $tenant = $this->sendableTenant();
        [$document, $token] = $this->sendAndCaptureToken($this->draftDocument($tenant));

        $html = $this->get($this->publicUrl($document, $token))->assertOk()->getContent();

        $this->assertStringContainsString('Kitchen renovation proposal', $html);
        $this->assertStringContainsString('Design work', $html);
        $this->assertStringContainsString('data-role="sign-form"', $html);
        $this->assertStringContainsString(DocumentManager::CONSENT_STATEMENT, $html);
        // No other tenant's data, no list, no search.
        $this->assertStringNotContainsString('client@example.test', $html);
    }

    // -----------------------------------------------------------------
    // §12.C — the byte-identical refusal set
    // -----------------------------------------------------------------

    public function test_an_unknown_uid_is_a_404_not_a_500(): void
    {
        $this->allowPublicEntitlement();

        $response = $this->get(route('public.documents.show', ['uid' => 'does-not-exist', 'token' => Str::random(64)]));

        $response->assertNotFound();
        $this->assertStringNotContainsString('Exception', (string) $response->getContent());
    }

    public function test_a_wrong_token_is_refused_uniformly(): void
    {
        $this->allowPublicEntitlement();
        $tenant = $this->sendableTenant();
        [$document] = $this->sendAndCaptureToken($this->draftDocument($tenant));

        $this->assertUniformRefusal($this->get($this->publicUrl($document, Str::random(64))), 'wrong token');
    }

    public function test_an_expired_link_is_refused_uniformly(): void
    {
        $this->allowPublicEntitlement();
        $tenant = $this->sendableTenant();
        [$document, $token] = $this->sendAndCaptureToken($this->draftDocument($tenant));
        DB::table('business_documents')->where('id', $document->id)->update(['access_token_expires_at' => now()->subMinute()]);

        $this->assertUniformRefusal($this->get($this->publicUrl($document, $token)), 'expired link');
    }

    public function test_a_rotated_old_token_is_refused_uniformly(): void
    {
        $this->allowPublicEntitlement();
        $tenant = $this->sendableTenant();
        [$document, $first] = $this->sendAndCaptureToken($this->draftDocument($tenant));

        app(DocumentManager::class)->revise($document, $tenant['customer']->user);
        [$document, $second] = $this->sendAndCaptureToken($document->refresh());

        $this->assertUniformRefusal($this->get($this->publicUrl($document, $first)), 'rotated token');
        $this->get($this->publicUrl($document, $second))->assertOk();
    }

    public function test_a_valid_token_for_a_different_document_is_refused_uniformly(): void
    {
        $this->allowPublicEntitlement();
        $tenant = $this->sendableTenant();
        [$mine, $myToken] = $this->sendAndCaptureToken($this->draftDocument($tenant, ['title' => 'Mine']));
        [$other, $otherToken] = $this->sendAndCaptureToken($this->draftDocument($tenant, ['title' => 'Other']));

        $this->assertUniformRefusal($this->get($this->publicUrl($mine, $otherToken)), 'cross-document token');
        $this->assertUniformRefusal($this->get($this->publicUrl($other, $myToken)), 'cross-document token (reverse)');
    }

    public function test_a_voided_document_is_refused_uniformly_and_its_link_dies_immediately(): void
    {
        $this->allowPublicEntitlement();
        $tenant = $this->sendableTenant();
        [$document, $token] = $this->sendAndCaptureToken($this->draftDocument($tenant));

        app(DocumentManager::class)->void($document, 'Customer changed their mind.');

        $this->assertUniformRefusal($this->get($this->publicUrl($document, $token)), 'voided document');
        $this->assertNull($document->refresh()->access_token_hash);
    }

    public function test_a_draft_document_has_no_public_surface_at_all(): void
    {
        $this->allowPublicEntitlement();
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant);

        $this->assertUniformRefusal($this->get($this->publicUrl($document, Str::random(64))), 'never-sent draft');
    }

    // -----------------------------------------------------------------
    // §6.3.1 — the link is NOT an account or entitlement bypass
    // -----------------------------------------------------------------

    public function test_an_unentitled_account_gets_the_same_refusal_as_a_bad_token(): void
    {
        // Deliberately NO allowPublicEntitlement(): Payments & Contracts is
        // `Planned` until Sub-slice G, so this is the real production answer.
        $tenant = $this->sendableTenant();
        [$document, $token] = $this->sendAndCaptureToken($this->draftDocument($tenant));

        $this->assertUniformRefusal($this->get($this->publicUrl($document, $token)), 'unentitled account');
    }

    public function test_a_suspended_account_gets_the_same_refusal(): void
    {
        $this->allowPublicEntitlement();
        $tenant = $this->sendableTenant();
        [$document, $token] = $this->sendAndCaptureToken($this->draftDocument($tenant));

        $this->get($this->publicUrl($document, $token))->assertOk();

        app(EntitlementManager::class)->changePlanStatus(
            $tenant['workspace'],
            WorkspacePlanAssignmentStatus::Suspended,
            $this->platformAdminId(),
            'Non-payment.',
        );

        $this->assertUniformRefusal($this->get($this->publicUrl($document, $token)), 'suspended account');
    }

    public function test_an_inactive_workspace_gets_the_same_refusal(): void
    {
        $this->allowPublicEntitlement();
        $tenant = $this->sendableTenant();
        [$document, $token] = $this->sendAndCaptureToken($this->draftDocument($tenant));

        DB::table('workspaces')->where('id', $tenant['workspace']->id)->update(['is_active' => false]);

        $this->assertUniformRefusal($this->get($this->publicUrl($document, $token)), 'inactive workspace');
    }

    public function test_an_archived_location_gets_the_same_refusal(): void
    {
        $this->allowPublicEntitlement();
        $tenant = $this->sendableTenant();
        [$document, $token] = $this->sendAndCaptureToken($this->draftDocument($tenant));

        DB::table('business_locations')->where('id', $tenant['location']->id)->update([
            'lifecycle_state' => BusinessLocationLifecycleState::Archived->value,
            'archived_at' => now(),
        ]);

        $this->assertUniformRefusal($this->get($this->publicUrl($document, $token)), 'archived Location');
    }

    public function test_the_guard_reruns_every_recheck_on_the_sign_post_too(): void
    {
        $this->allowPublicEntitlement();
        $tenant = $this->sendableTenant();
        [$document, $token] = $this->sendAndCaptureToken($this->draftDocument($tenant));

        DB::table('workspaces')->where('id', $tenant['workspace']->id)->update(['is_active' => false]);

        $response = $this->post($this->signUrl($document, $token), [
            'signer_name' => 'Pat Rivera', 'signer_email' => 'pat@example.test', 'typed_name' => 'Pat Rivera',
        ]);

        $response->assertNotFound();
        $this->assertSame(0, BusinessDocumentSignature::query()->count());
    }

    // -----------------------------------------------------------------
    // §6.3 / §10 — the GET is side-effect-free
    // -----------------------------------------------------------------

    public function test_the_public_get_writes_absolutely_nothing(): void
    {
        $this->allowPublicEntitlement();
        $tenant = $this->sendableTenant();
        [$document, $token] = $this->sendAndCaptureToken($this->draftDocument($tenant));

        $tables = ['business_documents', 'business_document_versions', 'business_document_line_items',
            'business_document_payment_schedule_items', 'business_document_signatures'];
        $before = $this->fingerprint($tables);

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)\s/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $this->get($this->publicUrl($document, $token))->assertOk();
        $this->get($this->publicUrl($document, $token))->assertOk();

        $this->assertSame([], $writes, 'The public GET must issue no write statement at all.');
        $this->assertSame($before, $this->fingerprint($tables));
    }

    public function test_there_is_no_view_tracking_column_or_event(): void
    {
        // §10 — DocumentViewed and last_viewed_at are deliberately not in V1.
        $this->assertFalse(\Schema::hasColumn('business_documents', 'last_viewed_at'));
        $this->assertFalse(class_exists('App\\Events\\DocumentViewed'));
    }

    // -----------------------------------------------------------------
    // §6.3 deltas — throttling, and no plaintext anywhere
    // -----------------------------------------------------------------

    public function test_every_public_document_route_is_throttled_and_carries_no_model_binding(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'public.documents.'));

        $this->assertCount(2, $routes, 'Exactly the view and the sign routes exist — no payment route in Sub-slice C.');

        foreach ($routes as $route) {
            $throttles = array_values(array_filter($route->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')));
            $this->assertCount(1, $throttles, "[{$route->getName()}] must carry exactly one throttle.");

            // No implicit route-model binding, so an unresolved binding can
            // never render a 500 the way the opt-in route does.
            $this->assertSame([], $route->signatureParameters(['subClass' => \Illuminate\Database\Eloquent\Model::class]));
        }
    }

    public function test_the_plaintext_token_is_never_written_to_the_log(): void
    {
        $this->allowPublicEntitlement();
        $tenant = $this->sendableTenant();
        [$document, $token] = $this->sendAndCaptureToken($this->draftDocument($tenant));

        $lines = [];
        Log::listen(function ($message) use (&$lines) {
            $lines[] = $message->message . ' ' . json_encode($message->context);
        });

        $this->get($this->publicUrl($document, $token))->assertOk();
        $this->get($this->publicUrl($document, Str::random(64)))->assertNotFound();
        $this->get(route('public.documents.show', ['uid' => 'nope', 'token' => $token]))->assertNotFound();

        foreach ($lines as $line) {
            $this->assertStringNotContainsString($token, $line, 'The plaintext token must never be logged.');
        }
    }

    public function test_the_refusal_page_discloses_nothing_about_the_document_or_business(): void
    {
        $this->allowPublicEntitlement();
        $tenant = $this->sendableTenant();
        [$document] = $this->sendAndCaptureToken($this->draftDocument($tenant));

        $body = (string) $this->get($this->publicUrl($document, Str::random(64)))->getContent();

        foreach ([$document->uid, 'Kitchen renovation proposal', 'Harbor Lane', 'client@example.test', 'Design work'] as $secret) {
            $this->assertStringNotContainsString((string) $secret, $body);
        }
    }

    // -----------------------------------------------------------------
    // Signing over HTTP
    // -----------------------------------------------------------------

    public function test_the_customer_can_sign_through_the_link_and_only_once(): void
    {
        $this->allowPublicEntitlement();
        $tenant = $this->sendableTenant();
        [$document, $token] = $this->sendAndCaptureToken($this->draftDocument($tenant));

        $payload = ['signer_name' => 'Pat Rivera', 'signer_email' => 'pat@example.test', 'typed_name' => 'Pat Rivera'];

        $this->post($this->signUrl($document, $token), $payload)->assertOk()->assertSee('Signature recorded');

        $signature = BusinessDocumentSignature::query()->sole();
        $this->assertSame((int) $document->refresh()->current_version_id, (int) $signature->business_document_version_id);
        // The request's own IP and user agent are recorded as evidence —
        // taken from the server's view of the request, never from a field
        // the signer could set.
        $this->assertNotNull($signature->ip_address);
        $this->assertNotSame('', $signature->ip_address);
        $this->assertSame('Pat Rivera', $signature->typed_name);

        // The second attempt is a clean refusal, not a second row.
        $this->post($this->signUrl($document, $token), $payload)->assertNotFound();
        $this->assertSame(1, BusinessDocumentSignature::query()->count());
    }

    public function test_the_sign_endpoint_accepts_no_card_data_and_no_consent_text(): void
    {
        $this->allowPublicEntitlement();
        $tenant = $this->sendableTenant();
        [$document, $token] = $this->sendAndCaptureToken($this->draftDocument($tenant));

        $this->post($this->signUrl($document, $token), [
            'signer_name' => 'Pat Rivera',
            'signer_email' => 'pat@example.test',
            'typed_name' => 'Pat Rivera',
            // All ignored by a closed request schema.
            'consent_statement' => 'I agree to nothing at all.',
            'card_number' => '4242424242424242',
            'cvc' => '123',
        ])->assertOk();

        $signature = BusinessDocumentSignature::query()->sole();
        $this->assertSame(DocumentManager::CONSENT_STATEMENT, $signature->consent_statement);

        foreach ((array) DB::table('business_document_signatures')->first() as $value) {
            $this->assertStringNotContainsString('4242424242424242', (string) $value);
            $this->assertStringNotContainsString('I agree to nothing', (string) $value);
        }
    }

    public function test_invalid_signature_input_redisplays_the_document_without_signing(): void
    {
        $this->allowPublicEntitlement();
        $tenant = $this->sendableTenant();
        [$document, $token] = $this->sendAndCaptureToken($this->draftDocument($tenant));

        $this->post($this->signUrl($document, $token), ['signer_name' => '', 'signer_email' => 'nope', 'typed_name' => ''])
            ->assertStatus(422)
            ->assertSee('Kitchen renovation proposal');

        $this->assertSame(0, BusinessDocumentSignature::query()->count());
    }

    public function test_an_invoice_offers_no_signing_form(): void
    {
        $this->allowPublicEntitlement();
        $tenant = $this->sendableTenant();
        [$document, $token] = $this->sendAndCaptureToken($this->draftDocument($tenant, ['kind' => 'invoice']));

        $html = $this->get($this->publicUrl($document, $token))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-role="sign-form"', $html);

        $this->post($this->signUrl($document, $token), [
            'signer_name' => 'Pat', 'signer_email' => 'p@example.test', 'typed_name' => 'Pat',
        ])->assertNotFound();
        $this->assertSame(0, BusinessDocumentSignature::query()->count());
    }

    public function test_the_public_page_makes_no_legal_sufficiency_claim(): void
    {
        $this->allowPublicEntitlement();
        $tenant = $this->sendableTenant();
        [$document, $token] = $this->sendAndCaptureToken($this->draftDocument($tenant));

        $html = strtolower((string) $this->get($this->publicUrl($document, $token))->getContent());

        foreach (['legally binding', 'legally sufficient', 'qualified signature', 'advanced signature',
            'identity verified', 'identity-verified', 'notarized', 'court'] as $claim) {
            $this->assertStringNotContainsString($claim, $html, "§6.5 forbids claiming [{$claim}].");
        }
    }
}
