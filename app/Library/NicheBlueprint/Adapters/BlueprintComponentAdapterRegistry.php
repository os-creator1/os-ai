<?php

namespace App\Library\NicheBlueprint\Adapters;

use App\Exceptions\NicheBlueprint\UnknownBlueprintComponentTypeException;
use InvalidArgumentException;

/**
 * Contract 20 §10 — the Blueprint component adapters this installation knows.
 *
 * SHIPS EMPTY, DELIBERATELY. Sub-slice A builds the seam only; the first real
 * adapter (CRM pipeline) arrives in Sub-slice D, and every later
 * Calendar/Packages/Proposal/Forms adapter is one more `register()` call with
 * nothing else in this domain changing (§11 rule 3).
 *
 * Bound as a singleton in `AppServiceProvider`, beside the existing
 * `BusinessTemplateRegistry` binding and for the same reason: one registry per
 * container, so an adapter registered once is the one every publisher and
 * installer sees — and so tests can register a fake adapter without editing
 * this file.
 *
 * REFUSES CLEANLY, NEVER SILENTLY. `adapterFor()` throws
 * UnknownBlueprintComponentTypeException rather than returning null, because
 * the one caller that matters is the publisher's fail-closed gate (§6.2) and a
 * nullable return there is an invitation to skip the check. `find()` exists
 * for the genuine "is one registered?" question and is explicit about it.
 */
class BlueprintComponentAdapterRegistry
{
    /** @var array<string, BlueprintComponentAdapter> */
    private array $adapters = [];

    public function register(BlueprintComponentAdapter $adapter): void
    {
        $componentType = $adapter->componentType();

        if (trim($componentType) === '' || mb_strlen($componentType) > 40) {
            throw new InvalidArgumentException(
                'A Blueprint component adapter must declare a component type of 1-40 characters.'
            );
        }

        if (isset($this->adapters[$componentType])) {
            throw new InvalidArgumentException(
                "A Blueprint component adapter is already registered for component type [{$componentType}]."
            );
        }

        $this->adapters[$componentType] = $adapter;
    }

    public function has(string $componentType): bool
    {
        return isset($this->adapters[$componentType]);
    }

    public function find(string $componentType): ?BlueprintComponentAdapter
    {
        return $this->adapters[$componentType] ?? null;
    }

    /**
     * @throws UnknownBlueprintComponentTypeException when none is registered.
     */
    public function adapterFor(string $componentType): BlueprintComponentAdapter
    {
        return $this->adapters[$componentType]
            ?? throw new UnknownBlueprintComponentTypeException($componentType);
    }

    /** @return list<string> the registered component types, for operator surfaces. */
    public function registeredComponentTypes(): array
    {
        return array_keys($this->adapters);
    }
}
