<?php

namespace Tests\Feature\Seo;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Seo\SeoReviewRequestChannel;
use App\Enums\Seo\SeoReviewRequestStatus;
use App\Http\Controllers\Customer\Business\SeoReviewsController;
use App\Http\Middleware\CustomerAccountAccessGate;
use App\Library\Entitlement\PlatformFeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\Feature\Seo\Concerns\CreatesSeoReviewFixtures;
use Tests\TestCase;

/**
 * Contract 18 Sub-slice 18F — the structural guarantees: fail-closed while
 * Planned; no review gating, incentive, quota, ranking, click-tracking or
 * dead Ratings surface (routes, request fields, enums and views); and a
 * source-boundary proof that SEO Reviews SENDS NOTHING and reaches no
 * Messaging, Conversations, Automation or Google client (Contract 18 §8.6,
 * §12, §15.F, §16).
 */
class SeoReviewsBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoReviewFixtures;

    /** Everything a gating / incentive / quota / ratings / tracking feature would be called. */
    private const FORBIDDEN_CONCEPTS = '/rating|ratings|star|stars|sentiment|satisf|happy|unhappy|nps|score|incentiv|reward|discount|coupon|gift|prize|quota|target|goal|leaderboard|ranking|\brank\b|redirect|short-?link|shorten|click|track(?!ing_logs)|divert|funnel|filter-by/i';

    // -----------------------------------------------------------------
    // Planned => fail-closed
    // -----------------------------------------------------------------

    public function test_both_seo_features_remain_planned(): void
    {
        $this->assertFalse(PlatformFeatureRegistry::isAvailable(PlatformFeature::SeoModule->value));
        $this->assertFalse(PlatformFeatureRegistry::isAvailable(PlatformFeature::SeoBasicVisibility->value));
    }

    public function test_every_real_reviews_route_is_404_for_every_tier_while_seo_module_is_planned(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth, WorkspacePlanTier::Agency] as $tier) {
            [$customer, $business, $workspace] = $this->entitledTenant($tier);
            $location = $this->reviewLocation($business);
            $request = $this->makeReviewRequest($business, $location);
            $this->authenticateAsSeoCustomer($customer, $this->reviewPermissions());
            $before = $this->dbFingerprint(['seo_review_requests', 'seo_location_review_links']);

            $this->get($this->reviewsUrl($workspace, $business))->assertNotFound();
            $this->put($this->reviewRoute('link.save', $workspace, $business, (string) $location->uid), ['review_url' => 'https://g.page/r/x'])->assertNotFound();
            $this->post($this->reviewRoute('link.clear', $workspace, $business, (string) $location->uid))->assertNotFound();
            $this->post($this->reviewRoute('requests.store', $workspace, $business, (string) $location->uid), ['channel' => 'sms'])->assertNotFound();
            $this->post($this->reviewRoute('requests.reviewed', $workspace, $business, (string) $request->uid))->assertNotFound();
            $this->post($this->reviewRoute('requests.declined', $workspace, $business, (string) $request->uid))->assertNotFound();

            $this->assertSame($before, $this->dbFingerprint(['seo_review_requests', 'seo_location_review_links']), 'A fail-closed write must persist nothing.');
        }
    }

    public function test_the_production_controller_gates_on_seo_module_and_the_two_seo_capabilities(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Http/Controllers/Customer/Business/SeoReviewsController.php');

        $this->assertStringContainsString('PlatformFeature::SeoModule->value', $source);
        $this->assertStringNotContainsString('SeoBasicVisibility', $source);
        $this->assertStringContainsString("authorize('view_seo')", $source);
        // saveLink, clearLink, recordRequest and the shared resolve() behind reviewed/declined.
        $this->assertSame(4, substr_count($source, "authorize('manage_seo')"), 'Every write entry point needs manage_seo.');
    }

    // -----------------------------------------------------------------
    // Routes: closed set, closed methods, no gating/incentive/ratings surface
    // -----------------------------------------------------------------

    /**
     * @return \Illuminate\Support\Collection<int, \Illuminate\Routing\Route>
     */
    private function reviewRoutes()
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains((string) $route->getActionName(), SeoReviewsController::class));
    }

    public function test_the_route_set_is_exactly_the_contracted_workflow_and_names_stay_in_the_seo_namespace(): void
    {
        $routes = $this->reviewRoutes()->mapWithKeys(fn ($route) => [$route->getName() => array_values(array_diff($route->methods(), ['HEAD']))]);

        $this->assertEqualsCanonicalizing([
            'customer.workspaces.businesses.seo.reviews.index' => ['GET'],
            'customer.workspaces.businesses.seo.reviews.link.save' => ['PUT'],
            'customer.workspaces.businesses.seo.reviews.link.clear' => ['POST'],
            'customer.workspaces.businesses.seo.reviews.requests.store' => ['POST'],
            'customer.workspaces.businesses.seo.reviews.requests.reviewed' => ['POST'],
            'customer.workspaces.businesses.seo.reviews.requests.declined' => ['POST'],
        ], $routes->all());

        foreach ($routes->keys() as $name) {
            $this->assertFalse(str_starts_with((string) $name, 'customer.keywords.'));
        }
    }

    public function test_no_route_uri_name_or_parameter_is_a_gating_incentive_quota_ratings_or_tracking_surface(): void
    {
        foreach ($this->reviewRoutes() as $route) {
            $surface = $route->uri() . ' ' . $route->getName() . ' ' . implode(' ', $route->parameterNames());
            // "reviewed"/"reviews" are the feature's own words; strip them so the scan is about everything ELSE.
            $surface = preg_replace('/review(ed|s)?/i', '', $surface);

            $this->assertDoesNotMatchRegularExpression(self::FORBIDDEN_CONCEPTS, (string) $surface, 'Route [' . $route->getName() . '] looks like a gating/incentive/quota/ratings/tracking surface.');
        }
    }

    public function test_no_seo_route_anywhere_is_a_ratings_stars_or_review_content_page(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! str_contains($name, '.seo.') && ! str_starts_with($name, 'customer.seo.')) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression('/rating|stars?\b|review-?content|review-?feed|reviewer|google-?reviews/i', $route->uri() . ' ' . $name, "[{$name}] is a dead Ratings / review-content surface.");
        }
    }

    public function test_the_only_request_fields_read_are_the_four_contracted_ones(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Http/Controllers/Customer/Business/SeoReviewsController.php');

        preg_match_all("/'([a-z_]+)' => \[/", $source, $matches);

        $this->assertEqualsCanonicalizing(['review_url', 'channel', 'contact_uid', 'crm_opportunity_uid'], array_values(array_unique($matches[1])));
        $this->assertStringNotContainsString('$request->all()', $source);
        $this->assertStringNotContainsString('$request->input(', $source);
        $this->assertStringNotContainsString('$request->get(', $source);
    }

    public function test_the_closed_vocabularies_carry_no_sentiment_gating_or_incentive_member(): void
    {
        $this->assertSame(['sms', 'email', 'in_person', 'other'], array_map(fn ($c) => $c->value, SeoReviewRequestChannel::cases()));
        $this->assertSame(['requested', 'reviewed', 'declined'], array_map(fn ($c) => $c->value, SeoReviewRequestStatus::cases()));
        $this->assertSame('Reviewed (self-reported)', SeoReviewRequestStatus::Reviewed->label());

        foreach ([...SeoReviewRequestChannel::cases(), ...SeoReviewRequestStatus::cases()] as $case) {
            $this->assertDoesNotMatchRegularExpression(self::FORBIDDEN_CONCEPTS, $case->value . ' ' . $case->label());
        }
    }

    public function test_the_view_has_no_gating_incentive_quota_ratings_or_tracking_copy_or_controls(): void
    {
        $view = file_get_contents(dirname(__DIR__, 3) . '/resources/views/customer/business/seo/reviews.blade.php');
        $markup = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $view);

        // target="_blank" is the HTML attribute, not a marketing target.
        $this->assertDoesNotMatchRegularExpression(self::FORBIDDEN_CONCEPTS, str_replace('target="_blank"', '', $markup));
        $this->assertStringNotContainsString('{!!', $markup);
        $this->assertStringNotContainsString('<script', strtolower($markup));
        $this->assertStringNotContainsString('type="radio"', $markup);
        $this->assertStringNotContainsString('type="range"', $markup);
        $this->assertDoesNotMatchRegularExpression('/<(tab|nav)[^>]*>|role="tab"/i', $markup, 'No tabs at all — and so no dead Ratings tab.');

        // Every external link carries the contracted rel.
        $this->assertSame(substr_count($markup, 'target="_blank"'), substr_count($markup, 'rel="{{ $rel }}"'));
        // The only outbound destination is the link the user pasted / Google's own review URI.
        $this->assertSame(1, substr_count($markup, 'target="_blank"'));
    }

    public function test_the_page_a_user_receives_has_no_rating_input_no_ratings_tab_and_no_review_content(): void
    {
        $this->bypassReviewEntitlementForTest();
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $location = $this->reviewLocation($business);
        $this->makeReviewRequest($business, $location, $this->reviewContact($business, $location));
        $this->makeReviewLink($business, $location);
        $this->authenticateAsSeoCustomer($customer, $this->reviewPermissions());

        $html = $this->get($this->reviewsUrl($workspace, $business))->assertOk()->getContent();
        $body = (string) preg_replace('/<(script|style|template)\b.*?<\/\1>/is', '', $html);
        $body = (string) preg_replace('/<head\b.*?<\/head>/is', '', $body);
        // The shared application shell (navigation, footer) is not this feature.
        $inner = substr($body, (int) strpos($body, 'data-role="reviews-note"'));

        $this->assertDoesNotMatchRegularExpression('/\bratings?\b|\bstars?\b|sentiment|leaderboard|incentiv|\bquota\b|\bgoal\b/i', strip_tags(substr($inner, 0, (int) strpos($inner, 'data-role="deep-link-automations"') + 200)));
        $this->assertSame(0, substr_count($html, 'type="radio"'));
    }

    // -----------------------------------------------------------------
    // Locked accounts
    // -----------------------------------------------------------------

    public function test_no_reviews_route_is_on_the_locked_account_allowlist(): void
    {
        $allowed = (new ReflectionClass(CustomerAccountAccessGate::class))->getReflectionConstant('ALLOWED_ROUTE_NAMES')->getValue();

        foreach ($allowed as $name) {
            $this->assertStringNotContainsString('seo', (string) $name);
        }
    }

    // -----------------------------------------------------------------
    // Source boundary: SEO Reviews sends NOTHING
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function reviewCodeFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $files = [
            $root . '/app/Library/Seo/SeoReviewLinkManager.php',
            $root . '/app/Library/Seo/SeoReviewRequestManager.php',
            $root . '/app/Library/Seo/SeoReviewWriteGate.php',
            $root . '/app/Library/Seo/SeoReviewsPageReader.php',
            $root . '/app/Library/Seo/SeoReviewLocationSection.php',
            $root . '/app/Http/Controllers/Customer/Business/SeoReviewsController.php',
            $root . '/app/Models/SeoReviewRequest.php',
            $root . '/app/Models/SeoLocationReviewLink.php',
            $root . '/app/Exceptions/Seo/SeoReviewException.php',
            $root . '/app/Enums/Seo/SeoReviewRequestChannel.php',
            $root . '/app/Enums/Seo/SeoReviewRequestStatus.php',
        ];

        $cases = [];

        foreach ($files as $file) {
            $cases[basename($file)] = [$file];
        }

        return $cases;
    }

    #[DataProvider('reviewCodeFiles')]
    public function test_review_code_cannot_send_and_reaches_no_messaging_automation_ai_network_or_google_client(string $file): void
    {
        $code = $this->codeWithoutComments($file);

        $this->assertNotSame('', $code);

        $forbidden = [
            // Any outbound message: SMS, email, notification, chat, campaign, WhatsApp/Viber, voice.
            '/\bMail::/', '/Mailable/', '/\bNotification::/', '/Notifiable/', '/->notify\s*\(/', '/->send\s*\(/', '/\bsend\w*\s*\(/i',
            '/App\\\\Library\\\\Messaging/', '/App\\\\Library\\\\Conversations/', '/App\\\\Library\\\\SMS/i', '/App\\\\Jobs\\\\/',
            '/App\\\\Library\\\\Automation/', '/AutomationWorkflow/', '/AutomationExecutor/', '/Automations\\\\/', '/App\\\\Models\\\\Automation/',
            '/\bChatBox/', '/\bCampaign/', '/SendCampaign/', '/SendMessage/', '/Twilio/i', '/Vonage/i', '/Nexmo/i', '/Plivo/i', '/SendingServer/',
            '/App\\\\Models\\\\(Reports|TrackingLog|SmsLog|Blacklists|Templates)/',
            // Any network call, Google client or SDK.
            '/\bHttp::/', '/GuzzleHttp/', '/\bcurl_/', '/file_get_contents\s*\(/', '/fopen\s*\(/', '/Socialite/',
            '/GoogleBusinessProfileReadClient/', '/HttpGoogleBusinessProfileReadClient/', '/FakeGoogleBusinessProfileReadClient/',
            '/BusinessGoogleConnection/', '/BusinessGoogleLocation/', '/GoogleBusinessProfileMirrorService/', '/GoogleBusinessProfileComparator/',
            // Any AI provider.
            '/OpenAI/i', '/App\\\\Library\\\\Ai\\\\/', '/AiGateway/', '/Anthropic/i',
            // Any queued / dispatched / scheduled / event side effect.
            '/\bdispatch\s*\(/', '/::dispatch\b/', '/\bBus::/', '/\bQueue::/', '/\bevent\s*\(/', '/\bEvent::/', '/\bSchedule::/', '/\bCache::/',
            // Any Website write seam.
            '/WebsiteDraftPageService/', '/WebsitePublisher/',
            // Any raw SQL write.
            '/\bDB::(insert|update|delete|statement|unprepared|table)\b/',
        ];

        foreach ($forbidden as $pattern) {
            $this->assertSame(0, preg_match($pattern, $code), basename($file) . ' must not match ' . $pattern . ' (Contract 18 §8.6: SEO sends nothing).');
        }
    }

    #[DataProvider('reviewCodeFiles')]
    public function test_review_code_uses_no_gating_incentive_quota_or_ratings_concept(string $file): void
    {
        $code = $this->codeWithoutComments($file);

        // Vocabulary that would betray a gating / incentive / quota / ratings feature.
        $this->assertDoesNotMatchRegularExpression('/rating|sentiment|satisf|incentiv|reward|discount|coupon|quota|leaderboard|nps\b|score/i', $code, basename($file));
    }

    public function test_the_managers_write_only_their_own_tables_and_read_contacts_and_opportunities_read_only(): void
    {
        $root = dirname(__DIR__, 3);

        foreach (['SeoReviewLinkManager', 'SeoReviewRequestManager'] as $manager) {
            $code = $this->codeWithoutComments($root . '/app/Library/Seo/' . $manager . '.php');

            $this->assertSame(0, preg_match('/\$(business|locked|location|workspace|contact|opportunity|website)\w*->(save|update|delete|forceDelete|touch|increment|decrement|fill|forceFill)\s*\(/', $code), "{$manager}: no write to a Business, Location, Contact or Opportunity.");
            $this->assertSame(0, preg_match('/\b(Business|BusinessLocation|Workspace|Contacts|CrmOpportunity|Website\w*)::(create|insert|upsert|updateOrCreate|firstOrCreate|destroy|forceCreate|query\(\)->(update|delete|insert))/', $code), "{$manager}: writes only its own tables.");
        }

        // Contacts and Opportunities are only ever read, by uid inside the Business.
        $requests = $this->codeWithoutComments($root . '/app/Library/Seo/SeoReviewRequestManager.php');
        $this->assertStringContainsString("where('business_id', \$locked->id)", $requests);
        $this->assertStringContainsString("Gate::forUser(\$actor)->allows('view_contact')", $requests);
    }

    public function test_the_page_reader_names_only_the_status_reader_and_contact_directory_as_foreign_seams(): void
    {
        $code = $this->codeWithoutComments(dirname(__DIR__, 3) . '/app/Library/Seo/SeoReviewsPageReader.php');

        preg_match_all('/use App\\\\Library\\\\(\w+)\\\\(\w+);/', $code, $matches, PREG_SET_ORDER);
        $seams = array_map(fn ($m) => $m[1] . '\\' . $m[2], $matches);

        $this->assertEqualsCanonicalizing(['Contacts\\ContactDirectory', 'GoogleBusinessProfile\\GoogleBusinessProfileStatusReader'], $seams);
    }

    public function test_seo_adds_no_automation_trigger_node_or_merge_field(): void
    {
        $root = dirname(__DIR__, 3);

        // Nothing under the Automations domain mentions the SEO review ledger.
        foreach (['app/Library/Automations', 'app/Library/Automation', 'app/Models'] as $dir) {
            if (! is_dir($root . '/' . $dir)) {
                continue;
            }

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $name = $file->getFilename();

                if (str_starts_with($name, 'Seo')) {
                    continue;
                }

                $this->assertStringNotContainsString('SeoReview', (string) file_get_contents($file->getPathname()), $name . ' must not know about the SEO review ledger.');
            }
        }
    }

    public function test_no_platform_feature_was_added_or_flipped(): void
    {
        $this->assertNotContains('seo_reviews', array_map(fn (PlatformFeature $feature) => $feature->value, PlatformFeature::cases()));
        $this->assertNotContains('reviews', array_map(fn (PlatformFeature $feature) => $feature->value, PlatformFeature::cases()));
    }

    private function codeWithoutComments(string $path): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }
}
