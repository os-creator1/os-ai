<?php

namespace App\Library\Merge;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\CustomFields\CustomFieldDefinitionManager;
use App\Models\Business;
use App\Models\CustomFieldDefinition;

/**
 * THE canonical merge-field vocabulary. One registry, one syntax:
 *
 *     {{group.key}}        e.g.  {{contact.first_name}}  {{contact.event_date}}
 *
 * Groups: contact, business, location, opportunity, appointment. Contact tokens
 * are the built-in identity fields PLUS the Business's own Custom Fields (a
 * custom field's key can never shadow a built-in; see reservedContactKeys()).
 * There are deliberately NO aliases (`{{lead_name}}`, `{{client_name}}`, ...).
 *
 * This class only DESCRIBES the vocabulary (for pickers and unknown-token
 * detection); MergeFieldResolver is what turns tokens into text.
 */
final class MergeFieldRegistry
{
    public const GROUP_CONTACT = 'contact';

    public const GROUP_CUSTOM = 'custom';

    public const GROUP_BUSINESS = 'business';

    public const GROUP_LOCATION = 'location';

    public const GROUP_OPPORTUNITY = 'opportunity';

    public const GROUP_APPOINTMENT = 'appointment';

    /** Agency Outreach only: the Agency's own identity/booking facts and the prospect being messaged. */
    public const GROUP_AGENCY = 'agency';

    public const GROUP_PROSPECT = 'prospect';

    /** Picker group order and titles. `custom` is the Custom fields sub-group of `contact`. */
    private const GROUP_TITLES = [
        self::GROUP_CONTACT => 'Contact',
        self::GROUP_CUSTOM => 'Custom fields',
        self::GROUP_BUSINESS => 'Business',
        self::GROUP_LOCATION => 'Location',
        self::GROUP_OPPORTUNITY => 'Opportunity',
        self::GROUP_APPOINTMENT => 'Appointment',
        self::GROUP_AGENCY => 'Agency',
        self::GROUP_PROSPECT => 'Prospect',
    ];

    /** @var array<string, array<string, string>> token group => key => label */
    private const BUILT_IN = [
        'contact' => [
            'first_name' => 'First name',
            'last_name' => 'Last name',
            'full_name' => 'Full name',
            'email' => 'Email',
            'phone' => 'Phone',
            'company' => 'Company',
        ],
        'business' => [
            'name' => 'Business name',
            'email' => 'Business email',
            'phone' => 'Business phone',
            'website' => 'Business website',
        ],
        'location' => [
            'name' => 'Location name',
            'address' => 'Location address',
        ],
        'opportunity' => [
            'name' => 'Opportunity name',
            'value' => 'Opportunity value',
            'stage' => 'Opportunity stage',
        ],
        'appointment' => [
            'start_date' => 'Appointment date',
            'start_time' => 'Appointment time',
            'timezone' => 'Appointment timezone',
        ],
        'agency' => [
            'name' => 'Agency name',
            'website' => 'Agency website',
            'calendar_link' => 'Agency calendar link',
        ],
        'prospect' => [
            'first_name' => 'Prospect first name',
            'full_name' => 'Prospect name',
            'company' => 'Prospect company',
        ],
    ];

    public function __construct(private readonly CustomFieldDefinitionManager $definitions)
    {
    }

    /** @return list<string> contact keys a custom field may never take */
    public static function reservedContactKeys(): array
    {
        return array_keys(self::BUILT_IN['contact']);
    }

    /** @return list<string> */
    public static function tokenGroups(): array
    {
        return ['contact', 'business', 'location', 'opportunity', 'appointment', 'agency', 'prospect'];
    }

    public static function isBuiltIn(string $group, string $key): bool
    {
        return isset(self::BUILT_IN[$group][$key]);
    }

    /**
     * Which picker groups an automation can actually resolve, given its trigger.
     * Opportunity facts exist only for Opportunity triggers and Appointment
     * facts only for Appointment triggers; every other trigger offers neither.
     *
     * @return list<string>
     */
    public static function groupsForTrigger(?WorkflowTriggerType $trigger): array
    {
        $groups = [self::GROUP_CONTACT, self::GROUP_CUSTOM, self::GROUP_BUSINESS, self::GROUP_LOCATION];

        if (in_array($trigger, [
            WorkflowTriggerType::OpportunityCreated,
            WorkflowTriggerType::OpportunityStageChanged,
            WorkflowTriggerType::OpportunityWon,
            WorkflowTriggerType::OpportunityLost,
        ], true)) {
            $groups[] = self::GROUP_OPPORTUNITY;
        }

        if (in_array($trigger, [
            WorkflowTriggerType::AppointmentScheduled,
            WorkflowTriggerType::AppointmentCancelled,
            WorkflowTriggerType::AppointmentRescheduled,
        ], true)) {
            $groups[] = self::GROUP_APPOINTMENT;
        }

        return $groups;
    }

