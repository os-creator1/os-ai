<?php

namespace Tests\Feature\Documents;

use App\Enums\Website\WebsiteAssetPurpose;
use App\Library\Documents\Blocks\BlockSchema;
use App\Library\Documents\DocumentManager;
use App\Models\Website;
use App\Models\WebsiteAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\Support\Documents\ShownVersion;
use Tests\TestCase;

/**
 * The recipient-facing proposal page: Business-branded header and accents, a
 * readable document card, a summary that adapts to mobile, and every signing,
 * payment and refusal behaviour unchanged. Presentation only.
 */
class PublicDocumentRecipientDesignTest extends TestCase
{
    use RefreshDatabase;
    use SendsDocuments;

    private function branded(array $tenant, string $color, ?string $logoPath = null): Website
    {
        $website = Website::create(['business_id' => $tenant['business']->id, 'name' => 'Site', 'theme' => ['brand_color' => $color]]);

        if ($logoPath !== null) {
            $logo = WebsiteAsset::create([
                'uid' => 'logo-' . $website->id, 'website_id' => $website->id, 'disk' => 'public', 'path' => $logoPath,
                'mime_type' => 'image/png', 'size' => 1, 'purpose' => WebsiteAssetPurpose::Logo,
            ]);
            $website->update(['theme' => ['brand_color' => $color, 'logo_asset_uid' => $logo->uid]]);
        }

        return $website;
    }

    /** @return array{0: array, 1: \App\Models\BusinessDocument, 2: string} */
    private function sent(string $name = 'Scary Photos', array $overrides = [], bool $blocks = true): array
    {
        $this->allowPublicEntitlement();
        $tenant = $this->sendableTenant($name);
        $document = $this->draftDocument($tenant, $overrides);

        if ($blocks) {
            app(DocumentManager::class)->edit($document, ['content' => BlockSchema::document([
                ['type' => 'heading', 'data' => ['level' => 1, 'runs' => [['t' => 'Photo booth proposal']]]],
                ['type' => 'text', 'data' => ['runs' => [['t' => 'Hello <b onclick="x()">there</b>']]]],
                ['type' => 'product_list', 'data' => []],
                ['type' => 'payment_terms', 'data' => []],
                ['type' => 'signature', 'data' => ['label' => 'Client signature']],
            ])]);
        }

        [$document, $token] = $this->sendAndCaptureToken($document->refresh());

        return [$tenant, $document, $token];
    }

    private function page(array $sent)
    {
        return $this->get($this->publicUrl($sent[1], $sent[2]));
    }

    private function sign(array $sent)
    {
        return $this->post($this->signUrl($sent[1], $sent[2]), [
            'displayed_version_uid' => ShownVersion::uid($sent[1]),
            'signer_name' => 'Pat Rivera', 'signer_email' => 'pat@example.test', 'typed_name' => 'Pat Rivera',
        ]);
    }

    // -- branding ----------------------------------------------------------------

    public function test_the_page_uses_the_issuing_businesss_name_logo_and_colour_never_the_platforms(): void
    {
        $sent = $this->sent('Harbor Lane Studios');
        $this->branded($sent[0], '#112233', 'brand/harbor.png');

        $html = $this->page($sent)->assertOk()->getContent();

        $this->assertStringContainsString('--pd-accent:#112233', $html);
        $this->assertStringContainsString('--pd-ink:#ffffff', $html);
        $this->assertStringContainsString('data-role="brand-name">Harbor Lane Studios', $html);
        $this->assertStringContainsString('data-role="brand-logo"', $html);
        $this->assertStringContainsString('brand/harbor.png', $html);
        $this->assertStringNotContainsStringIgnoringCase('--pd-accent:#B5524C', $html);
        $this->assertStringNotContainsStringIgnoringCase('motiongrove', $html);
    }

    public function test_a_business_without_branding_gets_a_neutral_header_not_the_platform_colour(): void
    {
        $sent = $this->sent('Plain Co');

        $html = $this->page($sent)->assertOk()->getContent();

        $this->assertStringContainsString('data-role="brand-name">Plain Co', $html);
        $this->assertStringContainsString('data-role="brand-initial"', $html);
        $this->assertStringNotContainsString('data-role="brand-logo"', $html);
        $this->assertStringContainsString('--pd-accent:#1f2933', $html);
        $this->assertStringNotContainsStringIgnoringCase('--pd-accent:#B5524C', $html);
    }

    public function test_a_light_brand_colour_gets_dark_ink(): void
    {
        $sent = $this->sent();
        $this->branded($sent[0], '#fde68a');

        $this->page($sent)->assertSee('--pd-ink:#000000', false);
    }

