<?php

namespace App\Library\AgencySite;

use App\Exceptions\AgencySite\SitePublishBlockedException;
use App\Library\Branding\AgencyWhiteLabelManager;
use App\Models\AgencySaasPlan;
use App\Models\AgencySite;
use App\Models\AgencySiteRevision;
use App\Models\AgencyWhiteLabelSetting;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * White Label — the one writer of an Agency's marketing site.
 *
 * AUTHORITY is the same as a brand write (AgencyWhiteLabelManager::assertCanManage on the LOCKED Workspace row:
 * owner, live Agency eligibility, `white_label` entitlement). The Agency is the Workspace passed in; no other
 * Agency can be named.
 *
 * DRAFT vs LIVE. Edits change `agency_sites.config` only. The public site reads an immutable revision created by
 * publish(), so a half-finished edit can never reach visitors. Prices, plan availability and the booking link are
 * read live (SiteContextFactory), so what is advertised always matches what will be charged / booked.
 */
final class AgencySiteManager
{
    public function __construct(
        private readonly AgencyWhiteLabelManager $whiteLabel,
        private readonly SiteRenderer $renderer,
        private readonly AgencySiteBookingService $booking,
    ) {
    }

    public function find(Workspace $agency): ?AgencySite
    {
        return AgencySite::query()->where('agency_workspace_id', $agency->id)->first();
    }

    /** @return array<string, mixed> */
    public static function values(AgencySite $site): array
    {
        return (array) ($site->config['values'] ?? []);
    }

    /**
     * The starter website, created on first use: a complete draft that renders immediately (the MotionGrove
     * pages with the Agency's brand, neutral wording and its published plans).
     */
    public function ensure(int $actorUserId, Workspace $agency): AgencySite
    {
        return DB::transaction(function () use ($actorUserId, $agency): AgencySite {
            $locked = Workspace::query()->whereKey($agency->id)->lockForUpdate()->firstOrFail();
            $this->whiteLabel->assertCanManage($locked, $actorUserId);

            $existing = $this->find($locked);

            if ($existing !== null) {
                return $existing;
            }

            $site = new AgencySite(['agency_workspace_id' => $locked->id, 'status' => AgencySite::DRAFT, 'config' => ['values' => ['x.goal' => 'both', 'x.pricing_mode' => 'both']], 'current_step' => 1]);
            $site->generateUid();
            $site->updated_by_user_id = $actorUserId;
            $site->save();

            return $site;
        });
    }

    /**
     * Save answers. A null / blank answer returns that field to its default. Every path and value is validated
     * against SiteSchema before anything is written.
     *
     * @param  array<string, mixed>  $answers  path => value
     * @throws \Illuminate\Validation\ValidationException
     */
    public function save(int $actorUserId, Workspace $agency, array $answers, ?int $step = null): AgencySite
    {
        $clean = [];

        foreach ($answers as $path => $value) {
            $clean[(string) $path] = SiteSchema::sanitize((string) $path, $value);
        }

        return DB::transaction(function () use ($actorUserId, $agency, $clean, $step): AgencySite {
            $locked = Workspace::query()->whereKey($agency->id)->lockForUpdate()->firstOrFail();
            $this->whiteLabel->assertCanManage($locked, $actorUserId);

            $site = $this->find($locked) ?? $this->ensureUnlocked($locked, $actorUserId);
            $values = self::values($site);

            foreach ($clean as $path => $value) {
                if ($value === null || $value === [] && $this->resetsOnEmpty($path)) {
                    unset($values[$path]);
                } else {
                    $values[$path] = $value;
                }
            }

            $site->config = ['values' => $values];
            $site->current_step = $step ?? $site->current_step;
            $site->updated_by_user_id = $actorUserId;
            $site->save();

            return $site;
        });
    }

    /** Return every field of a group (a section) to its default. */
    public function resetGroup(int $actorUserId, Workspace $agency, string $groupKey): AgencySite
    {
        $group = SiteSchema::groups()[$groupKey] ?? null;
        abort_if($group === null, 404);

        $paths = array_column($group['fields'], 'path');

        if (in_array($groupKey, SiteSchema::HOME_ORDER_DEFAULT, true) || $groupKey === 'header' || $groupKey === 'footer') {
            $paths = array_merge($paths, [], ['x.home_hidden']);
        }

        return DB::transaction(function () use ($actorUserId, $agency, $paths, $groupKey): AgencySite {
            $locked = Workspace::query()->whereKey($agency->id)->lockForUpdate()->firstOrFail();
            $this->whiteLabel->assertCanManage($locked, $actorUserId);

            $site = $this->find($locked) ?? $this->ensureUnlocked($locked, $actorUserId);
            $values = self::values($site);

            foreach ($paths as $path) {
                if ($path === 'x.home_hidden') {
                    $values[$path] = array_values(array_diff((array) ($values[$path] ?? []), [$groupKey]));
                    $values['x.home_shown'] = array_values(array_diff((array) ($values['x.home_shown'] ?? []), [$groupKey]));

                    continue;
                }

                unset($values[$path]);
            }

            $site->config = ['values' => $values];
            $site->updated_by_user_id = $actorUserId;
            $site->save();

            return $site;
        });
    }

