<?php

namespace Tests\Feature\GoogleAds\Attribution;

use App\Library\GoogleAds\Attribution\AttributionParameters;
use Illuminate\Http\Request;
use Tests\TestCase;

class AttributionParametersTest extends TestCase
{
    private function touchFrom(array $query, string $path = '/sites/abc'): AttributionParameters
    {
        return AttributionParameters::fromRequest(Request::create($path, 'GET', $query));
    }

    public function test_valid_click_ids_and_utms_are_kept(): void
    {
        $touch = $this->touchFrom([
            'gclid' => 'Cj0KCQjw_abc-123XYZ', 'gbraid' => '0AAAAAbcdef_ghij-1', 'wbraid' => 'CjwKWBRAID12345',
            'utm_source' => ' google ', 'utm_medium' => 'cpc', 'utm_campaign' => 'Spring Sale', 'utm_term' => 'photo booth', 'utm_content' => 'ad-1',
        ]);

        $this->assertTrue($touch->hasTouch());
        $this->assertSame('Cj0KCQjw_abc-123XYZ', $touch->columns()['gclid']);
        $this->assertSame('google', $touch->columns()['utm_source']);
        $this->assertSame('Spring Sale', $touch->columns()['utm_campaign']);
    }

    public function test_invalid_values_are_dropped_not_stored_raw(): void
    {
        $touch = $this->touchFrom([
            'gclid' => 'short',                                // too short
            'gbraid' => "abc<script>alert(1)</script>123456",  // illegal characters
            'wbraid' => str_repeat('a', 256),                  // over the click id limit
            'utm_source' => ['array', 'value'],                // not a string
            'utm_medium' => "\x00\x01\x1F",                    // only control characters
            'utm_campaign' => "bad\xC3\x28utf8",               // invalid UTF-8
        ]);

        $this->assertFalse($touch->hasTouch());
        $this->assertSame(array_fill_keys(array_keys($touch->columns()), null), $touch->columns());
    }

    public function test_utm_text_is_cleaned_trimmed_and_length_limited(): void
    {
        $touch = $this->touchFrom([
            'utm_campaign' => "  sum\x00mer\x07\n  ",
            'utm_term' => str_repeat('é', 400),
        ]);

        $this->assertSame('summer', $touch->columns()['utm_campaign']);
        $this->assertSame(255, mb_strlen($touch->columns()['utm_term']));
    }

    public function test_landing_page_is_the_path_only(): void
    {
        $touch = $this->touchFrom(['gclid' => self::id(), 'secret' => 'x', 'email' => 'a@b.test'], '/sites/abc/pricing');

        $this->assertSame('/sites/abc/pricing', $touch->landingPage());
        $this->assertStringNotContainsString('gclid', (string) $touch->landingPage());
        $this->assertStringNotContainsString('?', (string) $touch->landingPage());
    }

    public function test_landing_page_is_length_limited(): void
    {
        $this->assertSame(512, strlen((string) $this->touchFrom(['gclid' => self::id()], '/'.str_repeat('a', 700))->landingPage()));
    }

    public function test_no_click_id_or_utm_means_no_touch(): void
    {
        $this->assertFalse($this->touchFrom([])->hasTouch());
        $this->assertFalse($this->touchFrom(['ref' => 'friend', 'page' => '2'])->hasTouch());
    }

    public function test_payload_round_trip_is_re_sanitised(): void
    {
        $touch = AttributionParameters::fromPayload([
            'gclid' => self::id(), 'utm_source' => "x\x00y", 'business_id' => 99, 'ip' => '1.2.3.4',
            'p' => '/a?b=c#d', 't' => 1000,
        ]);

        $this->assertSame('xy', $touch->columns()['utm_source']);
        $this->assertSame('/a', $touch->landingPage());
        $this->assertArrayNotHasKey('business_id', $touch->toCookiePayload());
        $this->assertArrayNotHasKey('ip', $touch->toCookiePayload());
    }

    public function test_cookie_payload_stays_inside_its_size_budget(): void
    {
        $touch = $this->touchFrom([
            'gclid' => str_repeat('a', 255), 'gbraid' => str_repeat('b', 255), 'wbraid' => str_repeat('c', 255),
            'utm_source' => str_repeat('s', 255), 'utm_medium' => str_repeat('m', 255), 'utm_campaign' => str_repeat('n', 255),
            'utm_term' => str_repeat('t', 255), 'utm_content' => str_repeat('u', 255),
        ]);

        $this->assertLessThanOrEqual(1900, strlen(json_encode($touch->toCookiePayload())));
        $this->assertArrayHasKey('gclid', $touch->toCookiePayload());
    }

    private static function id(): string
    {
        return 'Cj0KCQjwTESTCLICKID0001';
    }
}
