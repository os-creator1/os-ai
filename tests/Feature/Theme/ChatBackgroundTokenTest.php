<?php

namespace Tests\Feature\Theme;

use Tests\TestCase;

/**
 * Design System M2 Slice 1 contract §6.17/§9 item 81. The embedded
 * base64 chat background patterns are removed structurally -- never
 * reintroduced for any preset -- and the surrounding surfaces are
 * token-driven.
 */
class ChatBackgroundTokenTest extends TestCase
{
    public function test_chat_bg_variables_no_longer_exist_in_bootstrap_variables(): void
    {
        $source = file_get_contents(base_path('resources/scss/base/bootstrap-extended/_variables.scss'));

        $this->assertDoesNotMatchRegularExpression('/\$chat-bg-light\s*:/', $source);
        $this->assertDoesNotMatchRegularExpression('/\$chat-bg-dark\s*:/', $source);
    }

    public function test_no_scss_file_still_references_the_removed_chat_bg_variables(): void
    {
        foreach ([
            'resources/scss/base/pages/app-chat.scss',
            'resources/scss/base/pages/app-chat-list.scss',
            'resources/scss/base/themes/dark-layout.scss',
        ] as $relativePath) {
            $source = file_get_contents(base_path($relativePath));
            $this->assertStringNotContainsString('url($chat-bg-light)', $source, "{$relativePath} still references the removed pattern.");
            $this->assertStringNotContainsString('url($chat-bg-dark)', $source, "{$relativePath} still references the removed pattern.");
        }
    }

    public function test_light_mode_chat_canvas_is_token_driven(): void
    {
        $source = file_get_contents(base_path('resources/scss/base/pages/app-chat.scss'));

        $this->assertMatchesRegularExpression('/\$chat-image-back-color\s*:\s*\$color-canvas/', $source);
    }
}