    private function resetsOnEmpty(string $path): bool
    {
        // An empty list for a copy field means "back to the default"; for these, empty is a real answer.
        return ! in_array($path, ['x.home_hidden', 'x.home_shown', 'x.product_hidden', 'x.pages_hidden', 'x.home_order', 'x.product_order', 'x.plans', 'x.testimonials'], true);
    }

    private function ensureUnlocked(Workspace $locked, int $actorUserId): AgencySite
    {
        $site = new AgencySite(['agency_workspace_id' => $locked->id, 'status' => AgencySite::DRAFT, 'config' => ['values' => ['x.goal' => 'both', 'x.pricing_mode' => 'both']], 'current_step' => 1]);
        $site->generateUid();
        $site->updated_by_user_id = $actorUserId;
        $site->save();

        return $site;
    }

    // --------------------------------------------------------------- publish

    /**
     * Freeze the current draft as the live site. Refuses while a blocking checklist item remains — in particular a
     * paid self-service funnel is never published with an unsellable plan or an Agency Stripe account that cannot
     * charge, and a booking CTA is never published pointing at an unbookable type.
     *
     * @throws SitePublishBlockedException
     */
    public function publish(int $actorUserId, Workspace $agency): AgencySite
    {
        $site = $this->find($agency) ?? throw new SitePublishBlockedException([['key' => 'no_site', 'label' => 'No website yet', 'detail' => 'Start by answering the first screen.', 'step' => 1]]);

        $blockers = array_values(array_filter($this->checklist($agency, $site), fn ($item) => $item['status'] === 'fail'));

        if ($blockers !== []) {
            throw new SitePublishBlockedException($blockers);
        }

        return DB::transaction(function () use ($actorUserId, $agency): AgencySite {
            $locked = Workspace::query()->whereKey($agency->id)->lockForUpdate()->firstOrFail();
            $this->whiteLabel->assertCanManage($locked, $actorUserId);

            $site = AgencySite::query()->where('agency_workspace_id', $locked->id)->lockForUpdate()->firstOrFail();

            $revision = new AgencySiteRevision([
                'agency_site_id' => $site->id,
                'number' => (int) AgencySiteRevision::query()->where('agency_site_id', $site->id)->max('number') + 1,
                'config' => $site->config,
                'published_by_user_id' => $actorUserId,
            ]);
            $revision->generateUid();
            $revision->save();

            $site->forceFill(['status' => AgencySite::PUBLISHED, 'published_revision_id' => $revision->id, 'published_at' => now(), 'updated_by_user_id' => $actorUserId])->save();

            return $site;
        });
    }

    /** Take the site offline. The published revision is kept so republishing is one click; visitors get a 404. */
    public function unpublish(int $actorUserId, Workspace $agency): AgencySite
    {
        return DB::transaction(function () use ($actorUserId, $agency): AgencySite {
            $locked = Workspace::query()->whereKey($agency->id)->lockForUpdate()->firstOrFail();
            $this->whiteLabel->assertCanManage($locked, $actorUserId);

            $site = AgencySite::query()->where('agency_workspace_id', $locked->id)->lockForUpdate()->firstOrFail();
            $site->forceFill(['status' => AgencySite::UNPUBLISHED, 'updated_by_user_id' => $actorUserId])->save();

            return $site;
        });
    }

    /** Does the draft differ from what is live? */
    public function hasUnpublishedChanges(AgencySite $site): bool
    {
        if (! $site->isLive()) {
            return false;
        }

        return $site->publishedRevision?->config !== $site->config;
    }

    // ------------------------------------------------------------- checklist

