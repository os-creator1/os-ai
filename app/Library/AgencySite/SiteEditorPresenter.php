<?php

namespace App\Library\AgencySite;

use App\Models\AgencySite;
use App\Models\AgencyStripeConnection;
use App\Models\AgencyWhiteLabelSetting;
use App\Models\Workspace;
use Illuminate\Support\Arr;

/**
 * White Label — everything the Landing Page editor shows, assembled server-side from the draft, the live
 * defaults and the Agency's real plan / Stripe / calendar state. Presentation only: nothing here grants or
 * changes anything.
 */
final class SiteEditorPresenter
{
    public function __construct(
        private readonly SiteContentBuilder $builder,
        private readonly SiteContextFactory $contexts,
        private readonly AgencySiteManager $manager,
        private readonly AgencySiteBookingService $booking,
    ) {
    }

    /** @return array<string, mixed> */
    public function present(Workspace $agency, AgencySite $site): array
    {
        $values = AgencySiteManager::values($site);
        $context = $this->contexts->make($site, $values);
        [$defaultTree] = $this->builder->build([], $context);
        [$tree, $homeOrder] = $this->builder->build($values, $context);

        $groups = [];

        foreach (SiteSchema::groups() as $key => $group) {
            $fields = [];

            foreach ($group['fields'] as $field) {
                $path = $field['path'];
                $default = $this->defaultFor($field, $defaultTree);
                $overridden = array_key_exists($path, $values);
                $fields[] = $field + [
                    'value' => $overridden ? $this->forEditor($field, $values[$path], $defaultTree) : $default,
                    'default' => $default,
                    'overridden' => $overridden,
                ];
            }

            $groups[$key] = ['key' => $key, 'fields' => $fields, 'modified' => collect($fields)->contains('overridden', true)] + $group;
        }

        $sellable = array_values(array_filter($context->plans, fn ($p) => $p['sellable']));
        $connection = AgencyStripeConnection::query()->where('agency_workspace_id', $agency->id)->first();
        $bookingUuid = $values['x.booking_type_uid'] ?? null;
        $bookingReadiness = $this->booking->readiness($agency, $bookingUuid);
        $presentation = collect((array) ($values['x.plans'] ?? []))->keyBy('uid');

        return [
            'site' => $site,
            'values' => $values,
            'status' => $site->status,
            'isLive' => $site->isLive(),
            'unpublishedChanges' => $this->manager->hasUnpublishedChanges($site),
            'hostedUrl' => route('agency.site.home', $site->public_id),
            'step' => (int) $site->current_step ?: 1,
            'steps' => SiteSchema::STEPS,
            'groups' => $groups,
            'homeSections' => $this->homeSections($values, $homeOrder),
            'productFeatures' => $this->productFeatures($values, $tree),
            'industryPageVisible' => ! in_array('solutions', (array) ($values['x.pages_hidden'] ?? []), true),
            'goal' => $context->goal,
            'pricingMode' => $context->pricingMode,
            'testimonials' => (array) ($values['x.testimonials'] ?? []),
            'plans' => collect($this->manager->planOptions($agency))->map(fn ($p) => $p + [
                'presentation' => $presentation[$p['uid']] ?? ['visible' => true, 'featured' => false, 'badge' => null, 'cta' => null, 'tagline' => null, 'features' => []],
                'default_features' => SiteContentBuilder::defaultFeatureLabels($p['tier'], $context->brandName),
            ])->all(),
            'stripe' => [
                'connected' => $connection !== null && $connection->disconnected_at === null,
                'canCharge' => $connection !== null && $connection->canCharge(),
                'sellable' => count($sellable),
            ],
            'booking' => [
                'options' => $this->booking->options($agency),
                'selected' => $bookingUuid,
                'readiness' => $bookingReadiness,
                'entitled' => $this->booking->calendarEntitled($agency),
                'hasBusiness' => $this->booking->business($agency) !== null,
                'editUrl' => ($t = $this->booking->find($agency, $bookingUuid)) ? $this->booking->editUrl($agency, $t) : null,
                'url' => $this->booking->publicUrl($agency, $bookingUuid),
            ],
            'checklist' => $this->manager->checklist($agency, $site),
            'brand' => [
                'name' => $context->brandName,
                'saved' => AgencyWhiteLabelSetting::query()->where('agency_workspace_id', $agency->id)->exists(),
                'color' => $context->primaryColor,
            ],
            'pages' => array_map(fn ($page) => ['key' => $page, 'url' => route('customer.workspaces.agency.site.preview', [$agency->uid, $page])], SiteSchema::PAGES),
            'ctaTargets' => SiteSchema::CTA_TARGETS,
            'goals' => SiteSchema::GOALS,
            'signupUrls' => collect($context->plans)->mapWithKeys(fn ($p) => [$p['uid'] => route('agency.site.signup', [$site->public_id, $p['uid']])])->all(),
        ];
    }

