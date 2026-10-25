<?php

namespace Tests\Feature\Forms;

use App\Enums\Forms\FormDeploymentSource;
use App\Enums\Website\WebsiteDomainStatus;
use App\Enums\Website\WebsiteStatus;
use App\Http\Middleware\VerifyCsrfToken;
use App\Library\Forms\Embed\FormWebsiteEmbed;
use App\Library\Forms\FormManager;
use App\Models\Business;
use App\Models\Website;
use App\Models\WebsiteRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\TestCase;

/**
 * A Website-source form is framed by the customer's own domain, and by nobody else: the public form routes answer
 * `frame-ancestors 'self'` plus the exact Active custom-domain origins of the owning Business's published Website.
 */
class FormEmbedFramingTest extends TestCase
{
    use CreatesFormsFixtures;
    use RefreshDatabase;

    private Business $business;

    private $location;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        [$owner, $this->business] = $this->formsTenant();
        $this->userId = (int) $owner->user_id;
        $this->location = $this->formsLocation($this->business, 'Downtown');
        $this->formsPipeline($this->business);
    }

    private function websiteWithDomain(Business $business, string $domain, WebsiteDomainStatus $status = WebsiteDomainStatus::Active, bool $published = true): Website
    {
        $website = Website::create(['business_id' => $business->id, 'name' => 'Site', 'status' => WebsiteStatus::Draft]);

        if ($published) {
            $revision = WebsiteRevision::create(['website_id' => $website->id, 'version_number' => 1, 'snapshot' => ['pages' => []], 'schema_version' => 1, 'created_by' => $this->userId]);
            $website->forceFill(['published_revision_id' => $revision->id])->save();
        }

        $website->domains()->create([
            'domain' => $domain, 'is_primary' => true, 'status' => $status, 'verification_token' => 'token',
            'verified_at' => now(), 'activated_at' => now(),
        ]);

        return $website;
    }

    private function offered(Business $business, $location, FormDeploymentSource $source = FormDeploymentSource::Website)
    {
        $form = $this->makeForm($business, ['name' => 'Quote request'], true);

        return app(FormManager::class)->setDeployment($business, $form, $location, true, $source);
    }

    public function test_the_customers_active_domain_may_frame_its_website_form_and_x_frame_options_is_not_sent(): void
    {
        $reference = $this->offered($this->business, $this->location);
        $this->websiteWithDomain($this->business, 'Quotes.Example.com');

        $response = $this->get(route('public.forms.show', [$reference->uid]))->assertOk();

        $this->assertSame("frame-ancestors 'self' https://quotes.example.com", $response->headers->get('Content-Security-Policy'));
        $this->assertNull($response->headers->get('X-Frame-Options'));
        $this->assertStringNotContainsString('*', (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_the_thank_you_page_and_a_refused_post_carry_the_same_policy(): void
    {
        $reference = $this->offered($this->business, $this->location);
        $this->websiteWithDomain($this->business, 'quotes.example.com');
        $policy = "frame-ancestors 'self' https://quotes.example.com";

        $this->get(route('public.forms.thanks', [$reference->uid]))->assertOk()->assertHeader('Content-Security-Policy', $policy);
        $this->post(route('public.forms.submit', [$reference->uid]), [])->assertHeader('Content-Security-Policy', $policy);
    }

    public function test_an_unverified_domain_and_another_businesses_domain_are_never_authorised(): void
    {
        $reference = $this->offered($this->business, $this->location);
        $this->websiteWithDomain($this->business, 'pending.example.com', WebsiteDomainStatus::PendingVerification);

        [, $other] = $this->formsTenant(\App\Enums\Entitlement\WorkspacePlanTier::Core, 'Other Business');
        $this->websiteWithDomain($other, 'attacker.example.net');

        $this->get(route('public.forms.show', [$reference->uid]))->assertOk()->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
    }

    public function test_an_unpublished_site_is_not_authorised(): void
    {
        $reference = $this->offered($this->business, $this->location);
        $this->websiteWithDomain($this->business, 'draft.example.com', WebsiteDomainStatus::Active, false);

        $this->get(route('public.forms.show', [$reference->uid]))->assertOk()->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
    }

    public function test_a_direct_link_form_is_not_framable_by_the_website(): void
    {
        $reference = $this->offered($this->business, $this->location, FormDeploymentSource::DirectLink);
        $this->websiteWithDomain($this->business, 'quotes.example.com');

        $this->get(route('public.forms.show', [$reference->uid]))->assertOk()->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
    }

    public function test_an_unknown_form_link_is_still_a_404_and_framable_by_nobody(): void
    {
        $this->websiteWithDomain($this->business, 'quotes.example.com');

        $this->get(route('public.forms.show', ['11111111-1111-4111-8111-111111111111']))
            ->assertNotFound()->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
    }

    public function test_the_customers_domain_is_not_authorised_on_any_other_platform_surface(): void
    {
        $this->websiteWithDomain($this->business, 'quotes.example.com');

        $login = $this->get('/login');

        $this->assertStringNotContainsString('quotes.example.com', (string) $login->headers->get('Content-Security-Policy'));
    }

    public function test_the_embed_carries_a_new_tab_fallback_link_to_the_saved_deployment(): void
    {
        $reference = $this->offered($this->business, $this->location);
        $embed = app(FormWebsiteEmbed::class)->resolve($this->business, $reference->uid);

        $html = view('public.forms._embed', ['embed' => $embed])->render();
        $url = route('public.forms.show', [$reference->uid]);

        $this->assertStringContainsString('<iframe src="'.$url.'"', $html);
        $this->assertStringContainsString('Having trouble viewing the form? <a href="'.$url.'" target="_blank" rel="noopener">Open it here.</a>', $html);
        $this->assertSame(2, substr_count($html, $url));
    }

    public function test_only_the_public_form_paths_are_exempt_from_csrf(): void
    {
        $middleware = new class(app(), app('encrypter')) extends VerifyCsrfToken {
            public function exempt(Request $request): bool
            {
                return $this->inExceptArray($request);
            }
        };

        $uid = '11111111-1111-4111-8111-111111111111';

        $this->assertTrue($middleware->exempt(Request::create('/forms/'.$uid, 'POST')));
        $this->assertFalse($middleware->exempt(Request::create('/forms/'.$uid.'/thanks', 'POST')), 'nested paths stay protected');
        $this->assertFalse($middleware->exempt(Request::create('/forms/'.$uid.'/s/token/page_1', 'POST')));
        $this->assertFalse($middleware->exempt(Request::create('/forms/not-a-uuid', 'POST')));
        $this->assertFalse($middleware->exempt(Request::create('/forms/'.$uid, 'DELETE')));
        $this->assertFalse($middleware->exempt(Request::create('/login', 'POST')));
        $this->assertFalse($middleware->exempt(Request::create('/customer/workspaces/x/businesses/y/website/forms', 'POST')));
        $this->assertFalse($middleware->exempt(Request::create('/admin/forms/'.$uid, 'POST')));
    }

    public function test_other_content_security_policy_directives_are_preserved_and_only_frame_ancestors_is_replaced(): void
    {
        $reference = $this->offered($this->business, $this->location);
        $this->websiteWithDomain($this->business, 'quotes.example.com');
        $request = Request::create('/forms/'.$reference->uid);
        $request->setRouteResolver(fn () => (new \Illuminate\Routing\Route('GET', 'forms/{deploymentUid}', []))->bind($request));

        $response = app(\App\Http\Middleware\FrameFormForAuthorizedWebsite::class)->handle($request, fn () => response('ok')
            ->header('Content-Security-Policy', "default-src 'self'; frame-ancestors 'none'; img-src https:")
            ->header('X-Frame-Options', 'SAMEORIGIN'));

        $this->assertSame(
            ["default-src 'self'; img-src https:; frame-ancestors 'self' https://quotes.example.com"],
            $response->headers->all('Content-Security-Policy'),
        );
        $this->assertNull($response->headers->get('X-Frame-Options'));
    }

    public function test_validation_errors_and_answers_are_in_the_post_response_without_any_session(): void
    {
        $reference = $this->offered($this->business, $this->location);
        $token = \App\Library\Forms\FormOperationToken::issue($reference);

        $response = $this
            ->post(route('public.forms.submit', [$reference->uid]), [
                \App\Library\Forms\FormSubmissionService::TOKEN_FIELD => $token,
                'email' => 'not-an-email',
            ]);

        $response->assertStatus(422)->assertHeader('Content-Security-Policy')->assertSessionMissing('errors');
        $this->assertStringContainsString('data-role="public-form-errors"', $response->getContent());
        $this->assertStringContainsString('value="not-an-email"', $response->getContent());
        $this->assertStringContainsString($token, $response->getContent());
    }
}
