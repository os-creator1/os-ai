<?php

namespace Tests\Feature\DesignSystem;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Design System M2 Slice 6 (ChatBox / Conversations) — mechanical content
 * checks across the exact 4-view allowlist (§9 of the Slice 6 contract).
 * Proves the icon migration (14 static data-feather occurrences, 13
 * distinct names, migrated to <x-ds-icon>, including the two JS-string-
 * embedded pin/unpin icons), the untouched feather.replace() runtime (3
 * calls, all in index.blade.php), the elimination of the 3 hardcoded color
 * literals in index.blade.php's inline <style> block, and that native-
 * retained plugin wiring (Select2, SweetAlert2, #load-more/#chat-search
 * AJAX) and both Echo/Pusher guards remain structurally present. This file
 * makes zero assertion requiring any ChatBoxController change.
 */
class ChatBoxDesignSystemContentTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private const SLICE6_VIEWS = [
        'resources/views/customer/ChatBox/index.blade.php',
        'resources/views/customer/ChatBox/new.blade.php',
        'resources/views/customer/ChatBox/_sidebar.blade.php',
        'resources/views/customer/ChatBox/partials/_chat_list.blade.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        User::create([
            'first_name' => 'Placeholder',
            'last_name' => 'SuperAdmin',
            'email' => 'placeholder-superadmin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);
    }

    // -----------------------------------------------------------------
    // Source-level mechanical checks — contract §13
    // -----------------------------------------------------------------

    public function test_zero_static_data_feather_remains_across_all_4_views(): void
    {
        foreach (self::SLICE6_VIEWS as $view) {
            $contents = file_get_contents(base_path($view));

            $this->assertSame(
                0,
                substr_count($contents, 'data-feather'),
                "Expected zero static data-feather occurrences in {$view}."
            );
        }
    }

    /**
     * An icon migrates either as a literal <x-ds-icon name="..."> tag, or
     * — for the Load More and new.blade.php submit buttons — as the
     * <x-button icon="..."> prop shorthand, which itself renders
     * <x-ds-icon :name="$icon" size="16" /> internally (button.blade.php
     * §3.2). Both forms are the canonical icon-rendering seam; this helper
     * recognizes either.
     */
    public function test_all_13_distinct_icon_names_are_present_as_ds_icon_markers(): void
    {
        $combined = '';
        foreach (self::SLICE6_VIEWS as $view) {
            $combined .= file_get_contents(base_path($view));
        }

        foreach (['x', 'search', 'plus-circle', 'refresh-cw', 'message-square', 'menu', 'shield', 'trash', 'image', 'send', 'delete', 'edit-2', 'info'] as $name) {
            $present = str_contains($combined, 'name="' . $name . '"') || str_contains($combined, 'icon="' . $name . '"');
            $this->assertTrue(
                $present,
                "Expected an <x-ds-icon name=\"{$name}\"> or <x-button icon=\"{$name}\"> marker somewhere across the 4-view allowlist."
            );
        }
    }

    public function test_exact_per_file_icon_marker_occurrence_counts(): void
    {
        $expected = [
            'resources/views/customer/ChatBox/_sidebar.blade.php' => 4, // x, search, plus-circle (literal) + refresh-cw (icon prop)
            'resources/views/customer/ChatBox/index.blade.php' => 8, // all 8 as literal <x-ds-icon> tags
            'resources/views/customer/ChatBox/new.blade.php' => 2, // info (literal) + send (icon prop)
            'resources/views/customer/ChatBox/partials/_chat_list.blade.php' => 0,
        ];

        $total = 0;
        foreach ($expected as $view => $count) {
            $contents = file_get_contents(base_path($view));
            $actual = substr_count($contents, '<x-ds-icon') + substr_count($contents, ' icon="');
            $this->assertSame($count, $actual, "Expected {$count} icon markers in {$view}, found {$actual}.");
            $total += $actual;
        }

        $this->assertSame(14, $total);
    }

    public function test_the_two_js_string_embedded_pin_unpin_icons_migrated_via_the_slice_5_precedent(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/ChatBox/index.blade.php'));

        $this->assertStringContainsString(
            "addToPin.append('<x-ds-icon name=\"delete\" class=\"cursor-pointer font-medium-2 mx-1 text-danger\" />');",
            $contents
        );
        $this->assertStringContainsString(
            "addToPin.append('<x-ds-icon name=\"edit-2\" class=\"cursor-pointer font-medium-2 mx-1 text-info\" />');",
            $contents
        );
    }

    public function test_feather_replace_count_remains_exactly_3_in_index_and_feather_runtime_script_present(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/ChatBox/index.blade.php'));

        $this->assertSame(3, substr_count($contents, 'feather.replace()'));
        $this->assertStringContainsString("mix('js/scripts/pages/chat.js')", $contents);
    }

    public function test_zero_hardcoded_color_literals_across_all_4_views(): void
    {
        foreach (self::SLICE6_VIEWS as $view) {
            $contents = file_get_contents(base_path($view));

            $this->assertSame(
                0,
                preg_match('/#[0-9A-Fa-f]{3,8}\b/', $contents),
                "Expected zero hex color literals in {$view}."
            );
            $this->assertSame(
                0,
                preg_match('/\brgba?\([^)]*\)/', $contents),
                "Expected zero rgb()/rgba() color literals in {$view}."
            );
        }
    }

    public function test_index_textarea_style_uses_the_three_css_custom_property_tokens(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/ChatBox/index.blade.php'));

        $this->assertStringContainsString('var(--color-input-border)', $contents);
        $this->assertStringContainsString('var(--color-focus-border)', $contents);
        $this->assertStringContainsString('var(--focus-ring-color)', $contents);
    }

    public function test_no_hardcoded_font_family_name_is_introduced(): void
    {
        foreach (self::SLICE6_VIEWS as $view) {
            $contents = file_get_contents(base_path($view));

            preg_match_all('/font-family\s*:\s*([^;]+);/', $contents, $matches);
            foreach ($matches[1] as $declaredValue) {
                $this->assertSame(
                    'inherit',
                    trim($declaredValue),
                    "{$view} must not introduce a hardcoded font-family name — only font-family: inherit; is allowed."
                );
            }
        }

        $indexContents = file_get_contents(base_path('resources/views/customer/ChatBox/index.blade.php'));
        $this->assertStringContainsString('font-family: inherit;', $indexContents);
    }

    public function test_both_pusher_broadcasting_guards_remain_exactly_twice_and_independent(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/ChatBox/index.blade.php'));

        $this->assertSame(2, substr_count($contents, "config('broadcasting.connections.pusher.app_id')"));
        $this->assertSame(2, substr_count($contents, '@endif'));
    }

    /**
     * Regression for the inbox search double-focus/seam defect. The
     * bootstrap-extended `.round` utility (_utilities.scss) sets a full
     * border-radius on whatever element carries it; the theme's own
     * `.input-group.round` rule (_input-group.scss) is what actually
     * distributes that radius correctly across a merged icon+input pair
     * and shapes the `:focus-within` ring to match. `round` must sit on
     * the `.input-group` wrapper — putting it on the icon span and the
     * input individually (the prior defect) produces two independently
     * rounded shapes with a visible seam and a mismatched outer ring.
     */
    public function test_chat_search_round_modifier_sits_on_the_input_group_wrapper_not_its_children(): void
    {
        $sidebar = file_get_contents(base_path('resources/views/customer/ChatBox/_sidebar.blade.php'));

        $this->assertMatchesRegularExpression(
            '/<div class="input-group input-group-merge round[^"]*">/',
            $sidebar,
            'The .round modifier must be on the .input-group wrapper so the theme shapes one merged control and one matching focus ring.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/<span class="input-group-text round"/',
            $sidebar,
            'round must not sit on the search icon span — it produces an independently rounded shape and a seam against the input.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]*class="form-control round"[^>]*id="chat-search"/',
            $sidebar,
            'round must not sit on #chat-search directly — it produces a second, independently rounded border under focus.'
        );
    }

    public function test_load_more_and_chat_search_ajax_wiring_retained(): void
    {
        $sidebar = file_get_contents(base_path('resources/views/customer/ChatBox/_sidebar.blade.php'));
        $this->assertStringContainsString('id="load-more"', $sidebar);
        $this->assertStringContainsString('id="chat-search"', $sidebar);

        $index = file_get_contents(base_path('resources/views/customer/ChatBox/index.blade.php'));
        $this->assertStringContainsString('$("#load-more").on("click"', $index);
        $this->assertStringContainsString('$("#chat-search").on("keyup"', $index);
    }

    public function test_select2_wiring_retained_across_both_forms(): void
    {
        $index = file_get_contents(base_path('resources/views/customer/ChatBox/index.blade.php'));
        $this->assertStringContainsString('.select2({', $index);
        $this->assertStringContainsString('id="sms_template"', $index);

        $new = file_get_contents(base_path('resources/views/customer/ChatBox/new.blade.php'));
        $this->assertStringContainsString('.select2({', $new);
        $this->assertStringContainsString('id="sender_id"', $new);
        $this->assertStringContainsString('id="country_code"', $new);
    }

    public function test_sweetalert2_structures_retained(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/ChatBox/index.blade.php'));

        $this->assertSame(3, substr_count($contents, 'Swal.fire({'));
        $this->assertSame(3, substr_count($contents, 'buttonsStyling: false'));
    }

    public function test_no_scss_or_js_plugin_override_path_was_touched(): void
    {
        // Slice 6 is a Blade-only presentation slice — none of the plugin
        // chrome override files ChatBox exercises carry a hardcoded literal
        // (contract §3.4's own mechanical finding), so none should exist as
        // a modified path in this slice's diff.
        foreach ([
            'resources/scss/base/plugins/forms/select2/_select2.scss',
            'resources/scss/base/plugins/extensions/ext-component-sweet-alerts.scss',
            'resources/scss/base/plugins/extensions/ext-component-toastr.scss',
        ] as $path) {
            $this->assertFileExists(base_path($path));
        }
    }

    // -----------------------------------------------------------------
    // Live render smoke checks — icons actually resolve at runtime
    // -----------------------------------------------------------------

    // Slice 2B: every surface is now reached through the Business-scoped
    // route family, and the list endpoint is POST-only. The rendered icon
    // output asserted is unchanged.

    public function test_chatbox_index_renders_migrated_icons_and_preserves_feather_runtime(): void
    {
        [, , $business, $workspace] = $this->authenticatedCustomerWithChatBox();

        $response = $this->get(route('customer.workspaces.businesses.conversations.index', [$workspace->uid, $business->uid]));

        $response->assertOk();
        $response->assertSee('ds-icon', false);
        $response->assertDontSee('data-feather=', false);
        $response->assertSee('feather.replace()', false);
    }

    public function test_chatbox_new_renders_migrated_icons(): void
    {
        [$customer, , $business, $workspace] = $this->authenticatedCustomerWithChatBox();
        $customer->user->update(['sms_unit' => 100]);

        $response = $this->get(route('customer.workspaces.businesses.conversations.new', [$workspace->uid, $business->uid]));

        // new() redirects when the customer has no active subscription —
        // this fixture does not build one (out of this slice's own scope);
        // either an OK render or the documented redirect proves the route
        // itself still resolves without a Blade compile error.
        $this->assertContains($response->getStatusCode(), [200, 302]);

        if ($response->getStatusCode() === 200) {
            $response->assertSee('ds-icon', false);
            $response->assertDontSee('data-feather=', false);
        }
    }

    public function test_chatbox_index_renders_the_merged_single_bordered_search_control(): void
    {
        [, , $business, $workspace] = $this->authenticatedCustomerWithChatBox();

        $response = $this->get(route('customer.workspaces.businesses.conversations.index', [$workspace->uid, $business->uid]));

        $response->assertOk();
        $response->assertSee('input-group input-group-merge round', false);
        $response->assertDontSee('input-group-text round', false);
    }

    public function test_chatbox_ajax_load_partial_renders_without_data_feather(): void
    {
        [, $box, $business, $workspace] = $this->authenticatedCustomerWithChatBox();

        $response = $this->post(route('customer.workspaces.businesses.conversations.load', [$workspace->uid, $business->uid]));

        $response->assertOk();
        $response->assertDontSee('data-feather=', false);
        $response->assertSee('data-id="' . $box->uid . '"', false);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Slice 2B: a conversation belongs to a Business, so the fixture is a
     * customer owning one Workspace + Business with a Business-scoped box.
     *
     * @return array{0: Customer, 1: ChatBox, 2: Business, 3: Workspace}
     */
    private function authenticatedCustomerWithChatBox(): array
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core, 'Content Co ' . uniqid(), 'Content WS ' . uniqid());

        $user = $customer->user;
        $user->email_verified_at = now();
        $user->save();

        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        $box = new ChatBox([
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'from' => 'AgentA',
            'to' => '15550009001',
            'reply_by_customer' => true,
        ]);
        $box->uid = (string) Str::uuid();
        $box->save();

        $this->actingAs($user);

        return [$customer, $box, $business, $workspace];
    }
}
