<?php

namespace Tests\Unit\Business;

use App\Library\Business\UrlNormalizer;
use InvalidArgumentException;
use Tests\TestCase;

class UrlNormalizerTest extends TestCase
{
    private UrlNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new UrlNormalizer();
    }

    public function test_it_prepends_https_when_scheme_is_missing(): void
    {
        $this->assertSame('https://example.com', $this->normalizer->normalize('example.com'));
    }

    public function test_it_preserves_http_scheme(): void
    {
        $this->assertSame('http://example.com', $this->normalizer->normalize('http://example.com'));
    }

    public function test_it_preserves_https_scheme(): void
    {
        $this->assertSame('https://example.com', $this->normalizer->normalize('https://example.com'));
    }

    public function test_it_lowercases_the_host(): void
    {
        $this->assertSame('https://example.com', $this->normalizer->normalize('https://EXAMPLE.COM'));
    }

    public function test_it_removes_a_trailing_slash_only_when_path_is_root(): void
    {
        $this->assertSame('https://example.com', $this->normalizer->normalize('https://example.com/'));
        $this->assertSame('https://example.com/about/', $this->normalizer->normalize('https://example.com/about/'));
    }

    public function test_it_preserves_path_and_query(): void
    {
        $this->assertSame(
            'https://example.com/Booking?ref=ABC',
            $this->normalizer->normalize('https://example.com/Booking?ref=ABC')
        );
    }

    public function test_it_converts_blank_to_null(): void
    {
        $this->assertNull($this->normalizer->normalize(''));
        $this->assertNull($this->normalizer->normalize('   '));
        $this->assertNull($this->normalizer->normalize(null));
    }

    public function test_it_rejects_non_http_schemes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->normalizer->normalize('ftp://example.com');
    }

    public function test_it_rejects_credential_bearing_urls(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->normalizer->normalize('https://user:pass@example.com');
    }

    public function test_canonical_domain_strips_www(): void
    {
        $normalized = $this->normalizer->normalize('https://WWW.Example.com/path');

        $this->assertSame('example.com', $this->normalizer->canonicalDomain($normalized));
    }

    public function test_canonical_domain_is_null_for_blank_input(): void
    {
        $this->assertNull($this->normalizer->canonicalDomain(null));
        $this->assertNull($this->normalizer->canonicalDomain(''));
    }
}
