<?php

namespace App\Library\Navigation;

/**
 * One rendered navigation entry. `label` is the human-readable label the
 * shell renders when no translation exists (contract §17.1: a missing key
 * falls back to the builder's label, never to a key path). `url` always
 * targets a registered route — the builder refuses to emit an entry whose
 * route does not exist (T-NAV-3).
 */
final class MenuItem
{
    /**
     * @param  array<int, MenuItem>  $children
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?string $url,
        public readonly string $icon,
        public readonly bool $active,
        public readonly array $children = [],
    ) {
    }

    public function isGroup(): bool
    {
        return $this->children !== [];
    }

    public function hasActiveChild(): bool
    {
        foreach ($this->children as $child) {
            if ($child->active || $child->hasActiveChild()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'url' => $this->url,
            'icon' => $this->icon,
            'active' => $this->active,
            'children' => array_map(fn (MenuItem $child) => $child->toArray(), $this->children),
        ];
    }
}
