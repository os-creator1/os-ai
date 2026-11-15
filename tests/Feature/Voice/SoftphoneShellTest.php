<?php

namespace Tests\Feature\Voice;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Http\Middleware\SoftphoneFrame;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Call continuity across navigation: an eligible user's top-level page request is answered with the shell that owns
 * the softphone; the page itself is then served to the shell's frame without a second softphone, and everything
 * else is served exactly as before.
 */
class SoftphoneShellTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use CreatesMessagingFixtures;
    use RefreshDatabase;

    private $customer;
    private $business;
    private $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'messaging.voice.customer_calls.enabled' => true,
            'messaging.voice.browser.enabled' => true,
            'messaging.voice.browser.credential_connection_id' => 'conn_webrtc_1',
            'messaging.voice.connection_id' => 'conn_cc_1',
            'messaging.voice.webhook_url' => 'https://app.example.test/webhooks/telnyx-voice',
            'services.telnyx.mode' => 'live',
            'services.telnyx.api_key' => 'fixture_api_key_not_a_real_credential',
            'services.telnyx.webhook_public_key' => base64_encode(str_repeat('k', 32)),
        ]);

        [$this->customer, $this->business, $this->workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($this->customer);
    }

    private function url(): string
    {
        return route('customer.workspaces.businesses.people.index', [$this->workspace->uid, $this->business->uid]);
    }

    private function navigate(string $dest = 'document', string $mode = 'navigate', ?string $url = null)
    {
        return $this->withHeaders(['Sec-Fetch-Dest' => $dest, 'Sec-Fetch-Mode' => $mode])->get($url ?? $this->url());
    }

    public function test_a_top_level_page_request_gets_the_shell_with_the_softphone_and_the_page_in_a_frame(): void
    {
        $response = $this->navigate()->assertOk();
        $response->assertSee('id="mg-app-frame"', false)->assertSee('id="mg-softphone"', false)->assertSee('mg-softphone.js', false);
        $response->assertSee('src="' . e('/workspaces/' . $this->workspace->uid . '/businesses/' . $this->business->uid . '/people') . '"', false);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('Sec-Fetch-Dest', (string) $response->headers->get('Vary'));
        $response->assertDontSee('Add contact');
    }

    public function test_the_framed_page_has_the_bridge_but_never_a_second_softphone(): void
    {
        $page = $this->navigate('iframe')->assertOk();
        $page->assertSee('mg-frame-bridge.js', false)->assertDontSee('id="mg-softphone"', false)->assertDontSee('mg-softphone.js', false);
        $page->assertSee('Add contact');
    }

    public function test_requests_that_are_not_top_level_navigations_are_served_as_before(): void
    {
        foreach ([[null, null], ['empty', 'cors'], ['document', 'same-origin']] as [$dest, $mode]) {
            $headers = array_filter(['Sec-Fetch-Dest' => $dest, 'Sec-Fetch-Mode' => $mode]);
            $this->withHeaders($headers)->get($this->url())->assertOk()->assertDontSee('id="mg-app-frame"', false)->assertSee('Add contact');
        }

        $this->withHeaders(['Sec-Fetch-Dest' => 'document', 'Sec-Fetch-Mode' => 'navigate', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->get($this->url())->assertDontSee('id="mg-app-frame"', false);
        $this->navigate(url: $this->url() . '?mgframe=0')->assertDontSee('id="mg-app-frame"', false);
    }

    public function test_a_user_who_cannot_call_gets_the_normal_page_not_a_shell(): void
    {
        \DB::table('workspaces')->where('id', $this->workspace->id)->update(['owner_user_id' => $this->platformAdminId()]);
        $this->authenticateAs($this->customer, ['view_contact', 'view_contact_group', 'create_contact']);

        $this->navigate()->assertDontSee('id="mg-app-frame"', false);
    }

    public function test_when_calling_is_switched_off_there_is_no_shell(): void
    {
        config(['messaging.voice.customer_calls.enabled' => false]);

        $this->navigate()->assertOk()->assertDontSee('id="mg-app-frame"', false);
    }

    public function test_another_businesss_url_is_never_wrapped_in_a_shell_and_stays_a_404(): void
    {
        [, $other, $otherWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Other Biz', 'Other WS');

        $this->navigate(url: route('customer.workspaces.businesses.people.index', [$otherWorkspace->uid, $other->uid]))->assertNotFound();
    }

    public function test_a_framed_redirect_that_leaves_the_app_breaks_out_of_the_frame_but_in_app_redirects_stay(): void
    {
        $middleware = app(SoftphoneFrame::class);
        $framed = fn (string $target) => $middleware->handle(
            tap(Request::create('/workspaces/w/businesses/b/email', 'POST'), fn ($r) => $r->headers->set('Sec-Fetch-Dest', 'iframe')),
            fn () => new RedirectResponse($target),
        );

        $out = $framed('https://accounts.google.com/o/oauth2/v2/auth?client_id=x&state=y');
        $this->assertSame(200, $out->getStatusCode());
        $this->assertStringContainsString('window.top.location.href = "https://accounts.google.com/o/oauth2/v2/auth?client_id=x', $out->getContent());
        $this->assertStringNotContainsString('client_id=x&state', $out->getContent(), 'The address is emitted as a safely escaped JavaScript string.');

        $login = $framed('http://localhost/login');
        $this->assertStringContainsString('window.top.location.href', $login->getContent(), 'An expired session shows the login page at the top level, not inside the frame.');

        $stay = $framed('/workspaces/w/businesses/b/people');
        $this->assertTrue($stay->isRedirection());
    }
}
