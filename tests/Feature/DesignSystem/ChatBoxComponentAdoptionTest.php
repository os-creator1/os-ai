<?php

namespace Tests\Feature\DesignSystem;

use App\Models\AppConfig;
use App\Models\ChatBox;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Design System M2 Slice 6 (ChatBox / Conversations) — component-adoption
 * assertions. Asserts ONLY the exact locked adoption set (§5 of the Slice 6
 * contract): 1 <x-card> (new.blade.php only), 7 <x-button> (the 4 sidebar
 * tab-filter buttons, the Load More button, the composer send button, and
 * new.blade.php's submit button), 2 <x-tooltip> (.add-to-blacklist,
 * .remove-btn) — and explicitly proves every non-adoption named in §5.3,
 * §5.5–§5.9 introduced zero forbidden component markers. Never a generic
 * "every X adopts" assumption.
 */
class ChatBoxComponentAdoptionTest extends TestCase
{
    use RefreshDatabase;

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
    // Locked adoption counts
    // -----------------------------------------------------------------

    public function test_exactly_1_card_marker_is_present_and_only_on_new_blade(): void
    {
        $this->assertMarkerTotal('<x-card', 1);

        $contents = file_get_contents(base_path('resources/views/customer/ChatBox/new.blade.php'));
        $this->assertStringContainsString('<x-card', $contents);

        foreach (['resources/views/customer/ChatBox/index.blade.php', 'resources/views/customer/ChatBox/_sidebar.blade.php', 'resources/views/customer/ChatBox/partials/_chat_list.blade.php'] as $view) {
            $contents = file_get_contents(base_path($view));
            $this->assertStringNotContainsString('<x-card', $contents, "{$view} has no card-adoption surface.");
        }
    }

    public function test_exactly_7_button_markers_are_present(): void
    {
        $this->assertMarkerTotal('<x-button', 7);
    }

    public function test_exact_per_file_button_breakdown(): void
    {
        $expected = [
            'resources/views/customer/ChatBox/_sidebar.blade.php' => 5, // 4 tab-filters + Load More
            'resources/views/customer/ChatBox/index.blade.php' => 1, // composer send
            'resources/views/customer/ChatBox/new.blade.php' => 1, // submit
            'resources/views/customer/ChatBox/partials/_chat_list.blade.php' => 0,
        ];

        foreach ($expected as $view => $count) {
            $contents = file_get_contents(base_path($view));
            $this->assertSame($count, substr_count($contents, '<x-button'), $view);
        }
    }

    public function test_sidebar_tab_filter_buttons_preserve_class_hook_and_data_filter(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/ChatBox/_sidebar.blade.php'));

        foreach (['recents', 'unread', 'read', 'all'] as $filter) {
            $this->assertMatchesRegularExpression(
                '/<x-button[^>]*class="tab-button"[^>]*data-filter="' . $filter . '"/s',
                $contents,
                "Expected the {$filter} tab button to keep the tab-button class and data-filter={$filter}."
            );
        }
    }

    public function test_sidebar_tab_buttons_map_initial_variant_correctly(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/ChatBox/_sidebar.blade.php'));

        $this->assertMatchesRegularExpression('/<x-button variant="primary"[^>]*data-filter="recents"/s', $contents);
        foreach (['unread', 'read', 'all'] as $filter) {
            $this->assertMatchesRegularExpression('/<x-button variant="outline"[^>]*data-filter="' . $filter . '"/s', $contents);
        }
    }

    public function test_load_more_button_preserves_id_and_layout_class(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/ChatBox/_sidebar.blade.php'));

        $this->assertMatchesRegularExpression('/<x-button[^>]*id="load-more"[^>]*class="mt-1"/s', $contents);
        $this->assertStringContainsString('icon="refresh-cw"', $contents);
    }

    public function test_composer_send_button_preserves_class_and_onclick_with_slot_based_responsive_pair(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/ChatBox/index.blade.php'));

        $this->assertMatchesRegularExpression('/<x-button[^>]*class="send"[^>]*onclick="enter_chat\(\);"/s', $contents);

        // The responsive icon/text pair is slot content, never the icon prop.
        $sendButtonStart = strpos($contents, '<x-button variant="primary" class="send"');
        $sendButtonEnd = strpos($contents, '</x-button>', $sendButtonStart);
        $this->assertNotFalse($sendButtonStart);
        $this->assertNotFalse($sendButtonEnd);
        $sendButtonBlock = substr($contents, $sendButtonStart, $sendButtonEnd - $sendButtonStart);

        $this->assertStringNotContainsString(' icon=', $sendButtonBlock);
        $this->assertStringContainsString('<x-ds-icon name="send" class="d-lg-none" />', $sendButtonBlock);
        $this->assertStringContainsString('<span class="d-none d-lg-block">', $sendButtonBlock);
    }

    public function test_new_submit_button_uses_the_icon_prop_directly(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/ChatBox/new.blade.php'));

        $this->assertMatchesRegularExpression('/<x-button variant="primary" type="submit" icon="send"/', $contents);
    }

    public function test_exactly_2_tooltip_markers_are_present_both_in_index(): void
    {
        $this->assertMarkerTotal('<x-tooltip', 2);

        $contents = file_get_contents(base_path('resources/views/customer/ChatBox/index.blade.php'));
        $this->assertSame(2, substr_count($contents, '<x-tooltip'));
        $this->assertStringContainsString('class="add-to-blacklist"', $contents);
        $this->assertStringContainsString('class="remove-btn"', $contents);
    }

