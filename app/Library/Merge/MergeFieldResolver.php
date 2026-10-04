<?php

namespace App\Library\Merge;

use App\Library\CustomFields\CustomFieldValueCodec;
use App\Models\Business;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * THE merge engine. Plain `{{group.key}}` substitution — never template
 * evaluation, never an expression, never a lookup the registry does not name.
 *
 * MISSING-VALUE BEHAVIOUR (the documented contract):
 *   - a known token with no value (empty field, absent Opportunity/Appointment
 *     context, no Location, ...)            -> blank, recorded in `missing`
 *   - an unknown token (typo, another Business's field, retired vocabulary,
 *     malformed `{{...}}`)                   -> blank, recorded in `unknown`
 *   - anything throwing while reading       -> blank. Rendering never throws and
 *     the raw `{{...}}` never reaches a customer.
 *
 * ARCHIVED custom fields still resolve; only NEW picks/mappings are refused.
 *
 * SECURITY. The Contact, Location, Opportunity and Appointment in the context
 * must all belong to the context's Business; one that does not is treated as
 * ABSENT (fail closed), so a mis-built context cannot render another Business's
 * data. Custom field keys are resolved against the context Business only.
 */
class MergeFieldResolver
{
    /** A token is `{{ ... }}` with no braces inside; bounded so a stray `{{` cannot scan the whole body. */
    private const TOKEN_PATTERN = '/\{\{([^{}]{1,80})\}\}/';

    private const WELL_FORMED = '/^\s*([a-z]+)\.([a-z][a-z0-9_]{0,39})\s*$/';

    public function __construct(private readonly MergeFieldRegistry $registry)
    {
    }

    public function render(string $text, MergeContext $context): string
    {
        return $this->resolve($text, $context)->text;
    }

    public function resolve(string $text, MergeContext $context): MergeResult
    {
        if (! str_contains($text, '{{')) {
            return new MergeResult($text);
        }

        $unknown = [];
        $missing = [];
        $scope = $this->scope($context);

        $rendered = preg_replace_callback(self::TOKEN_PATTERN, function (array $match) use (&$scope, &$unknown, &$missing): string {
            $canonical = $this->canonical($match[0]);

            if (preg_match(self::WELL_FORMED, $match[1], $parts) !== 1) {
                $unknown[] = trim($match[0]);

                return '';
            }

            try {
                $value = $this->value($scope, $parts[1], $parts[2]);
            } catch (Throwable) {
                $value = null;
            }

            if ($value === false) {
                $unknown[] = $canonical;

                return '';
            }

            if ($value === null || $value === '') {
                $missing[] = $canonical;

                return '';
            }

            return $value;
        }, $text);

        return new MergeResult($rendered ?? '', array_values(array_unique($unknown)), array_values(array_unique($missing)));
    }

    /**
     * Tokens in `$text` that this Business cannot resolve — for editors and
     * previews to flag "Unknown merge field". `$groups` limits which groups the
     * editor offers; a token of any other group is reported too.
     *
     * @param list<string>|null $groups picker groups (MergeFieldRegistry::GROUP_*); null = all
     *
     * @return list<string>
     */
    public function unknownTokens(string $text, Business $business, ?array $groups = null): array
    {
        if (! str_contains($text, '{{')) {
            return [];
        }

        $unknown = [];
        preg_match_all(self::TOKEN_PATTERN, $text, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (preg_match(self::WELL_FORMED, $match[1], $parts) !== 1) {
                $unknown[] = trim($match[0]);

                continue;
            }

            [, $group, $key] = $parts;
            $known = MergeFieldRegistry::isBuiltIn($group, $key)
                || ($group === MergeFieldRegistry::GROUP_CONTACT && $this->registry->customDefinition($business, $key) !== null);

            if (! $known || ($groups !== null && ! $this->groupOffered($groups, $group, $key))) {
                $unknown[] = $this->canonical($match[0]);
            }
        }

        return array_values(array_unique($unknown));
    }

    /** @param list<string> $groups */
    private function groupOffered(array $groups, string $group, string $key): bool
    {
        if ($group !== MergeFieldRegistry::GROUP_CONTACT) {
            return in_array($group, $groups, true);
        }

        return MergeFieldRegistry::isBuiltIn($group, $key)
            ? in_array(MergeFieldRegistry::GROUP_CONTACT, $groups, true)
            : in_array(MergeFieldRegistry::GROUP_CUSTOM, $groups, true);
    }

