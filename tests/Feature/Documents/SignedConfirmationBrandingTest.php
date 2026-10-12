<?php

namespace Tests\Feature\Documents;

use App\Models\Website;
use App\Models\WebsiteAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\TestCase;

/**
 * The post-signature page is branded from the SIGNING Business's own Website
 * theme — never another tenant's — with a neutral fallback.
 */
class SignedConfirmationBrandingTest extends TestCase
{
    use RefreshDatabase;
    use SendsDocuments;

    private function brandedWebsite(array $tenant, array $theme): Website
    {
        return Website::create([
            'business_id' => $tenant['business']->id,
            'name' => $tenant['business']->name,
            'theme' => $theme,
        ]);
    }

    private function logo(Website $website, string $path): WebsiteAsset
    {
        return WebsiteAsset::create([
            'uid' => 'logo-'.$website->id,
            'website_id' => $website->id,
            'disk' => 'public',
            'path' => $path,
            'mime_type' => 'image/png',
            'size' => 1,
            'purpose' => \App\Enums\Website\WebsiteAssetPurpose::Logo,
        ]);
    }

    private function sign(array $tenant)
    {
        $this->allowPublicEntitlement();
        [$document, $token] = $this->sendAndCaptureToken($this->draftDocument($tenant));

        return $this->post($this->signUrl($document, $token), [
            'displayed_version_uid' => \Tests\Support\Documents\ShownVersion::uid($document),
            'signer_name' => 'Pat Rivera',
            'signer_email' => 'pat@example.test',
            'typed_name' => 'Pat Rivera',
        ]);
    }

    public function test_the_header_uses_the_signing_business_color_logo_and_name_with_readable_ink(): void
    {
        $tenant = $this->sendableTenant('Harbor Lane Studios');
        $website = $this->brandedWebsite($tenant, ['brand_color' => '#112233']);
        $website->update(['theme' => ['brand_color' => '#112233', 'logo_asset_uid' => $this->logo($website, 'brand/harbor.png')->uid]]);

        $response = $this->sign($tenant)->assertOk()->assertSee('Signature recorded');

        $response->assertSee('background:#112233', false);
        $response->assertSee('color:#ffffff', false);
        $response->assertSee('brand/harbor.png', false);
        $response->assertSee('data-role="brand-name">Harbor Lane Studios', false);
    }

    public function test_a_light_brand_color_gets_dark_ink(): void
    {
        $tenant = $this->sendableTenant();
        $this->brandedWebsite($tenant, ['brand_color' => '#fde68a']);

        $this->sign($tenant)->assertOk()->assertSee('background:#fde68a;color:#000000', false);
    }

    public function test_missing_branding_falls_back_to_neutral_with_the_correct_name(): void
    {
        $tenant = $this->sendableTenant('Plain Co');

        $this->sign($tenant)->assertOk()
            ->assertSee('data-role="brand-name">Plain Co', false)
            ->assertSee('data-role="brand-initial"', false)
            ->assertDontSee('data-role="brand-logo"', false);
    }

    public function test_another_businesses_branding_never_leaks(): void
    {
        $other = $this->sendableTenant('Other Brand');
        $otherSite = $this->brandedWebsite($other, ['brand_color' => '#ff0066']);
        $otherSite->update(['theme' => ['brand_color' => '#ff0066', 'logo_asset_uid' => $this->logo($otherSite, 'brand/other.png')->uid]]);

        $mine = $this->sendableTenant('Mine Co');

        $this->sign($mine)->assertOk()
            ->assertSee('data-role="brand-name">Mine Co', false)
            ->assertDontSee('#ff0066', false)
            ->assertDontSee('brand/other.png', false)
            ->assertDontSee('Other Brand', false);
    }

    public function test_a_logo_uid_belonging_to_another_website_is_ignored(): void
    {
        $other = $this->sendableTenant('Other Brand');
        $otherSite = $this->brandedWebsite($other, []);
        $foreign = $this->logo($otherSite, 'brand/foreign.png');

        $mine = $this->sendableTenant('Mine Co');
        $this->brandedWebsite($mine, ['logo_asset_uid' => $foreign->uid, 'brand_color' => 'not-a-color']);

        $this->sign($mine)->assertOk()
            ->assertDontSee('brand/foreign.png', false)
            ->assertSee('data-role="brand-initial"', false);
    }
}
