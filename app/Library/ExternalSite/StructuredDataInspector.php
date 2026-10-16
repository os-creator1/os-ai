<?php

namespace App\Library\ExternalSite;

/**
 * Reads ONE JSON-LD block into the few facts the audit can defend: did it parse,
 * which schema.org types does it declare, and — for a short list of types whose
 * required properties Google documents — which of those are absent.
 *
 * It deliberately does NOT claim rich-result eligibility. Parsing and having the
 * documented required properties is necessary, not sufficient; Google's own tools
 * decide eligibility. Only the types in REQUIRED are checked, and only for the
 * properties named there; every other type is reported as "declared" and left
 * alone. Microdata and RDFa are not read.
 *
 * Pure: a string in, a small array out. Nothing is fetched or executed.
 */
final class StructuredDataInspector
{
    private const MAX_BYTES = 200_000;

    private const MAX_NODES = 60;

    /** Business types that carry the LocalBusiness requirements (name + address). */
    private const LOCAL_BUSINESS_TYPES = [
        'LocalBusiness', 'Store', 'Restaurant', 'ProfessionalService', 'HealthAndBeautyBusiness', 'HomeAndConstructionBusiness',
        'AutomotiveBusiness', 'EntertainmentBusiness', 'FoodEstablishment', 'LodgingBusiness', 'Dentist', 'Physician', 'LegalService',
        'RealEstateAgent', 'ChildCare', 'SportsActivityLocation', 'TravelAgency', 'FinancialService', 'MedicalBusiness', 'DaySpa',
        'BeautySalon', 'HairSalon', 'Plumber', 'Electrician', 'Locksmith', 'RoofingContractor', 'GeneralContractor', 'MovingCompany',
        'AutoRepair', 'Bakery', 'CafeOrCoffeeShop', 'BarOrPub', 'Florist', 'GymOrFitnessCenter', 'EventVenue',
    ];

    /** @var array<string, list<string>> type => properties Google lists as required for its feature */
    private const REQUIRED = [
        'BreadcrumbList' => ['itemListElement'],
        'FAQPage' => ['mainEntity'],
        'Product' => ['name'],
        'Event' => ['name', 'startDate', 'location'],
    ];

    public static function isLocalBusinessType(string $type): bool
    {
        return in_array($type, self::LOCAL_BUSINESS_TYPES, true);
    }

    /**
     * @return array{types: list<string>, valid: bool, error: ?string, missing: list<string>}
     */
    public static function inspect(string $json): array
    {
        $json = trim($json);

        if ($json === '' || strlen($json) > self::MAX_BYTES) {
            return ['types' => [], 'valid' => false, 'error' => $json === '' ? 'empty' : 'too_large', 'missing' => []];
        }

        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data)) {
            return ['types' => [], 'valid' => false, 'error' => 'invalid_json', 'missing' => []];
        }

        $nodes = [];
        self::collect($data, $nodes);

        $types = [];
        $missing = [];

        foreach ($nodes as $node) {
            foreach (self::typesOf($node) as $type) {
                $types[$type] = true;

                $required = in_array($type, self::LOCAL_BUSINESS_TYPES, true) ? ['name', 'address'] : (self::REQUIRED[$type] ?? []);

                foreach ($required as $property) {
                    if (! self::present($node, $property)) {
                        $missing[$type.'.'.$property] = true;
                    }
                }
            }
        }

        return ['types' => array_slice(array_keys($types), 0, 12), 'valid' => true, 'error' => null, 'missing' => array_slice(array_keys($missing), 0, 12)];
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<array<string, mixed>>  $nodes
     */
    private static function collect(array $value, array &$nodes): void
    {
        if (count($nodes) >= self::MAX_NODES) {
            return;
        }

        if (array_is_list($value)) {
            foreach ($value as $item) {
                if (is_array($item)) {
                    self::collect($item, $nodes);
                }
            }

            return;
        }

        if (isset($value['@type'])) {
            $nodes[] = $value;
        }

        foreach (['@graph', 'mainEntity', 'itemListElement'] as $nested) {
            if (isset($value[$nested]) && is_array($value[$nested])) {
                self::collect($value[$nested], $nodes);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private static function typesOf(array $node): array
    {
        $raw = $node['@type'] ?? [];
        $raw = is_array($raw) ? $raw : [$raw];
        $types = [];

        foreach ($raw as $type) {
            if (is_string($type) && $type !== '') {
                $types[] = substr((string) preg_replace('#^.*[/:]#', '', $type), 0, 60);
            }
        }

        return $types;
    }

    /** @param array<string, mixed> $node */
    private static function present(array $node, string $property): bool
    {
        $value = $node[$property] ?? null;

        if (is_string($value)) {
            return trim($value) !== '';
        }

        return is_array($value) ? $value !== [] : $value !== null;
    }
}
