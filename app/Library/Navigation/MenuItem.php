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
        /** A section heading (no link): the Agency shell's "Your business" / "Agency" groups. */
        public readonly bool $header = false,
        /**
         * A frame move rendered as a CSRF POST button, not a link: the frame
         * choice is a server-authorized POST (SwitchAccountAction /
         * SwitchBusinessAction), never a GET.
         *
         * @var array{url?: string, fields?: array<string, string>}
         */
        public readonly array $post = [],
    ) {
    }

    public static function header(string $key, string $label): self
    {
        return new self($key, $label, null, '', false, [], true);
    }

    public function isPost(): bool
    {
        return $this->post !== [];
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
            'header' => $this->header,
            'post' => $this->post,
            'children' => array_map(fn (MenuItem $child) => $child->toArray(), $this->children),
        ];
    }
}
