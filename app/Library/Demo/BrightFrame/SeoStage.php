<?php

namespace App\Library\Demo\BrightFrame;

use App\Library\Seo\Content\ArticleManager;
use App\Library\Seo\Rank\SeoRankTargetManager;
use App\Library\Seo\Rank\SeoRankTrackingBudget;
use App\Library\Seo\SeoAuditRunner;
use App\Library\Seo\SeoCitationManager;
use App\Library\Seo\SeoKeywordManager;
use App\Library\Seo\SeoReviewLinkManager;
use App\Library\Seo\SeoReviewRequestManager;
use App\Models\SeoCitation;
use App\Models\SeoKeyword;
use App\Models\SeoRankTarget;
use App\Models\SeoReviewRequest;
use App\Models\WebsiteArticle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SEO: keywords, rank-tracking history (SIMULATED), a real site audit of the
 * published demo website, citations, review link/requests and blog posts.
 *
 * Rank history is inserted rather than fetched. Targets are marked "tracking"
 * but their next check is pushed years out, so the scheduler never picks them
 * up and no rank provider (a paid service) is ever called.
 */
final class SeoStage implements DemoStage
{
    private const KEYWORDS = [
        // phrase => [organic start, organic now, local start, local now]
        'photo booth rental charlotte' => [18, 6, 9, 3],
        'wedding photo booth charlotte nc' => [24, 9, 11, 4],
        '360 photo booth rental charlotte' => [31, 12, 12, 5],
        'corporate event photo booth' => [27, 14, 14, 7],
        'glam mirror photo booth' => [40, 19, 15, 8],
        'prom photo booth rental' => [35, 21, 16, 10],
    ];

    public function __construct(
        private readonly SeoKeywordManager $keywords,
        private readonly SeoRankTargetManager $targets,
        private readonly SeoRankTrackingBudget $budget,
        private readonly SeoAuditRunner $audits,
        private readonly SeoCitationManager $citations,
        private readonly SeoReviewLinkManager $reviewLinks,
        private readonly SeoReviewRequestManager $reviewRequests,
        private readonly ArticleManager $articles,
    ) {
    }

    public function run(DemoContext $context): void
    {
        $this->keywordsAndRank($context);
        $this->audit($context);
        $this->citationsAndReviews($context);
        $this->articles($context);
    }

    private function keywordsAndRank(DemoContext $context): void
    {
        $business = $context->business;
        $now = now();
        $provider = $this->budget->providerKey();

        $locationCode = 9_990_001;
        DB::table('seo_rank_locations')->updateOrInsert(
            ['provider' => $provider, 'location_code' => $locationCode],
            ['location_name' => 'Charlotte,North Carolina,United States', 'country_iso' => 'US', 'location_type' => 'City', 'created_at' => $now, 'updated_at' => $now],
        );

        foreach (self::KEYWORDS as $phrase => [$organicStart, $organicNow, $localStart, $localNow]) {
            $keyword = SeoKeyword::query()->where('business_id', $business->id)->where('phrase', $phrase)->first()
                ?? $this->keywords->create($context->user->id, $business, $phrase, $context->location);

            $target = $this->targets->track($context->user->id, $business, $keyword->uid, $locationCode);

            if (DB::table('seo_rank_observations')->where('seo_rank_target_id', $target->id)->exists()) {
                continue;
            }

            foreach (range(0, 8) as $week) {
                $when = $now->copy()->subWeeks(8 - $week)->setTime(5, 15);
                foreach (['organic' => [$organicStart, $organicNow], 'local' => [$localStart, $localNow]] as $type => [$from, $to]) {
                    $position = max(1, (int) round($from + ($to - $from) * ($week / 8) + (($week * 7 + strlen($phrase)) % 3) - 1));
                    $runId = DB::table('seo_rank_check_runs')->insertGetId([
                        'uid' => (string) Str::uuid(), 'business_id' => $business->id, 'workspace_id' => $context->workspace->id,
                        'seo_rank_target_id' => $target->id, 'check_type' => $type, 'trigger' => 'scheduled',
                        'idempotency_key' => 'demo-brightframe-' . $target->id . '-' . $type . '-' . $week, 'state' => 'completed',
                        'provider' => $provider, 'depth' => 100, 'attempts' => 1, 'poll_attempts' => 1, 'reserved_micros' => 0, 'actual_micros' => 0,
                        'started_at' => $when, 'submitted_at' => $when, 'completed_at' => $when, 'created_at' => $when, 'updated_at' => $when,
                    ]);
                    DB::table('seo_rank_observations')->insert([
                        'business_id' => $business->id, 'seo_rank_target_id' => $target->id, 'seo_rank_check_run_id' => $runId, 'check_type' => $type,
                        'status' => 'found', 'position' => $position, 'result_url' => 'https://brightframe-photobooths.example/',
                        'result_domain' => 'brightframe-photobooths.example', 'result_path' => '/', 'match_basis' => 'domain', 'depth_checked' => 100,
                        'provider' => $provider, 'search_location_code' => $locationCode, 'device' => 'mobile', 'checked_at' => $when,
                        'created_at' => $when, 'updated_at' => $when,
                    ]);
                }
            }

            // "Tracking" on screen, but never due: no scheduled check can ever reach a provider.
            SeoRankTarget::query()->whereKey($target->id)->update([
                'last_checked_at' => $now->copy()->subDays(2),
                'next_check_at' => $now->copy()->addYears(5),
            ]);
        }

        $context->note('seo_keywords', SeoKeyword::query()->where('business_id', $business->id)->count());
    }

