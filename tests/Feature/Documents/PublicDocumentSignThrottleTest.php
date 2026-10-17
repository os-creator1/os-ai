<?php

namespace Tests\Feature\Documents;

use App\Models\BusinessDocumentSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\Support\Documents\ShownVersion;
use Tests\TestCase;

/**
 * Public signing is throttled per document+visitor, per visitor across all
 * documents, and per document — in its OWN bucket, no longer the shared
 * per-IP counter every `throttle:N,1` route drew from.
 */
class PublicDocumentSignThrottleTest extends TestCase
{
    use RefreshDatabase;
    use SendsDocuments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->allowPublicEntitlement();
    }

    /**
     * The throttle must refuse with HTTP 429, Retry-After and the rate-limit
     * headers. It is asserted at the exception the middleware throws, because
     * how that exception is RENDERED to a browser belongs to the separate
     * universal error-page work: app/Exceptions/Handler.php currently turns
     * every HttpException (429 included) into the 404 view for HTML requests.
     */
    private function assertThrottled(callable $request): void
    {
        $this->withoutExceptionHandling();

        try {
            $request();
            $this->fail('Expected the request to be throttled.');
        } catch (\Illuminate\Http\Exceptions\ThrottleRequestsException $e) {
            $this->assertSame(429, $e->getStatusCode());
            $this->assertArrayHasKey('Retry-After', $e->getHeaders());
            $this->assertArrayHasKey('X-RateLimit-Limit', $e->getHeaders());
            $this->assertSame(0, (int) $e->getHeaders()['X-RateLimit-Remaining']);
        } finally {
            $this->withExceptionHandling();
        }
    }

    /** @return array{0: \App\Models\BusinessDocument, 1: string} */
    private function sendable(): array
    {
        return $this->sendAndCaptureToken($this->draftDocument($this->sendableTenant()));
    }

    private function payload($document, string $name = 'Pat Rivera'): array
    {
        return ['displayed_version_uid' => ShownVersion::uid($document), 'signer_name' => $name, 'signer_email' => 'pat@example.test', 'typed_name' => $name];
    }

    public function test_normal_signing_works_and_the_form_blocks_double_submission(): void
    {
        [$document, $token] = $this->sendable();

        $this->get($this->publicUrl($document, $token))->assertOk()
            ->assertSee('data-role="sign-button"', false)
            ->assertSee('Signing', false)
            ->assertSee('submitting', false);

        $this->post($this->signUrl($document, $token), $this->payload($document))->assertOk()->assertSee('Signature recorded');
        $this->assertSame(1, BusinessDocumentSignature::query()->count());
    }

    public function test_views_and_other_public_traffic_from_the_same_ip_no_longer_spend_the_signing_allowance(): void
    {
        [$document, $token] = $this->sendable();

        // Previously every throttle:N,1 route shared one per-IP counter, so
        // this much ordinary traffic exhausted a signer's 10.
        for ($i = 0; $i < 25; $i++) {
            $this->get($this->publicUrl($document, $token))->assertOk();
        }

        $this->post($this->signUrl($document, $token), $this->payload($document))->assertOk()->assertSee('Signature recorded');
    }

    public function test_distinct_proposals_from_one_ip_do_not_interfere(): void
    {
        $docs = [$this->sendable(), $this->sendable(), $this->sendable()];

        foreach ($docs as [$document, $token]) {
            for ($i = 0; $i < 4; $i++) {
                $this->post($this->signUrl($document, $token), ['signer_name' => ''])->assertStatus(422);
            }
        }

        foreach ($docs as [$document, $token]) {
            $this->post($this->signUrl($document, $token), $this->payload($document))->assertOk()->assertSee('Signature recorded');
        }

        $this->assertSame(3, BusinessDocumentSignature::query()->count());
    }

    public function test_excessive_attempts_against_one_document_are_throttled_with_429_and_headers(): void
    {
        [$document, $token] = $this->sendable();

        for ($i = 0; $i < 10; $i++) {
            $this->post($this->signUrl($document, $token), ['signer_name' => ''])->assertStatus(422);
        }

        $this->assertThrottled(fn () => $this->post($this->signUrl($document, $token), ['signer_name' => '']));

        // A different visitor is unaffected.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->post($this->signUrl($document, $token), $this->payload($document))->assertOk();
    }

    public function test_the_visitor_ceiling_holds_when_the_document_identifier_is_rotated(): void
    {
        $token = Str::random(64);

        for ($i = 0; $i < 60; $i++) {
            $this->post(route('public.documents.sign', ['uid' => 'doc-' . $i, 'token' => $token]), [])->assertNotFound();
        }

        $this->assertThrottled(fn () => $this->post(route('public.documents.sign', ['uid' => 'doc-final', 'token' => $token]), []));
    }

    public function test_rate_limit_keys_never_contain_the_bearer_token_or_the_raw_uid(): void
    {
        [$document, $token] = $this->sendable();

        $route = Route::getRoutes()->getByName('public.documents.sign');
        $request = Request::create($this->signUrl($document, $token), 'POST');
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        $limits = (RateLimiter::limiter('public-document-sign'))($request);
        $this->assertCount(3, $limits, 'document+visitor, visitor and document bounds.');

        foreach ($limits as $limit) {
            $this->assertStringNotContainsString($token, $limit->key);
            $this->assertStringNotContainsString((string) $document->uid, $limit->key);
        }
    }

    public function test_duplicate_submissions_produce_one_signature(): void
    {
        [$document, $token] = $this->sendable();
        $payload = $this->payload($document);

        $this->post($this->signUrl($document, $token), $payload)->assertOk();
        $this->post($this->signUrl($document, $token), $payload)->assertOk()->assertSee('Signature recorded');

        $this->assertSame(1, BusinessDocumentSignature::query()->count());
    }

    public function test_invalid_expired_and_foreign_links_keep_their_protection(): void
    {
        [$document, $token] = $this->sendable();
        [$other, $otherToken] = $this->sendable();

        $this->post($this->signUrl($document, 'not-the-token'), $this->payload($document))->assertNotFound();
        $this->post($this->signUrl($document, $otherToken), $this->payload($document))->assertNotFound();
        $this->post($this->signUrl($other, $token), $this->payload($other))->assertNotFound();

        \DB::table('business_documents')->where('id', $document->id)->update(['access_token_expires_at' => now()->subMinute()]);
        $this->post($this->signUrl($document, $token), $this->payload($document))->assertNotFound();

        $this->assertSame(0, BusinessDocumentSignature::query()->count());
    }

    public function test_consent_and_version_validation_are_intact(): void
    {
        [$document, $token] = $this->sendable();

        $this->post($this->signUrl($document, $token), ['signer_name' => 'Pat', 'signer_email' => 'x', 'typed_name' => ''])->assertStatus(422);

        $stale = $this->payload($document);
        $stale['displayed_version_uid'] = 'not-the-current-version';
        $this->post($this->signUrl($document, $token), $stale)->assertStatus(422)->assertSee('updated after you opened it');

        $this->assertSame(0, BusinessDocumentSignature::query()->count());

        $this->post($this->signUrl($document, $token), $this->payload($document))->assertOk();
        $this->assertSame(\App\Library\Documents\DocumentManager::CONSENT_STATEMENT, BusinessDocumentSignature::query()->sole()->consent_statement);
    }
}
