<?php

namespace App\Library\Automation\Workflow;

/**
 * A workflow's Location scope — the ONE place that says what "Business-wide",
 * "one Location" and "selected Locations" mean.
 *
 *   business   listens to facts of every Location (and to facts with none)
 *   one        listens only to facts of exactly one Location
 *   selected   listens only to facts of one of a chosen list of Locations
 *
 * Whatever the mode, a RUN is never multi-location: enrollment pins the one
 * Location of the triggering fact (EnrollmentService), and a bound scope merely
 * decides which facts are admitted. A fact with no Location is refused by every
 * bound scope, so a source that failed to supply one cannot enrol anywhere.
 *
 * Two readers, one meaning. The DRAFT carries the choice in its trigger node's
 * config (`scope_mode`, `business_location_id`, `business_location_ids`), read
 * leniently by fromTriggerConfig(): a document written before the mode existed
 * has only `business_location_id`, which is "one" (or none = Business-wide). The
 * PUBLISHED version carries it in columns/rows, read by AutomationWorkflowVersion::scope().
 *
 * FAIL CLOSED. A scope that names a mode but no usable Location ("one" with none,
 * "selected" with an empty list) is bound and allows nothing — never Business-wide.
 */
final class WorkflowLocationScope
{
    public const BUSINESS = 'business';

    public const ONE = 'one';

    public const SELECTED = 'selected';

    /** The most Locations a `selected` scope may name. */
    public const MAX_SELECTED = 100;

    /** @var list<int> */
    private readonly array $ids;

    /** @param list<int> $ids */
    public function __construct(private readonly string $mode, array $ids = [])
    {
        $clean = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            fn (int $id): bool => $id > 0,
        )));
        sort($clean);

        $this->ids = $this->mode === self::BUSINESS ? [] : $clean;
    }

    /** @return list<string> */
    public static function modes(): array
    {
        return [self::BUSINESS, self::ONE, self::SELECTED];
    }

    public static function business(): self
    {
        return new self(self::BUSINESS);
    }

    /** @param list<int> $ids */
    public static function selected(array $ids): self
    {
        return new self(self::SELECTED, $ids);
    }

    public static function one(int $id): self
    {
        return new self(self::ONE, [$id]);
    }

    /**
     * Read the scope a trigger node's config declares.
     *
     * An absent or unknown mode falls back to the pre-mode shape: a
     * `business_location_id` means "one Location", none means Business-wide. A
     * mode that is present but not a known one is NOT read as Business-wide — it
     * is a bound scope with no Location, so nothing can enrol and the validator
     * reports it.
     *
     * @param array<string, mixed> $config
     */
    public static function fromTriggerConfig(array $config): self
    {
        $single = self::positive($config['business_location_id'] ?? null);
        $list = is_array($config['business_location_ids'] ?? null)
            ? array_values(array_filter(array_map([self::class, 'positive'], $config['business_location_ids']), fn ($id): bool => $id !== null))
            : [];

        $mode = $config['scope_mode'] ?? null;

        if ($mode === null || $mode === '') {
            return $single === null ? self::business() : self::one($single);
        }

        return match ($mode) {
            self::BUSINESS => self::business(),
            self::ONE => new self(self::ONE, $single === null ? [] : [$single]),
            self::SELECTED => new self(self::SELECTED, $list),
            // Present but unrecognised: bound, and allows nothing.
            default => new self(self::ONE, []),
        };
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /** True for "one" and "selected": the workflow is limited to Locations. */
    public function isBound(): bool
    {
        return $this->mode !== self::BUSINESS;
    }

    public function isBusinessWide(): bool
    {
        return $this->mode === self::BUSINESS;
    }

    /** @return list<int> the Locations a bound scope names; empty for Business-wide */
    public function ids(): array
    {
        return $this->ids;
    }

    /**
     * Whether a fact at this Location is one this scope listens to. A bound scope
     * admits only a Location it names — never a null one.
     */
    public function allows(?int $locationId): bool
    {
        if ($this->mode === self::BUSINESS) {
            return true;
        }

        return $locationId !== null && in_array($locationId, $this->ids, true);
    }

    /** The single Location of a "one" scope, else null. */
    public function singleId(): ?int
    {
        return $this->mode === self::ONE && count($this->ids) === 1 ? $this->ids[0] : null;
    }

    private static function positive(mixed $value): ?int
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0 ? (int) $value : null;
    }
}