    private function audit(DemoContext $context): void
    {
        $website = $context->refs['website']->fresh();

        if ($website->published_revision_id !== null) {
            $this->audits->runForRevision((int) $context->business->id, (int) $website->id, (int) $website->published_revision_id);
        }

        $context->note('seo_audit_runs', DB::table('seo_audit_runs')->where('business_id', $context->business->id)->count());
    }

    private function citationsAndReviews(DemoContext $context): void
    {
        $business = $context->business;
        $location = $context->location;

        $plan = [
            'bing_places' => ['listed', true], 'facebook_pages' => ['listed', true], 'apple_business' => ['in_progress', false], 'yelp' => ['listed', true],
            'data_axle' => ['needs_correction', true], 'foursquare' => ['listed', true], 'nextdoor_business' => ['not_started', false], 'bbb' => ['in_progress', false],
            'yellow_pages' => ['listed', true], 'weddingwire_the_knot' => ['listed', true], 'gigsalad' => ['not_started', false],
        ];

        foreach ($plan as $key => [$status, $hasListing]) {
            if (! DB::table('seo_citation_directories')->where('key', $key)->exists()) {
                continue;
            }
            if (SeoCitation::query()->where('business_id', $business->id)->whereHas('directory', fn ($q) => $q->where('key', $key))->exists()) {
                continue;
            }

            $this->citations->save($context->user->id, $business, $location->uid, $key, [
                'status' => $status,
                'listing_url' => $hasListing ? 'https://example.com/listings/brightframe-photo-booths/' . $key : null,
                'listed_name' => $hasListing ? 'BrightFrame Photo Booths' : null,
                'listed_phone' => $hasListing ? ($status === 'needs_correction' ? '(704) 555-0199' : BrightFrameData::PHONE) : null,
                'listed_website' => $hasListing ? BrightFrameData::WEBSITE : null,
                'last_verified_at' => now()->subDays(10)->toDateString(),
                'notes' => $status === 'needs_correction' ? 'Phone number is outdated on this directory.' : null,
            ]);
        }

        $this->reviewLinks->set($context->user->id, $business, $location->uid, 'https://example.com/review/brightframe-photo-booths');

        if (! SeoReviewRequest::query()->where('business_id', $business->id)->exists()) {
            $contacts = array_values($context->refs['contacts']);
            foreach ([[12, 'sms', 'reviewed'], [13, 'sms', 'reviewed'], [14, 'email', 'requested'], [15, 'sms', 'declined'], [11, 'in_person', 'requested']] as [$i, $channel, $outcome]) {
                $request = $this->reviewRequests->record($context->user, $business, $location->uid, $channel, $contacts[$i]->uid);
                if ($outcome !== 'requested') {
                    $this->reviewRequests->resolve($context->user->id, $business, $request->uid, $outcome);
                }
            }
        }

        $context->note('seo_citations', SeoCitation::query()->where('business_id', $business->id)->count());
        $context->note('seo_review_requests', SeoReviewRequest::query()->where('business_id', $business->id)->count());
    }