    /**
     * The picker catalog: grouped tokens for one Business. Archived custom
     * fields are never offered for NEW use; `$alsoArchivedKeys` lets an editor
     * keep showing one an existing configuration already references.
     *
     * @param list<string> $groups picker groups to include (see groupsForTrigger())
     * @param list<string> $alsoArchivedKeys
     * @param list<array{key: string, label: string, archived: bool}>|null $customRows the Business's custom fields when the caller already holds them (the Automations builder reads them in its single catalog statement); null = read them here
     *
     * @return list<array{group: string, title: string, fields: list<array{token: string, label: string}>}>
     */
    public function catalog(Business $business, array $groups = [self::GROUP_CONTACT, self::GROUP_CUSTOM, self::GROUP_BUSINESS, self::GROUP_LOCATION], array $alsoArchivedKeys = [], ?array $customRows = null): array
    {
        $catalog = [];

        foreach (self::GROUP_TITLES as $group => $title) {
            if (! in_array($group, $groups, true)) {
                continue;
            }

            $fields = [];

            if ($group === self::GROUP_CUSTOM) {
                foreach ($customRows ?? $this->customRows($business) as $row) {
                    if ($row['archived'] && ! in_array($row['key'], $alsoArchivedKeys, true)) {
                        continue;
                    }

                    $fields[] = ['token' => '{{contact.' . $row['key'] . '}}', 'label' => $row['label']];
                }

                if ($fields === []) {
                    continue;
                }
            } else {
                foreach (self::BUILT_IN[$group] as $key => $label) {
                    $fields[] = ['token' => '{{' . $group . '.' . $key . '}}', 'label' => $label];
                }
            }

            $catalog[] = ['group' => $group, 'title' => $title, 'fields' => $fields];
        }

        return $catalog;
    }

    /**
     * Everything the reusable picker component needs for one editor.
     *
     * `$groups` are the picker groups the editor can resolve (see
     * groupsForTrigger()). A group named in `$deferred` is rendered hidden and
     * marked `requires` so the host editor can reveal it when its context (the
     * workflow's trigger) supplies it. `extra` lists valid tokens that are NOT
     * offered for new use (archived custom fields) so existing text is not
     * flagged as unknown.
     *
     * @param list<string> $groups
     * @param list<string> $deferred
     * @param list<array{key: string, label: string, archived: bool}>|null $customRows see catalog()
     *
     * @return array{groups: list<array<string, mixed>>, extra: list<string>}
     */
    public function picker(Business $business, array $groups, array $deferred = [], ?array $customRows = null): array
    {
        $customRows ??= $this->customRows($business);
        $catalog = $this->catalog($business, array_merge($groups, $deferred), [], $customRows);

        foreach ($catalog as &$entry) {
            if (in_array($entry['group'], $deferred, true)) {
                $entry['requires'] = $entry['group'];
            }
        }

        unset($entry);

        $extra = array_values(array_map(
            fn (array $row): string => '{{contact.' . $row['key'] . '}}',
            array_filter($customRows, fn (array $row): bool => $row['archived']),
        ));

        return ['groups' => $catalog, 'extra' => $extra];
    }

    /** @return list<array{key: string, label: string, archived: bool}> */
    private function customRows(Business $business): array
    {
        return $this->definitions->forBusiness($business, true)
            ->map(fn (CustomFieldDefinition $definition): array => [
                'key' => $definition->key,
                'label' => $definition->label,
                'archived' => $definition->isArchived(),
            ])
            ->values()
            ->all();
    }

    /**
     * The Business's custom definition for a contact-group token key, archived
     * ones included (existing references keep resolving).
     */
    public function customDefinition(Business $business, string $key): ?CustomFieldDefinition
    {
        return $this->definitions->findByKey($business, $key);
    }
}
