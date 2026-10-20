<?php

namespace App\Library\AgencySite;

use App\Models\AgencySaasPlan;
use App\Models\AgencySite;
use App\Models\AgencyStripeConnection;
use App\Models\AgencyWhiteLabelSetting;
use App\Models\Workspace;
use Illuminate\Support\Facades\Route;
use NumberFormatter;

/**
 * White Label — assembles the non-copy inputs of a rendered Agency site from authoritative sources:
 * the Agency's single white-label identity, the Agency's plan catalog and Stripe connection, and its
 * own booking calendar. Read live at render time, so a public price, a "sellable" plan or a booking
 * button can never be more optimistic than the systems that will honour it.
 */
final class SiteContextFactory
{
    public const DEFAULT_PRIMARY = '#B4514A';

    public function __construct(private readonly AgencySiteBookingService $booking)
    {
    }

    /**
     * @param  array<string, mixed>  $values  the answers to render (draft or a published revision)
     * @param  string|null  $previewWorkspaceUid  set for the authenticated draft preview
     */
    public function make(AgencySite $site, array $values, ?string $previewWorkspaceUid = null): SiteContext
    {
        $agency = $site->agencyWorkspace ?? Workspace::query()->findOrFail($site->agency_workspace_id);
        $brand = AgencyWhiteLabelSetting::query()->where('agency_workspace_id', $agency->id)->first();

        $name = trim((string) ($brand?->display_name ?: $agency->name));
        $primary = $brand?->accent_color ?: self::DEFAULT_PRIMARY;
        $values = SiteSchema::canonicalValues($values);
        $goal = (string) ($values['x.goal'] ?? 'both');
        $goal = in_array($goal, array_keys(SiteSchema::GOALS), true) ? $goal : 'both';

        $publicId = (string) $site->public_id;
        $preview = $previewWorkspaceUid !== null;

        $pageUrl = function (string $page) use ($publicId, $preview, $previewWorkspaceUid): string {
            if ($preview) {
                return route('customer.workspaces.agency.site.preview', [$previewWorkspaceUid, $page === 'home' ? 'home' : $page]);
            }

            return $page === 'home' ? route('agency.site.home', $publicId) : route('agency.site.page', [$publicId, $page]);
        };

        return new SiteContext(
            brandName: $name,
            legalName: $brand?->legal_name,
            primaryColor: $primary,
            secondaryColor: $brand?->secondary_color,
            logo: $this->asset($brand?->logo_path),
            logoDark: $this->asset($brand?->logo_dark_path),
            logoSquare: $this->asset($brand?->logo_compact_path),
            favicon: $this->asset($brand?->favicon_path),
            supportEmail: $brand?->support_email,
            supportUrl: $brand?->support_url,
            social: $this->social($brand),
            plans: $this->plans($agency, $values, $name),
            bookingUrl: $this->booking->publicUrl($agency, $values['x.booking_type_uid'] ?? null),
            goal: $goal,
            pricingMode: $goal,
            pageUrl: $pageUrl,
            signupUrl: fn (string $planUid) => route('agency.site.signup', [$publicId, $planUid]),
            loginUrl: $preview ? route('login') : route('agency.site.login', $publicId),
            cssUrl: route('agency.site.css', $publicId).'?v='.substr(md5($primary.($brand?->secondary_color ?? '')), 0, 8),
            preview: $preview,
        );
    }

    /**
     * The plans that may appear on the site, in display order. A plan appears only if the Agency published it;
     * whether it can be BOUGHT is a separate flag (published, priced, Stripe price bound, account able to charge).
     *
     * @return list<array<string, mixed>>
     */
    public function plans(Workspace $agency, array $values, string $brand): array
    {
        $connection = AgencyStripeConnection::query()->where('agency_workspace_id', $agency->id)->first();
        $canCharge = $connection !== null && $connection->canCharge();
        $presentation = collect((array) ($values['x.plans'] ?? []))->keyBy('uid');
        $position = $presentation->keys()->flip();

        $plans = AgencySaasPlan::query()
            ->where('agency_workspace_id', $agency->id)
            ->where('is_published', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (AgencySaasPlan $plan) => ($presentation[$plan->uid]['visible'] ?? true))
            ->sortBy(fn (AgencySaasPlan $plan) => $position[$plan->uid] ?? 1000 + $plan->id)
            ->values();

        return $plans->map(function (AgencySaasPlan $plan) use ($presentation, $canCharge, $brand): array {
            $p = $presentation[$plan->uid] ?? [];
            $features = ! empty($p['features'])
                ? $p['features']
                : SiteContentBuilder::defaultFeatureLabels($plan->tier->value, $brand);

            return [
                'uid' => (string) $plan->uid,
                'name' => (string) $plan->name,
                'description' => $plan->description,
                'tier' => $plan->tier->value,
                'featured' => (bool) ($p['featured'] ?? false),
                'badge' => $p['badge'] ?? (($p['featured'] ?? false) ? 'Most popular' : null),
                'tagline' => $p['tagline'] ?? $plan->description ?? '',
                'features' => $features,
                'cta' => $p['cta'] ?? 'Get started',
                'price_label' => $this->priceLabel($plan),
                'cycle_label' => $plan->billing_cycle === 'yearly' ? '/ year' : '/ month',
                'sellable' => $canCharge && $plan->isSellable(),
            ];
        })->all();
    }

    private function priceLabel(AgencySaasPlan $plan): ?string
    {
        if ($plan->price === null || blank($plan->currency_code)) {
            return null;
        }

        $formatter = new NumberFormatter('en', NumberFormatter::CURRENCY);
        $amount = (float) $plan->price;
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, fmod($amount, 1.0) === 0.0 ? 0 : 2);

        return (string) $formatter->formatCurrency($amount, strtoupper((string) $plan->currency_code));
    }

    /** Only an existing file under the Agency's own branding directory is ever linked. */
    private function asset(?string $path): ?string
    {
        if ($path === null || ! str_starts_with($path, 'images/branding/') || str_contains($path, '..') || ! is_file(public_path($path))) {
            return null;
        }

        return asset($path);
    }

    /** @return array<string, string> label => https url */
    private function social(?AgencyWhiteLabelSetting $brand): array
    {
        $labels = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'x' => 'X', 'youtube' => 'YouTube', 'tiktok' => 'TikTok'];
        $out = [];

        foreach ((array) ($brand?->social_links ?? []) as $network => $url) {
            if (isset($labels[$network]) && is_string($url) && str_starts_with($url, 'https://')) {
                $out[$labels[$network]] = $url;
            }
        }

        return $out;
    }
}
