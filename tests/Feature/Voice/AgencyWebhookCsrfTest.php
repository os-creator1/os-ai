<?php

namespace Tests\Feature\Voice;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AgencyOutreach\Concerns\BuildsAgencyByoChannels;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Provider webhooks carry no browser session, so they cannot carry a CSRF token; their authenticity is the
 * Ed25519 signature (and the channel token in the URL). These tests switch Laravel's CSRF check ON (it is
 * skipped under the test environment) and prove that: a correctly signed Agency Telnyx webhook is accepted
 * without a token, a forged one is rejected by the signature (403, not by CSRF), and a normal session POST
 * without a token is still refused with 419 — so only the webhook paths were opened.
 */
class AgencyWebhookCsrfTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;
    use BuildsAgencyByoChannels;

    private function enableCsrf(): void
    {
        $this->app['env'] = 'local'; // VerifyCsrfToken skips only while the app environment is "testing"
    }

    private function channel(): \App\Models\AgencyProspectingChannel
    {
        User::create([
            'first_name' => 'Placeholder', 'last_name' => 'SuperAdmin', 'email' => 'p' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Agency Co', 'Agency WS');

        return $this->byoChannel($workspace->fresh(), ['sender' => '12025550142']);
    }

    public function test_a_signed_agency_sms_webhook_succeeds_without_a_csrf_token(): void
    {
        $channel = $this->channel();
        $this->enableCsrf();

        $response = $this->signedWebhook($channel, $this->receivedPayload($channel, 'hello', 'in-csrf-1'));

        $this->assertNotSame(419, $response->getStatusCode(), 'The existing Agency BYO SMS webhook must not be blocked by session CSRF.');
        $this->assertNotSame(403, $response->getStatusCode(), 'A correctly signed webhook is authenticated.');
    }

    public function test_a_forged_agency_sms_webhook_is_rejected_by_its_signature_not_by_csrf(): void
    {
        $channel = $this->channel();
        $this->enableCsrf();
        $forger = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());

        $this->signedWebhook($channel, $this->receivedPayload($channel, 'hello', 'in-csrf-2'), $forger)->assertStatus(403);
    }

    public function test_an_ordinary_session_post_without_a_token_is_still_refused(): void
    {
        $this->channel();
        $this->enableCsrf();

        $this->post('/login', ['email' => 'x@example.test', 'password' => 'x'])->assertStatus(419);
    }
}
