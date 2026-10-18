<?php

namespace App\Library\NicheBlueprint\Adapters;

use App\Enums\NicheBlueprint\BlueprintUpdatePolicy;

/**
 * Niche Blueprint V2 — what the Blueprint Workspace needs to know about a
 * component type beyond installing it: which surface it belongs to, whether it
 * is copied or live, how to author it with a form, and how to summarise it.
 *
 * Optional beside BlueprintComponentAdapter (an adapter without it can still
 * install; it just cannot be authored in the Workspace).
 *
 * Form field spec entries: ['name' => string, 'label' => string,
 * 'type' => text|textarea|number|select|lines, 'options' => [value => label],
 * 'help' => ?string, 'required' => bool]. `lines` is one item per line.
 */
interface BlueprintComponentDefinition
{
    /** One of BlueprintSurfaces::keys(). */
    public function surface(): string;

    public function updatePolicy(): BlueprintUpdatePolicy;

    /** The Business-scoped PlatformFeature key a component of this type is gated by. */
    public function featureKey(): string;

    /** Short singular name, e.g. "Custom field". */
    public function typeLabel(): string;

    /** One-line human description of a payload, for lists and counts. */
    public function summary(array $payload): string;

    /** @return list<array<string, mixed>> */
    public function formFields(): array;

    /**
     * Turns submitted form input into a payload, throwing
     * InvalidArgumentException with an operator-readable message when the
     * input cannot form one.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function payloadFromInput(array $input): array;

    /** @return array<string, mixed> */
    public function inputFromPayload(array $payload): array;
}