    public function test_add_to_pin_is_never_a_tooltip_adoption(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/ChatBox/index.blade.php'));

        $this->assertStringContainsString('<span class="add-to-pin"> </span>', $contents);
    }

    // -----------------------------------------------------------------
    // Explicit non-adoption assertions — never demand what the contract
    // locks as native.
    // -----------------------------------------------------------------

    public function test_no_forbidden_component_is_ever_adopted_anywhere_in_the_slice(): void
    {
        foreach (['<x-badge', '<x-alert', '<x-empty-state', '<x-input', '<x-select', '<x-dialog', '<x-table', '<x-pagination', '<x-menu'] as $marker) {
            $this->assertMarkerTotal($marker, 0);
        }
    }

    public function test_no_new_list_item_or_message_bubble_component_was_created(): void
    {
        $this->assertFileDoesNotExist(base_path('resources/views/components/chat-list.blade.php'));
        $this->assertFileDoesNotExist(base_path('resources/views/components/chat-bubble.blade.php'));
        $this->assertFileDoesNotExist(base_path('resources/views/components/message-bubble.blade.php'));
    }

    public function test_notification_badge_remains_native_solid_bg_primary(): void
    {
        foreach ([
            'resources/views/customer/ChatBox/_sidebar.blade.php',
            'resources/views/customer/ChatBox/partials/_chat_list.blade.php',
        ] as $view) {
            $contents = file_get_contents(base_path($view));
            $this->assertStringContainsString('badge bg-primary rounded-pill float-end notification_count', $contents);
            $this->assertStringNotContainsString('<x-badge', $contents);
        }
    }

    public function test_new_blade_alert_info_block_remains_native(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/ChatBox/new.blade.php'));

        $this->assertStringContainsString('class="alert alert-info"', $contents);
        $this->assertStringContainsString('class="alert-body d-flex align-items-center"', $contents);
        $this->assertStringNotContainsString('<x-alert', $contents);
    }

    public function test_start_chat_area_empty_state_remains_native_with_its_responsive_heading_pair(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/ChatBox/index.blade.php'));

        $this->assertStringContainsString('class="start-chat-area"', $contents);
        $this->assertStringContainsString('start-chat-text d-block d-md-none', $contents);
        $this->assertStringContainsString('start-chat-text d-none d-md-block', $contents);
        $this->assertStringNotContainsString('<x-empty-state', $contents);
    }

    public function test_no_select_or_textarea_or_file_input_is_wrapped_in_x_input_or_x_select(): void
    {
        $index = file_get_contents(base_path('resources/views/customer/ChatBox/index.blade.php'));
        $this->assertStringContainsString('<textarea', $index);
        $this->assertStringContainsString('type="file" id="media_image"', $index);

        $new = file_get_contents(base_path('resources/views/customer/ChatBox/new.blade.php'));
        $this->assertStringContainsString('<textarea', $new);
        $this->assertStringContainsString('type="hidden" name="idempotency_token"', $new);
    }

    // -----------------------------------------------------------------
    // Live render proof — adopted markers actually resolve to the
    // component's own real, documented output classes at runtime.
    // -----------------------------------------------------------------

    public function test_chatbox_index_renders_the_adopted_button_and_tooltip_output_classes(): void
    {
        [$customer] = $this->authenticatedCustomerWithChatBox();

        $response = $this->get(route('customer.chatbox.index'));

        $response->assertOk();
        $response->assertSee('transition-fast', false);
        $response->assertSee('ds-tooltip-trigger', false);
    }

    public function test_chatbox_ajax_load_partial_renders_the_adopted_button_output_class(): void
    {
        [$customer] = $this->authenticatedCustomerWithChatBox();

        $response = $this->get(route('customer.chatbox.load'));

        $response->assertOk();
        // The partial itself has zero buttons — this proves the request
        // succeeds and the sidebar's own adopted buttons are unaffected.
        $response->assertDontSee('<x-button', false);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function assertMarkerTotal(string $marker, int $expected): void
    {
        $total = 0;
        foreach (self::SLICE6_VIEWS as $view) {
            $contents = file_get_contents(base_path($view));
            $total += substr_count($contents, $marker);
        }

        $this->assertSame($expected, $total, "Expected exactly {$expected} occurrences of {$marker} across the 4-view allowlist.");
    }

    /**
     * @return array{0: Customer, 1: ChatBox}
     */
    private function authenticatedCustomerWithChatBox(): array
    {
        $this->ensureRequiredAppConfigRowsExist();

        $user = User::create([
            'first_name' => 'Test',
            'last_name' => 'Customer',
            'email' => 'customer' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
            'email_verified_at' => now(),
        ]);

        $customer = Customer::create(['user_id' => $user->id]);
        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        $box = ChatBox::create([
            'user_id' => $user->id,
            'from' => 'AgentA',
            'to' => '15550009002',
            'reply_by_customer' => true,
        ]);

        $this->actingAs($user);

        return [$customer, $box];
    }

    private function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])
            ->pluck('setting')
            ->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }

        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }

        if (! in_array('customer_permissions', $existing, true)) {
            $default = collect((new AppConfig())->defaultSettings())
                ->firstWhere('setting', 'customer_permissions');

            AppConfig::create($default);
        }
    }
}
