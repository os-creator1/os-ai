<?php

namespace App\Providers;

use App\Library\Assistant\AssistantToolRegistry;
use App\Library\Assistant\Tools;
use Illuminate\Support\ServiceProvider;

/**
 * Ask MotionGrove — the closed tool registry. A capability exists only if it is listed here; adding one is a code
 * change reviewed like any other, never something a customer, a record or the model can do.
 */
class AssistantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AssistantToolRegistry::class, fn ($app): AssistantToolRegistry => new AssistantToolRegistry(
            [
                $app->make(Tools\BusinessSnapshotTool::class),
                $app->make(Tools\UnansweredConversationsTool::class),
                $app->make(Tools\ScheduleTool::class),
                $app->make(Tools\GrowthRecommendationsTool::class),
                $app->make(Tools\RecentWorkTool::class),
                $app->make(Tools\SeoStatusTool::class),
                $app->make(Tools\WebsiteAndListingsTool::class),
                $app->make(Tools\AdsStatusTool::class),
                $app->make(Tools\AutomationsStatusTool::class),
                $app->make(Tools\ContactSearchTool::class),
                $app->make(Tools\OpenPageTool::class),
            ],
            [
                $app->make(Tools\CreateBookingTypeTool::class),
                $app->make(Tools\CreateAppointmentTool::class),
                $app->make(Tools\DraftReplyTool::class),
                $app->make(Tools\PrepareAutomationTool::class),
            ],
        ));
    }
}
