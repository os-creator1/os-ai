<?php

namespace Tests\Feature\AgencyOutreach;

use App\Library\AgencyOutreach\OutreachConversationLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\Feature\AgencyOutreach\Concerns\CreatesOutreachUiFixtures;
use Tests\TestCase;

/** Real-application render regressions for the eight redesigned Outreach states. */
class OutreachRedesignRenderTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOutreachUiFixtures;

    public function test_eight_outreach_surfaces_boot_under_real_app_routes(): void
    {
        $a = $this->outreachAgency('Alpha');
        $this->authenticateAs($a['customer']);

        foreach (['overview', 'prospects.index', 'conversations.index', 'campaigns.index',
            'script.show', 'phone-messaging'] as $name) {
            $this->get($this->outreachRoute($name, $a['workspace']))->assertOk();
        }

        $detail = $this->outreachRoute('campaigns.show', $a['workspace'], [$a['campaign']->uid]);
        $this->get($detail)->assertOk();
        $a['campaign']->update(['status' => 'active']);
        $this->get($detail)->assertOk()->assertSee('data-role="campaign-launched"', false);
    }

    public function test_conversation_links_keep_eloquent_models_inside_real_paginators(): void
    {
        $a = $this->outreachAgency('Bravo');
        $this->authenticateAs($a['customer']);
        $member = $a['member'];
        $links = app(OutreachConversationLinks::class);

        $this->assertSame([$member->id => null], $links->forMembers(
            $a['workspace'], new LengthAwarePaginator([$member], 1, 20)
        ));
        $this->assertSame([], $links->forMembers(
            $a['workspace'], new LengthAwarePaginator([], 0, 20)
        ));
        $this->get($this->outreachRoute('conversations.index', $a['workspace']))->assertOk();
    }

    public function test_prospect_dialog_is_not_hidden_by_global_bootstrap_modal_css(): void
    {
        $a = $this->outreachAgency('Charlie');
        $this->authenticateAs($a['customer']);

        $html = $this->get($this->outreachRoute('prospects.index', $a['workspace']))->assertOk()->getContent();
        $this->assertStringContainsString('<dialog class="og-dialog" id="og-add-prospect"', $html);
        $this->assertStringNotContainsString('<dialog class="modal"', $html);
        $css = file_get_contents(public_path('css/outreach/outreach.css'));
        $this->assertStringContainsString('dialog.og-dialog', $css);
    }
}
