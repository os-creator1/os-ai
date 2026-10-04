<?php

namespace Tests\Feature\GoogleAds\Attribution;

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Route;
use Tests\Feature\GoogleAds\Attribution\Concerns\BuildsAttributionCookies;
use Tests\TestCase;

class CaptureAttributionTouchTest extends TestCase
{
    use BuildsAttributionCookies;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'attribution.capture'])->group(function (): void {
            Route::get('/__attribution/page', fn () => response('ok'));
            Route::get('/__attribution/missing', fn () => abort(404));
            Route::post('/__attribution/post', fn () => response('ok'))->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        });
    }

    private function cookieNames($response): array
    {
        return array_values(array_filter(array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies()), fn (string $name) => str_starts_with($name, 'bos_at_')));
    }

    public function test_tagged_arrival_sets_both_cookies_hardened_and_encrypted(): void
    {
        $response = $this->get('/__attribution/page?gclid='.self::CLICK.'&utm_source=google&junk=1');

        $first = $response->getCookie('bos_at_first');
        $last = $response->getCookie('bos_at_last');
        $this->assertNotNull($first);
        $this->assertNotNull($last);

        foreach ([$first, $last] as $cookie) {
            $this->assertTrue($cookie->isHttpOnly());
            $this->assertSame('lax', $cookie->getSameSite());
            $this->assertNull($cookie->getDomain(), 'host-only cookie: works on any host or custom domain');
            $this->assertFalse($cookie->isSecure(), 'plain http request');
            $this->assertEqualsWithDelta(time() + 90 * 86400, $cookie->getExpiresTime(), 120);
        }

        $payload = json_decode($first->getValue(), true);
        $this->assertSame(self::CLICK, $payload['gclid']);
        $this->assertSame('google', $payload['utm_source']);
        $this->assertSame('/__attribution/page', $payload['p']);
        $this->assertEqualsCanonicalizing(['gclid', 'utm_source', 'p', 't'], array_keys($payload), 'no visitor id, ip or user agent');

        // On the wire the value is Laravel-encrypted, not readable JSON.
        $raw = $response->getCookie('bos_at_first', false)->getValue();
        $this->assertStringNotContainsString(self::CLICK, $raw);
    }

    public function test_cookies_are_secure_on_https(): void
    {
        $response = $this->get('https://localhost/__attribution/page?gclid='.self::CLICK);

        $this->assertTrue($response->getCookie('bos_at_first')->isSecure());
    }

    public function test_the_cookies_are_not_exempt_from_encryption(): void
    {
        $except = (new \ReflectionProperty(\App\Http\Middleware\EncryptCookies::class, 'except'))->getValue(app(\App\Http\Middleware\EncryptCookies::class));
        $this->assertNotContains('bos_at_first', $except);
        $this->assertNotContains('bos_at_last', $except);
    }

    public function test_first_is_written_once_and_last_is_rewritten(): void
    {
        $existing = $this->touchCookies(['gclid' => 'FirstClickIdAAAAAA1'], ['gclid' => 'FirstClickIdAAAAAA1']);

        $response = $this->withCookies($existing)->get('/__attribution/page?gclid=SecondClickIdBBBBBB2');

        $this->assertNotContains('bos_at_first', $this->cookieNames($response));
        $this->assertSame('SecondClickIdBBBBBB2', json_decode($response->getCookie('bos_at_last')->getValue(), true)['gclid']);
    }

    public function test_an_invalid_existing_first_cookie_is_replaced(): void
    {
        $response = $this->withUnencryptedCookie('bos_at_first', 'garbage')->get('/__attribution/page?gclid='.self::CLICK);

        $this->assertContains('bos_at_first', $this->cookieNames($response));
    }

    public function test_global_privacy_control_captures_nothing(): void
    {
        $response = $this->withHeaders(['Sec-GPC' => '1'])->get('/__attribution/page?gclid='.self::CLICK);

        $this->assertSame([], $this->cookieNames($response));
    }

    public function test_do_not_track_captures_nothing(): void
    {
        $response = $this->withHeaders(['DNT' => '1'])->get('/__attribution/page?gclid='.self::CLICK);

        $this->assertSame([], $this->cookieNames($response));
    }

    public function test_untagged_arrival_sets_nothing(): void
    {
        $this->assertSame([], $this->cookieNames($this->get('/__attribution/page')));
        $this->assertSame([], $this->cookieNames($this->get('/__attribution/page?page=2&ref=x&gclid=bad')));
    }

    public function test_capture_can_be_disabled_by_config(): void
    {
        config(['google_ads.attribution.capture_enabled' => false]);

        $this->assertSame([], $this->cookieNames($this->get('/__attribution/page?gclid='.self::CLICK)));
    }

    public function test_error_responses_and_non_get_requests_set_nothing(): void
    {
        $this->assertSame([], $this->cookieNames($this->get('/__attribution/missing?gclid='.self::CLICK)));
        $this->assertSame([], $this->cookieNames($this->post('/__attribution/post?gclid='.self::CLICK)));
    }

    public function test_a_publicly_cacheable_response_is_made_private_when_it_carries_a_cookie(): void
    {
        Route::middleware(['web', 'attribution.capture'])->get('/__attribution/cached', fn () => response('ok')->header('Cache-Control', 'public, max-age=600'));

        $cache = $this->get('/__attribution/cached?gclid='.self::CLICK)->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $cache);
        $this->assertStringNotContainsString('public', $cache);
    }
}