    public function test_another_businesss_branding_never_leaks_and_isolation_holds(): void
    {
        $a = $this->sent('Alpha Studio');
        $this->branded($a[0], '#112233', 'brand/alpha.png');
        $b = $this->sent('Beta Studio', ['title' => 'Beta proposal']);
        $this->branded($b[0], '#ff0066', 'brand/beta.png');

        $pageA = $this->page($a)->assertOk()->getContent();
        $pageB = $this->page($b)->assertOk()->getContent();

        $this->assertStringContainsString('Alpha Studio', $pageA);
        $this->assertStringNotContainsString('Beta', $pageA);
        $this->assertStringNotContainsString('#ff0066', $pageA);
        $this->assertStringNotContainsString('brand/beta.png', $pageA);
        $this->assertStringNotContainsString('Alpha', $pageB);
        $this->assertStringNotContainsString('brand/alpha.png', $pageB);

        // A's token does not open B's document, and the refusal says nothing.
        $this->get($this->publicUrl($b[1], $a[2]))->assertNotFound()->assertDontSee('Beta');
    }

    public function test_the_frozen_issuer_name_survives_a_later_business_rename(): void
    {
        $sent = $this->sent('Original Name');
        $sent[0]['business']->forceFill(['name' => 'Renamed Later'])->save();

        $html = $this->page($sent)->assertOk()->getContent();

        $this->assertStringContainsString('data-role="issuer">Original Name', $html);
        $this->assertStringContainsString('data-role="brand-name">Original Name', $html);
        $this->assertStringNotContainsString('Renamed Later', $html);
    }

    // -- structure: desktop / mobile --------------------------------------------------

    public function test_unsigned_page_has_the_summary_document_card_and_sign_section_with_every_selector_kept(): void
    {
        $sent = $this->sent();

        $html = $this->page($sent)->assertOk()->getContent();

        foreach (['brand-header', 'summary', 'summary-total', 'summary-from', 'summary-for', 'summary-status', 'summary-action', 'issuer', 'document-blocks', 'sign-form', 'consent-statement', 'lines', 'schedule', 'payment-section', 'summary-note'] as $role) {
            $this->assertStringContainsString('data-role="' . $role . '"', $html, $role);
        }

        $this->assertStringContainsString('USD 500.00', $html);
        $this->assertStringContainsString('Paid in full after signing', $html);
        $this->assertStringContainsString('Waiting for signature', $html);
        $this->assertStringContainsString('href="#sign"', $html);
        $this->assertStringContainsString('id="sign"', $html);
        $this->assertStringContainsString('Questions or changes? Get in touch with Scary Photos before signing.', $html);

        // The sign form posts the same fields to the same route; the acknowledgement adds none.
        $this->assertStringContainsString('action="' . $this->signUrl($sent[1], $sent[2]) . '"', $html);
        foreach (['signer_name', 'signer_email', 'typed_name', 'displayed_version_uid'] as $field) {
            $this->assertStringContainsString('name="' . $field . '"', $html);
        }
        $this->assertSame(0, substr_count($html, ' name="consent'), 'the acknowledgement posts nothing');
        $this->assertStringContainsString('id="consent_ack" type="checkbox" required', $html);
        $this->assertStringContainsString(DocumentManager::CONSENT_STATEMENT, $html);

        // Labels are bound to their inputs.
        foreach (['signer_name', 'signer_email', 'typed_name'] as $id) {
            $this->assertStringContainsString('for="' . $id . '"', $html);
            $this->assertStringContainsString('id="' . $id . '"', $html);
        }
    }