    /**
     * Readiness, computed from the same live sources the renderer uses.
     *
     * @return list<array{key: string, label: string, status: string, detail: string, step: int}> status = pass | warn | fail
     */
    public function checklist(Workspace $agency, AgencySite $site): array
    {
        $values = self::values($site);
        $context = app(SiteContextFactory::class)->make($site, $values);
        $brand = AgencyWhiteLabelSetting::query()->where('agency_workspace_id', $agency->id)->first();
        $goal = $context->goal;
        $items = [];
        $add = function (string $key, string $label, string $status, string $detail, int $step) use (&$items): void {
            $items[] = compact('key', 'label', 'status', 'detail', 'step');
        };

        $add('brand', 'Brand identity', $brand !== null ? 'pass' : 'warn', $brand !== null ? 'Name, logo and colours come from the Branding tab.' : 'No brand saved yet — the site uses your workspace name and the default colour. Set it up on the Branding tab.', 1);
        $add('logo', 'Logo', $context->logo !== null || $context->logoDark !== null ? 'pass' : 'warn', $context->logo !== null || $context->logoDark !== null ? 'Logo set.' : 'No logo uploaded — the site shows your name as text.', 1);

        if (SiteRenderer::contrastWithWhite($context->primaryColor) < 3.0) {
            $add('contrast', 'Button contrast', 'warn', 'Your primary colour is quite light for white button text. Choose a darker colour on the Branding tab.', 1);
        }

        // Subscriptions.
        if (in_array($goal, ['both', 'subscriptions'], true)) {
            $published = array_values($context->plans);
            $sellable = array_values(array_filter($published, fn ($p) => $p['sellable']));

            if ($published === []) {
                $add('plans', 'Subscription plans', 'fail', 'Publish at least one plan, or change the goal to "Book a call".', 7);
            } elseif (count($sellable) !== count($published)) {
                $add('plans', 'Subscription plans', 'fail', 'Some plans shown on the site cannot be bought yet (Stripe not ready or price not verified). Fix or hide them.', 7);
            } else {
                $add('plans', 'Subscription plans', 'pass', count($sellable).' plan(s) can be bought online.', 7);
            }

            $add('stripe', 'Stripe account', $sellable !== [] ? 'pass' : 'fail', $sellable !== [] ? 'Your Stripe account can take payments.' : 'Connect Stripe and verify a plan price so customers can pay you directly.', 9);
            $privacy = $this->hasLink($values, 'links.privacy');
            $terms = $this->hasLink($values, 'links.terms');
            $add('legal', 'Privacy policy and terms', $privacy && $terms ? 'pass' : 'fail', $privacy && $terms ? 'Both links are set.' : 'Add your privacy policy and terms of service links in the Footer section — required before taking sign-ups.', 2);
        } else {
            $add('legal', 'Privacy policy and terms', $this->hasLink($values, 'links.privacy') && $this->hasLink($values, 'links.terms') ? 'pass' : 'warn', 'Add your privacy policy and terms of service links in the Footer section.', 2);
        }

        // Booking.
        if (in_array($goal, ['both', 'book_call'], true)) {
            $uuid = $values['x.booking_type_uid'] ?? null;
            $readiness = $this->booking->readiness($agency, $uuid);
            $add('booking', 'Booking type', $readiness['ready'] ? 'pass' : 'fail', $readiness['ready'] ? 'Visitors can book into your calendar.' : ($readiness['reason'] ?? 'Choose a booking type.'), 8);
        }

        // CTAs always resolve somewhere that works.
        $broken = $this->brokenCtas($site, $values);
        $add('ctas', 'Buttons lead somewhere', $broken === [] ? 'pass' : 'fail', $broken === [] ? 'Every call-to-action opens a working page.' : 'These buttons have no working destination: '.implode(', ', $broken).'.', 2);

        // Every page renders.
        $failed = [];

        foreach (SiteSchema::PAGES as $page) {
            try {
                $this->renderer->page($site, $values, $page);
            } catch (Throwable) {
                $failed[] = $page;
            }
        }

        $add('render', 'All pages render', $failed === [] ? 'pass' : 'fail', $failed === [] ? 'Home, product, solutions, how it works, customers and pricing all render.' : 'These pages could not be built: '.implode(', ', $failed).'.', 10);

        $hasStories = collect((array) ($values['x.testimonials'] ?? []))->contains(fn ($t) => ! empty($t['approved']));
        $add('customers', 'Customer stories', $hasStories ? 'pass' : 'warn', $hasStories ? 'Approved stories are shown.' : 'No approved stories — the Customers page and testimonial section stay hidden.', 6);

        $site_title = (string) ($values['pages.home.title'] ?? '');
        $add('seo', 'Search & sharing text', $site_title !== '' ? 'pass' : 'warn', $site_title !== '' ? 'Custom home page title set.' : 'Using the default page titles. Add your own in the SEO section.', 10);
        $add('domain', 'Address', 'pass', 'Your site is available at its hosted address. A custom marketing domain is not part of this release.', 10);

        return $items;
    }

    private function hasLink(array $values, string $path): bool
    {
        return ! empty($values[$path]);
    }

    /** @return list<string> */
    private function brokenCtas(AgencySite $site, array $values): array
    {
        $mg = $this->renderer->site($site, $values);
        $broken = [];

        foreach (['default', 'hero', 'final'] as $slot) {
            if (blank($mg->ctaUrl($slot))) {
                $broken[] = $slot;
            }
        }

        // A booking CTA the Agency asked for must not silently fall back to Pricing.
        foreach (['hero', 'final', 'product', 'solutions', 'how'] as $slot) {
            if (($values["x.cta.$slot"] ?? null) === 'booking' && $mg->bookDemoUrl() === null) {
                $broken[] = $slot.' (booking)';
            }

            if (($values["x.cta.$slot"] ?? null) === 'signup' && ! $mg->context()->offersSubscriptions()) {
                $broken[] = $slot.' (signup)';
            }
        }

        return $broken;
    }

    /** Plans the Agency could present, for the editor: every plan it owns with its sell-readiness. */
    public function planOptions(Workspace $agency): array
    {
        return AgencySaasPlan::query()->where('agency_workspace_id', $agency->id)->orderBy('id')->get()->map(fn (AgencySaasPlan $p) => [
            'uid' => (string) $p->uid, 'name' => (string) $p->name, 'tier' => $p->tier->value, 'price' => $p->price, 'currency' => $p->currency_code,
            'cycle' => $p->billing_cycle, 'published' => (bool) $p->is_published, 'sellable' => $p->isSellable(),
        ])->all();
    }
}
