<?php

namespace Tests\Unit\Seo;

use App\Library\Seo\SeoLinkSafety;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contract 18 §8.5 / §10.7 — the link-safety boundary. Pure unit test.
 */
class SeoLinkSafetyTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function safeUrls(): array
    {
        return [
            'plain' => ['https://example.com'],
            'with path' => ['https://www.example-directory.com/biz/acme-plumbing'],
            'with query and fragment' => ['https://example.com/b?id=1&x=2#top'],
            'subdomain' => ['https://biz.example.co.uk/claim'],
            'with port' => ['https://example.com:8443/x'],
            'upper-case scheme' => ['HTTPS://example.com/x'],
            'punycode host' => ['https://xn--caf-dma.example/x'],
        ];
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function unsafeUrls(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'http' => ['http://example.com'],
            'javascript' => ['javascript:alert(1)'],
            'javascript with https prefix trick' => ['javascript://https://example.com/%0aalert(1)'],
            'data' => ['data:text/html,<script>alert(1)</script>'],
            'mailto' => ['mailto:a@example.com'],
            'ftp' => ['ftp://example.com/x'],
            'file' => ['file:///etc/passwd'],
            'protocol relative' => ['//example.com/x'],
            'no scheme' => ['example.com/x'],
            'relative path' => ['/biz/acme'],
            'https without slashes' => ['https:example.com'],
            'https single slash' => ['https:/example.com'],
            'userinfo (phishing display)' => ['https://google.com@evil.example/x'],
            'userinfo with password' => ['https://user:pass@example.com/x'],
            'leading space' => [' https://example.com'],
            'trailing space' => ['https://example.com '],
            'inner space' => ['https://exa mple.com/x'],
            'newline injection' => ["https://example.com/x\nSet-Cookie: a=b"],
            'tab' => ["https://example.com/\tx"],
            'backslash host trick' => ['https://example.com\\@evil.example/x'],
            'non-ascii host (must be punycode)' => ['https://café.example/x'],
            'localhost' => ['https://localhost/x'],
            'ip literal' => ['https://127.0.0.1/x'],
            'ipv6 literal' => ['https://[::1]/x'],
            'single label host' => ['https://intranet/x'],
            'label starting with hyphen' => ['https://-bad.example/x'],
            'empty host' => ['https:///x'],
        ];
    }

    #[DataProvider('safeUrls')]
    public function test_safe_https_urls_are_returned_unchanged(string $url): void
    {
        $this->assertSame($url, SeoLinkSafety::safeHttpsUrl($url));
        $this->assertTrue(SeoLinkSafety::isSafeHttpsUrl($url));
    }

    #[DataProvider('unsafeUrls')]
    public function test_everything_else_is_refused(?string $url): void
    {
        $this->assertNull(SeoLinkSafety::safeHttpsUrl($url));
        $this->assertFalse(SeoLinkSafety::isSafeHttpsUrl($url));
    }

    public function test_the_length_cap_is_2048(): void
    {
        $atLimit = 'https://example.com/' . str_repeat('a', SeoLinkSafety::MAX_LENGTH - strlen('https://example.com/'));

        $this->assertSame(2048, strlen($atLimit));
        $this->assertSame($atLimit, SeoLinkSafety::safeHttpsUrl($atLimit));
        $this->assertNull(SeoLinkSafety::safeHttpsUrl($atLimit . 'a'));
    }

    public function test_a_nearly_safe_url_is_refused_never_repaired(): void
    {
        $this->assertNull(SeoLinkSafety::safeHttpsUrl('http://example.com'));
        $this->assertNull(SeoLinkSafety::safeHttpsUrl('https://example.com '));
    }

    public function test_the_contracted_rel_value(): void
    {
        $this->assertSame('noopener noreferrer nofollow', SeoLinkSafety::EXTERNAL_REL);
    }
}