    private function canonical(string $token): string
    {
        return '{{' . trim(substr($token, 2, -2)) . '}}';
    }

    /**
     * The context with every record proven to belong to its Business.
     *
     * @return array<string, mixed>
     */
    private function scope(MergeContext $context): array
    {
        $business = $context->business;
        $businessId = (int) $business->id;

        $contact = $context->contact !== null && (int) $context->contact->business_id === $businessId
            ? $context->contact
            : null;

        $location = $context->location !== null && (int) $context->location->business_id === $businessId
            ? $context->location
            : null;

        $opportunity = $context->opportunity !== null
            && (int) $context->opportunity->business_id === $businessId
            && ($contact === null || $context->opportunity->contact_id === null || (int) $context->opportunity->contact_id === (int) $contact->id)
            ? $context->opportunity
            : null;

        $appointment = null;

        if ($context->appointment !== null) {
            $appointmentLocation = DB::table('business_locations')
                ->where('id', (int) $context->appointment->business_location_id)
                ->where('business_id', $businessId)
                ->exists();

            if ($appointmentLocation && ($contact === null || (int) $context->appointment->contact_id === (int) $contact->id)) {
                $appointment = $context->appointment;
            }
        }

        // Agency Outreach facts. Prospects and the Outreach script are
        // Workspace-owned, so they must belong to the context Business's own
        // Workspace; one that does not is ABSENT (fail closed).
        $workspaceId = (int) $business->workspace_id;

        $prospect = $context->prospect !== null && $workspaceId > 0 && (int) $context->prospect->workspace_id === $workspaceId
            ? $context->prospect
            : null;

        $outreach = $context->outreach !== null && $workspaceId > 0 && (int) $context->outreach->workspace_id === $workspaceId
            ? $context->outreach
            : null;

        return [
            'prospect' => $prospect,
            'outreach' => $outreach,
            'business' => $business,
            'contact' => $contact,
            'location' => $location,
            'opportunity' => $opportunity,
            'appointment' => $appointment,
            'identity' => null,
            'custom' => null,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     *
     * @return string|null|false string value, null when known-but-absent, false when unknown
     */
    private function value(array &$scope, string $group, string $key): string|false|null
    {
        if (! MergeFieldRegistry::isBuiltIn($group, $key)) {
            if ($group !== MergeFieldRegistry::GROUP_CONTACT) {
                return false;
            }

            return $this->customValue($scope, $key);
        }

        return match ($group) {
            'contact' => $this->contactValue($scope, $key),
            'business' => $this->businessValue($scope['business'], $key),
            'location' => $this->locationValue($scope['location'], $key),
            'opportunity' => $this->opportunityValue($scope, $key),
            'appointment' => $this->appointmentValue($scope, $key),
            'agency' => $this->agencyValue($scope, $key),
            'prospect' => $this->prospectValue($scope['prospect'], $key),
            default => false,
        };
    }

    /** @param array<string, mixed> $scope */
    private function contactValue(array &$scope, string $key): ?string
    {
        $contact = $scope['contact'];

        if ($contact === null) {
            return null;
        }

        if ($key === 'phone') {
            $digits = preg_replace('/\D/', '', (string) $contact->phone) ?? '';

            return $digits === '' ? null : '+' . $digits;
        }

        if ($scope['identity'] === null) {
            $scope['identity'] = $this->identity((int) $contact->id);
        }

        $identity = $scope['identity'];

        return match ($key) {
            'first_name' => $identity['FIRST_NAME'] ?? null,
            'last_name' => $identity['LAST_NAME'] ?? null,
            'email' => $identity['EMAIL'] ?? null,
            'company' => $identity['COMPANY'] ?? null,
            'full_name' => trim(($identity['FIRST_NAME'] ?? '') . ' ' . ($identity['LAST_NAME'] ?? '')) ?: null,
            default => null,
        };
    }

    /**
     * The Contact's built-in identity, read from the legacy tag-keyed storage —
     * never copied into the custom-field tables.
     *
     * @return array<string, string> tag => value
     */
    private function identity(int $contactId): array
    {
        $identity = [];

        $rows = DB::table('contacts_custom_field as v')
            ->join('contact_group_fields as f', 'f.id', '=', 'v.field_id')
            ->where('v.contact_id', $contactId)
            ->whereIn('f.tag', ['FIRST_NAME', 'LAST_NAME', 'EMAIL', 'COMPANY'])
            ->orderBy('v.id')
            ->get(['f.tag', 'v.value']);

        foreach ($rows as $row) {
            $value = trim((string) $row->value);

            if ($value !== '') {
                $identity[(string) $row->tag] ??= $value;
            }
        }

        return $identity;
    }

    /** @param array<string, mixed> $scope */
    private function customValue(array &$scope, string $key): string|false|null
    {
        $business = $scope['business'];

        $definition = $this->registry->customDefinition($business, $key);

        if ($definition === null) {
            return false;
        }

        $contact = $scope['contact'];

        if ($contact === null) {
            return null;
        }

        if ($scope['custom'] === null) {
            $scope['custom'] = CustomFieldValue::query()
                ->where('contact_id', (int) $contact->id)
                ->where('business_id', (int) $business->id)
                ->get()
                ->keyBy('definition_id');
        }

        /** @var CustomFieldDefinition $definition */
        $row = $scope['custom']->get($definition->id);

        if ($row === null) {
            return null;
        }

        $canonical = CustomFieldValueCodec::fromRow($definition->fieldType(), $row);

        return $canonical === null ? null : CustomFieldValueCodec::display($definition, $canonical, $business);
    }

    /** @param array<string, mixed> $scope */
    private function agencyValue(array $scope, string $key): ?string
    {
        $outreach = $scope['outreach'];

        $value = match ($key) {
            'name' => $outreach?->agency_name ?: $scope['business']->name,
            'website' => $this->httpUrl($outreach?->website_url),
            'calendar_link' => $this->httpUrl($outreach?->booking_url),
            default => null,
        };

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** Only an http(s) URL is ever merged into a message to a stranger. */
    private function httpUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        return $url !== '' && preg_match('#^https?://[^\s]+$#i', $url) === 1 ? $url : null;
    }

    private function prospectValue(mixed $prospect, string $key): ?string
    {
        if ($prospect === null) {
            return null;
        }

        $full = trim((string) $prospect->contact_name);

        $value = match ($key) {
            'first_name' => $full === '' ? '' : (preg_split('/\s+/', $full)[0] ?? ''),
            'full_name' => $full,
            'company' => trim((string) $prospect->company_name),
            default => '',
        };

        return $value === '' ? null : $value;
    }

    private function businessValue(Business $business, string $key): ?string
    {
        $value = match ($key) {
            'name' => $business->name,
            'email' => $business->email,
            'phone' => $business->phone,
            'website' => $business->website_url,
            default => null,
        };

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function locationValue(mixed $location, string $key): ?string
    {
        if ($location === null) {
            return null;
        }

        $value = match ($key) {
            'name' => (string) $location->name,
            'address' => implode(', ', array_filter(array_map('trim', [
                (string) $location->address_line_1,
                (string) $location->address_line_2,
                (string) $location->city,
                trim($location->region . ' ' . $location->postal_code),
                (string) $location->country_code,
            ]), static fn (string $part): bool => $part !== '')),
            default => '',
        };

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $scope */
    private function opportunityValue(array $scope, string $key): ?string
    {
        $opportunity = $scope['opportunity'];

        if ($opportunity === null) {
            return null;
        }

        $value = match ($key) {
            'name' => (string) $opportunity->title,
            'value' => $opportunity->value_minor === null
                ? ''
                : CustomFieldValueCodec::money(
                    rtrim(rtrim(number_format($opportunity->value_minor / 100, 2, '.', ''), '0'), '.'),
                    $opportunity->currency_code ?: $scope['business']->currency_code,
                ),
            'stage' => (string) DB::table('crm_pipeline_stages')
                ->where('id', (int) $opportunity->stage_id)
                ->where('business_id', (int) $scope['business']->id)
                ->value('name'),
            default => '',
        };

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $scope */
    private function appointmentValue(array $scope, string $key): ?string
    {
        $appointment = $scope['appointment'];

        if ($appointment === null || $appointment->start_at === null) {
            return null;
        }

        $timezone = (string) ($scope['business']->timezone ?: config('app.timezone', 'UTC'));

        // Same conversion CalendarController uses for display, so a merged time
        // always agrees with what the Calendar shows.
        $local = Carbon::parse($appointment->start_at)->utc()->setTimezone($timezone);

        return match ($key) {
            'start_date' => $local->format('j M Y'),
            'start_time' => $local->format('g:i A'),
            'timezone' => $timezone,
            default => null,
        };
    }
}