    public function test_the_layout_collapses_to_one_column_with_the_summary_first_on_narrow_screens(): void
    {
        $html = $this->page($this->sent())->assertOk()->getContent();

        $this->assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $html);
        $this->assertStringContainsString('grid-template-areas:"head head" "main summary" "main note" "main ."', $html);
        $this->assertStringContainsString('@media (max-width:860px)', $html);
        $this->assertStringContainsString('grid-template-areas:"head" "summary" "main" "note"', $html);
        $this->assertStringContainsString('.pd-sign .pd-row{grid-template-columns:1fr}', $html);
        // In the source the summary precedes the document, so a linear (screen reader / mobile) read starts with it.
        $this->assertGreaterThan(strpos($html, 'data-role="summary"'), strpos($html, 'data-role="issuer"'));
    }

    public function test_no_fake_download_action_and_no_pdf_claim(): void
    {
        $html = $this->page($this->sent())->assertOk()->getContent();

        $this->assertStringNotContainsStringIgnoringCase('download', $html);
        $this->assertStringNotContainsStringIgnoringCase('pdf', $html);
    }

    // -- states ----------------------------------------------------------------------

    public function test_unsigned_page_never_shows_a_paid_badge(): void
    {
        $html = $this->page($this->sent())->assertOk()->getContent();

        $this->assertStringNotContainsString('data-role="paid-confirmation"', $html);
        $this->assertDoesNotMatchRegularExpression('/data-role="summary-status">Paid</', $html);
    }

    public function test_signed_page_shows_signed_state_without_the_sign_form_or_sign_action(): void
    {
        $sent = $this->sent();
        $this->sign($sent)->assertOk()->assertSee('Signature recorded');

        $html = $this->page($sent)->assertOk()->getContent();

        $this->assertStringContainsString('data-role="already-signed"', $html);
        $this->assertStringNotContainsString('data-role="sign-form"', $html);
        $this->assertStringNotContainsString('href="#sign"', $html);
        $this->assertStringNotContainsString('data-role="summary-note"', $html);
        $this->assertMatchesRegularExpression('/data-role="summary-status">Signed/', $html);
        $this->assertStringNotContainsString('data-role="paid-confirmation"', $html);
    }

    public function test_paid_page_shows_paid_only_from_persisted_paid_state(): void
    {
        $sent = $this->sent();
        $this->sign($sent)->assertOk();
        \Illuminate\Support\Facades\DB::table('business_documents')->where('id', $sent[1]->id)->update(['status' => 'paid', 'paid_at' => now()]);

        $html = $this->page($sent)->assertOk()->getContent();

        $this->assertStringContainsString('data-role="paid-confirmation"', $html);
        $this->assertStringContainsString('Payment received', $html);
        $this->assertMatchesRegularExpression('/data-role="summary-status">Paid</', $html);
        $this->assertStringNotContainsString('data-role="pay-button"', $html);
    }

    public function test_invalid_wrong_token_and_expired_links_still_get_the_one_uniform_refusal(): void
    {
        $sent = $this->sent();
        $this->branded($sent[0], '#112233');

        $wrong = $this->get($this->publicUrl($sent[1], Str::random(64)))->assertNotFound();
        $unknown = $this->get(route('public.documents.show', ['uid' => 'nope', 'token' => Str::random(64)]))->assertNotFound();
        \Illuminate\Support\Facades\DB::table('business_documents')->where('id', $sent[1]->id)->update(['access_token_expires_at' => now()->subDay()]);
        $expired = $this->get($this->publicUrl($sent[1], $sent[2]))->assertNotFound();
        \Illuminate\Support\Facades\DB::table('business_documents')->where('id', $sent[1]->id)->update(['access_token_expires_at' => now()->addDay(), 'status' => 'void']);
        $void = $this->get($this->publicUrl($sent[1], $sent[2]))->assertNotFound();

        $this->assertSame($wrong->getContent(), $unknown->getContent());
        $this->assertSame($wrong->getContent(), $expired->getContent());
        $this->assertSame($wrong->getContent(), $void->getContent());
        $this->assertStringNotContainsString('Scary Photos', $expired->getContent());
        $this->assertStringNotContainsString('#112233', $expired->getContent());
    }

    // -- escaping ----------------------------------------------------------------------

    public function test_user_authored_content_is_escaped_everywhere_on_the_page(): void
    {
        $sent = $this->sent('Evil <script>alert(1)</script> Co', ['title' => '<img src=x onerror=alert(1)>'], blocks: true);

        $html = $this->page($sent)->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringNotContainsString('<b onclick="x()">', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringContainsString('Evil &lt;script&gt;alert(1)&lt;/script&gt; Co', $html);
    }

    public function test_legacy_documents_get_the_same_shell_and_escape_their_body(): void
    {
        $sent = $this->sent('Legacy Co', ['title' => 'Legacy <i>title</i>'], blocks: false);
        $this->branded($sent[0], '#112233');

        $html = $this->page($sent)->assertOk()->getContent();

        $this->assertStringContainsString('--pd-accent:#112233', $html);
        $this->assertStringContainsString('data-role="lines"', $html);
        $this->assertStringContainsString('data-role="sign-form"', $html);
        $this->assertStringNotContainsString('doc-blocks', $html);
        $this->assertStringContainsString('Legacy &lt;i&gt;title&lt;/i&gt;', $html);
        $this->assertStringNotContainsString('Legacy <i>title</i>', $html);
    }
}