    /** @return list<array{key: string, label: string, visible: bool, hideable: bool, modified: bool}> in display order, hidden ones included */
    private function homeSections(array $values, array $rendered): array
    {
        $labels = SiteSchema::homeSectionLabels();
        $order = array_values(array_unique(array_merge((array) ($values['x.home_order'] ?? []), SiteSchema::HOME_ORDER_DEFAULT)));
        $order = array_values(array_filter($order, fn ($k) => in_array($k, SiteSchema::HOME_ORDER_DEFAULT, true)));

        $out = [['key' => 'header', 'label' => $labels['header'], 'visible' => true, 'hideable' => false, 'movable' => false]];

        foreach ($order as $key) {
            $out[] = ['key' => $key, 'label' => $labels[$key], 'visible' => in_array($key, $rendered, true), 'hideable' => in_array($key, SiteSchema::HOME_HIDEABLE, true), 'movable' => true];
        }

        $out[] = ['key' => 'footer', 'label' => $labels['footer'], 'visible' => true, 'hideable' => false, 'movable' => false];

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function productFeatures(array $values, array $tree): array
    {
        $hidden = (array) ($values['x.product_hidden'] ?? []);
        $order = array_values(array_unique(array_merge((array) ($values['x.product_order'] ?? []), SiteSchema::productFeatureKeys())));
        $featured = $values['x.featured_feature'] ?? null;
        $out = [];

        foreach (array_filter($order, fn ($k) => in_array($k, SiteSchema::productFeatureKeys(), true)) as $key) {
            $feature = (array) ($tree['product_features'][$key] ?? []);
            $out[] = [
                'key' => $key,
                'nav' => $feature['nav'] ?? $key,
                'chip' => $feature['chip'] ?? '',
                'title' => $feature['title'] ?? '',
                'lead' => $feature['lead'] ?? '',
                'bullets' => $feature['bullets'] ?? [],
                'visible' => ! in_array($key, $hidden, true),
                'featured' => $featured === $key,
            ];
        }

        return $out;
    }

    /** The value the form shows for a field the Agency has not changed. */
    private function defaultFor(array $field, array $defaultTree): mixed
    {
        $path = $field['path'];

        if (str_starts_with($path, 'x.')) {
            return match ($path) {
                'x.goal' => 'both',
                'x.pricing_mode' => 'both',
                'x.industries' => (array) Arr::get($defaultTree, 'copy.home.trust.industries', []),
                default => in_array($field['type'], ['strings', 'rows'], true) ? [] : (str_starts_with($path, 'x.cta.') ? 'pricing' : null),
            };
        }

        return $this->forEditor($field, Arr::get($defaultTree, $path), $defaultTree);
    }

    /** Lists of positional tuples become named rows so one form control can edit any list. */
    private function forEditor(array $field, mixed $value, array $defaultTree): mixed
    {
        if (($field['type'] ?? null) !== 'rows' || ! is_array($value)) {
            return $value;
        }

        if (($field['shape'] ?? 'tuple') === 'assoc') {
            return array_values(array_map(function ($row) use ($field) {
                $row = (array) $row;

                return collect($field['columns'])->mapWithKeys(fn ($c) => [$c[0] => $row[$c[0]] ?? ''])->all();
            }, $value));
        }

        return array_values(array_map(function ($row) use ($field) {
            $row = is_array($row) ? $row : [];

            return collect($field['columns'])->mapWithKeys(fn ($c) => [$c[0] => $row[(int) $c[0]] ?? ''])->all();
        }, $value));
    }
}
