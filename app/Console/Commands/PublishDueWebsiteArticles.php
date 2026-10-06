<?php

namespace App\Console\Commands;

use App\Library\Seo\Content\ArticleManager;
use Illuminate\Console\Command;

/**
 * SEO Content Engine V1 — scheduled publishing. Only the owner's own schedule can make an article
 * public here: ArticleManager::publishDue() publishes the Scheduled articles whose time has come and
 * RE-CHECKS each one first, returning any that no longer passes (a removed catalog price, an edit that
 * introduced a blocker) to Draft instead of going live. This command adds nothing to that: it only
 * runs it and says what happened. No AI and no provider call.
 */
class PublishDueWebsiteArticles extends Command
{
    protected $signature = 'articles:publish-due';

    protected $description = 'Publish website articles whose scheduled time has arrived (re-checked first)';

    public function handle(ArticleManager $articles): int
    {
        $result = $articles->publishDue();

        $this->info("Published {$result['published']} article(s); returned {$result['returned_to_draft']} to draft for review.");

        return self::SUCCESS;
    }
}
