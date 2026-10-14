<?php

namespace Tests\Feature\Assistant\Concerns;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Ai\AiCompletionResult;
use App\Library\Ai\Contracts\AiCompletionClient;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Models\Business;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;

/**
 * Fixtures for Ask MotionGrove. NO real provider is ever reached: AiCompletionClient is bound to the fake, and any
 * stray HTTP request fails the test.
 */
trait AssistantTestSupport
{
    use CreatesDashboardFixtures;

    protected FakeAiCompletionClient $fake;

    protected function bootAssistant(): void
    {
        $this->freezeClock();
        config(['services.openai.active' => true, 'opportunity.enabled' => true]);
        $this->fake = new FakeAiCompletionClient();
        $this->app->instance(AiCompletionClient::class, $this->fake);
        Http::preventStrayRequests();
    }

    /** @param array<string, mixed> $json what the (fake) model returns */
    protected function modelSays(array $json, int $in = 1500, int $out = 120): void
    {
        $this->fake->queueResult(AiCompletionResult::success((string) json_encode($json), 'gpt-4o-mini-2026', $in, $out));
    }

    protected function modelFails(): void
    {
        $this->fake->queueResult(AiCompletionResult::failure());
    }

    protected function modelReturnsRaw(string $raw): void
    {
        $this->fake->queueResult(AiCompletionResult::success($raw, 'gpt-4o-mini-2026', 800, 40));
    }

    protected function assistantUrl(Workspace $workspace, Business $business, string $route = 'message', array $extra = []): string
    {
        return route('customer.workspaces.businesses.home.assistant.' . $route, array_merge([$workspace->uid, $business->uid], $extra));
    }

    protected function say(Workspace $workspace, Business $business, string $question, array $extra = []): TestResponse
    {
        return $this->postJson($this->assistantUrl($workspace, $business), array_merge([
            'q' => $question,
            'client_message_id' => (string) Str::uuid(),
        ], $extra));
    }

    /** @return array<string, mixed> the last assistant message of a response */
    protected function lastReply(TestResponse $response): array
    {
        $messages = $response->json('messages');

        return end($messages);
    }

    protected function onTrial(Workspace $workspace, ?\DateTimeInterface $endsAt = null): void
    {
        \App\Models\WorkspacePlanAssignment::query()->where('workspace_id', $workspace->id)->update([
            'trial_ends_at' => $endsAt ?? now()->addDays(10),
            'is_complimentary' => false,
        ]);
    }

    protected function aiCalls(): int
    {
        return $this->fake->callCount();
    }
}
