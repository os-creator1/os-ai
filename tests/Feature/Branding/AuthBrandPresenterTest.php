<?php

namespace Tests\Feature\Branding;

use App\Library\Branding\AgencyBrand;
use App\Library\Branding\AgencyBrandResolver;
use App\Library\Branding\AuthBrandPresenter;
use App\Library\Branding\AuthBrandSource;
use App\Library\Branding\BrandingPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Customer Experience Slice 2 — the branding seam's presentation model in
 * isolation (brief §3/§5): precedence, the neutral default, and the
 * normalization that keeps a stored asset reference or accent from ever
 * becoming a broken URL, a traversal, a remote fetch or arbitrary CSS.
 */
class AuthBrandPresenterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.name' => 'AI Business OS', 'app.auth_illustration' => null]);
        Cache::forget(BrandingPresenter::CACHE_KEY);
    }

    protected function tearDown(): void
    {
        Cache::forget(BrandingPresenter::CACHE_KEY);
        parent::tearDown();
    }

    private function presenterReturning(?AgencyBrand $brand): AuthBrandPresenter
    {
        $resolver = new class($brand) extends AgencyBrandResolver {
            public function __construct(private readonly ?AgencyBrand $brand)
            {
            }

            public function resolve(Request $request): ?AgencyBrand
            {
                return $this->brand;
            }
        };

        return new AuthBrandPresenter($resolver, new BrandingPresenter());
    }

    public function test_the_neutral_identity_is_a_finished_typographic_panel_without_artwork(): void
    {
        $brand = $this->presenterReturning(null)->for(Request::create('/login'));

        $this->assertSame(AuthBrandSource::Neutral, $brand->source);
        $this->assertSame('AI Business OS', $brand->displayName);
        $this->assertSame('AB', $brand->mark);
        $this->assertNull($brand->logoSrc);
        $this->assertNull($brand->illustrationSrc);
        $this->assertFalse($brand->hasIllustration());
        $this->assertNotEmpty($brand->tagline);
        $this->assertContains('Google Business Profile', $brand->areas);
        $this->assertNull($brand->tokenStyle());
    }

    public function test_the_owner_platform_name_takes_precedence_over_neutral(): void
    {
        config(['app.name' => 'Harbor Lane Platform']);
        Cache::forget(BrandingPresenter::CACHE_KEY);

        $brand = $this->presenterReturning(null)->for(Request::create('/login'));

        $this->assertSame(AuthBrandSource::Platform, $brand->source);
        $this->assertSame('Harbor Lane Platform', $brand->displayName);
        $this->assertSame('HL', $brand->mark);
    }

    public function test_an_agency_brand_takes_precedence_over_the_platform(): void
    {
        config(['app.name' => 'Harbor Lane Platform']);
        Cache::forget(BrandingPresenter::CACHE_KEY);

        $brand = $this->presenterReturning(new AgencyBrand('uid', 'Northwind Agency', null, 'Client portal'))
            ->for(Request::create('/login'));

        $this->assertSame(AuthBrandSource::Agency, $brand->source);
        $this->assertTrue($brand->isAgency());
        $this->assertSame('Northwind Agency', $brand->displayName);
        $this->assertSame('NA', $brand->mark);
        $this->assertSame('Client portal', $brand->tagline);
        $this->assertSame([], $brand->areas, 'An Agency screen does not advertise the platform areas.');
    }

    public function test_only_an_existing_branding_asset_with_a_permitted_extension_is_ever_emitted(): void
    {
        $cases = [
            'images/branding/default-logo.svg' => 'images/branding/default-logo.svg',
            'images/branding/default-logo-compact.svg' => 'images/branding/default-logo-compact.svg',
            'images/branding/agency/missing.png' => null,
            '../../.env' => null,
            'images/branding/../../.env' => null,
            '/etc/passwd' => null,
            'https://evil.example/logo.png' => null,
            'data:image/png;base64,AAAA' => null,
            'images/pages/login-v2.svg' => null,
            'images/branding/default-logo.svg?x=1' => null,
            "images/branding/default-logo.svg\0.php" => null,
            'images/branding/script.php' => null,
            '' => null,
        ];

        foreach ($cases as $input => $expected) {
            $brand = $this->presenterReturning(new AgencyBrand('uid', 'Agency', $input))->for(Request::create('/login'));

            $this->assertSame($expected, $brand->logoSrc, "asset reference {$input}");
            $this->assertSame($expected !== null ? 'Agency' : null, $brand->logoAlt);
        }
    }

    public function test_the_owner_illustration_is_validated_the_same_way(): void
    {
        foreach (['images/pages/login-v2.svg', '../secret.png', 'images/branding/nope.png'] as $bad) {
            config(['app.auth_illustration' => $bad]);
            Cache::forget(BrandingPresenter::CACHE_KEY);

            $this->assertNull($this->presenterReturning(null)->for(Request::create('/login'))->illustrationSrc, $bad);
        }

        config(['app.auth_illustration' => 'images/branding/default-logo.svg']);
        Cache::forget(BrandingPresenter::CACHE_KEY);

        $brand = $this->presenterReturning(null)->for(Request::create('/login'));
        $this->assertSame('images/branding/default-logo.svg', $brand->illustrationSrc);
        $this->assertSame(AuthBrandSource::Platform, $brand->source);
    }

    public function test_only_a_six_digit_hex_accent_becomes_a_permitted_token(): void
    {
        $accepted = $this->presenterReturning(new AgencyBrand('uid', 'Agency', null, null, '#1A2B3C'))->for(Request::create('/login'));
        $this->assertSame(['--auth-brand-accent' => '#1a2b3c'], $accepted->tokens);
        $this->assertSame('--auth-brand-accent:#1a2b3c', $accepted->tokenStyle());

        foreach (['red', '#fff', 'expression(alert(1))', 'url(x)', '#12345g', '#123456;color:red', null] as $bad) {
            $brand = $this->presenterReturning(new AgencyBrand('uid', 'Agency', null, null, $bad))->for(Request::create('/login'));
            $this->assertSame([], $brand->tokens, (string) $bad);
        }
    }

    public function test_display_text_is_plain_bounded_and_never_empty(): void
    {
        $brand = $this->presenterReturning(new AgencyBrand('uid', "  <script>alert(1)</script>Northwind\n\tAgency  ", null, str_repeat('x', 400)))
            ->for(Request::create('/login'));

        $this->assertSame('alert(1)Northwind Agency', $brand->displayName);
        $this->assertSame(160, strlen((string) $brand->tagline));

        $empty = $this->presenterReturning(new AgencyBrand('uid', '<b></b>'))->for(Request::create('/login'));
        $this->assertSame('AI Business OS', $empty->displayName, 'An empty Agency name falls back to the product name.');
    }
}