    private function articles(DemoContext $context): void
    {
        $website = $context->refs['website']->fresh();
        $posts = [
            ['how-much-does-a-photo-booth-cost-in-charlotte', 'How Much Does a Photo Booth Cost in Charlotte?', 'cost', 'Photo booth rental prices in Charlotte run from $699 to $1,299 for a full package depending on booth type, hours and extras. Here is what drives the price.',
                "Photo booth pricing in Charlotte depends on three things: the type of booth, how long you need it and what is included.\n\n## What most packages include\n\nA good rental covers a friendly attendant, studio lighting, unlimited captures and instant sharing by text, email or QR code.\n\n## Typical price ranges\n\n- **Essential:** $699 for two hours with a classic digital booth\n- **Signature:** $949 for three hours, your choice of booth and a custom template\n- **Luxe:** $1,299 for four hours, two booths and a roaming photographer\n\n## How to save\n\nBook early for popular Saturdays, choose a two-hour window for smaller events and skip add-ons you will not use.\n\n## Questions to ask before you book\n\nConfirm whether setup and teardown time is included, whether a custom photo template is designed for you and whether guests receive a private online gallery afterward. Ask how much space and power the booth needs so your venue is not surprised on the day. A clear written quote that lists hours, booth type and attendant coverage makes it easy to compare companies fairly and avoid hidden fees."],
            ['wedding-photo-booth-ideas-for-charlotte-venues', '12 Wedding Photo Booth Ideas for Charlotte Venues', 'ideas', 'From barn receptions to uptown ballrooms, here are ideas to make your wedding photo booth a guest favorite.',
                "A photo booth is one of the easiest ways to give guests something to do between the toasts and the dance floor.\n\n## Match the booth to the venue\n\nIndustrial lofts suit bold neon backdrops, while garden venues look lovely with soft florals and warm lighting.\n\n## Make the template yours\n\nA custom photo strip with your names and date becomes a keepsake guests take home.\n\n## Add a guest book\n\nStick one strip in a guest book and let guests sign next to it.\n\n## Think about the flow of the evening\n\nPlace the booth where guests naturally gather, such as near the bar or just outside the dance floor, so it never feels hidden. Open it during cocktail hour so early arrivals have something to do, and keep it available through the last dance. Props are fun, but a few well chosen ones beat a table of clutter. Ask your attendant to keep the line moving so everyone gets a turn before the night ends."],
            ['how-to-plan-a-corporate-photo-booth-activation', 'How to Plan a Corporate Photo Booth Activation', 'planning', 'A step-by-step plan for branded booths at launches, trade shows and company parties.',
                "Branded booths turn guests into promoters. Here is how to plan one.\n\n## Set a goal\n\nDecide whether you want social shares, lead capture or simply a fun staff event.\n\n## Design the overlay\n\nKeep your logo clear, use high-contrast colors and add one short hashtag.\n\n## Plan the flow\n\nPlace the booth near the entrance, with power and room for a line of six people.\n\n## Measure what matters\n\nAgree up front how you will judge success: photos shared, hashtag mentions, leads captured or simply attendance at the booth. Ask your provider for a post-event gallery and share counts so you can report results to your team. Finally, plan the follow-up. A thank-you email with each guest's photo and a clear next step turns a fun moment into a lasting impression of your brand.\n\n## Keep the experience simple\n\nGuests should understand the booth in seconds. Use clear signage, a short on-screen instruction and an attendant who greets people warmly. Offer a few tasteful props that fit your brand rather than a large pile, and make sharing effortless by text, email or QR code so photos reach guests before they leave the venue."],
        ];

        foreach ($posts as $i => [$slug, $title, $intent, $excerpt, $body]) {
            if (WebsiteArticle::query()->where('website_id', $website->id)->where('slug', $slug)->exists()) {
                continue;
            }

            $article = $this->articles->create($context->user->id, $context->business, [
                'title' => $title, 'slug' => $slug, 'excerpt' => $excerpt, 'body' => $body,
                'seo_title' => Str::limit($title, 70, ''), 'meta_description' => Str::limit($excerpt, 160, ''),
                'author_name' => 'Alex Rivera', 'search_intent' => $intent,
            ]);
            try {
                $article = $this->articles->publish($context->user->id, $context->business, $article, true);
            } catch (\App\Exceptions\Seo\ArticleException $e) {
                throw new \RuntimeException("Demo article [{$slug}] not publishable: " . implode(' | ', $e->problems), 0, $e);
            }
            $when = now()->subDays(12 + $i * 9);
            $article->forceFill(['published_at' => $when, 'content_updated_at' => $when])->save();
        }

        $context->note('blog_articles', WebsiteArticle::query()->where('website_id', $website->id)->count());
    }
}
