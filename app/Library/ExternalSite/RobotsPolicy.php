<?php

namespace App\Library\ExternalSite;

/**
 * A parsed robots.txt, for ONE crawler token. Honours the groups that name our
 * token, else the `*` groups; within them the longest matching Allow/Disallow
 * path wins and Allow wins a tie, with `*` and `$` pattern support, as the
 * Robots Exclusion Protocol describes. A missing, unreadable or empty file allows
 * everything. The body is capped before parsing so a hostile file cannot be a
 * resource problem.
 */
final class RobotsPolicy
{
    private const MAX_BYTES = 500_000;

    private const MAX_RULES = 1000;

    /**
     * @param  list<array{0: bool, 1: string}>  $rules  [allow?, path pattern]
     * @param  list<string>  $sitemaps
     */
    private function __construct(private readonly array $rules, private readonly array $sitemaps)
    {
    }

    public static function allowAll(): self
    {
        return new self([], []);
    }

    public static function parse(string $body, string $token): self
    {
        $token = strtolower($token);
        $blocks = [];
        $sitemaps = [];
        $previousWasAgent = false;

        foreach (preg_split('/\R/', substr($body, 0, self::MAX_BYTES)) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');

            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'sitemap') {
                if (count($sitemaps) < 10 && $value !== '') {
                    $sitemaps[] = $value;
                }

                continue;
            }

            if ($field === 'user-agent') {
                if (! $previousWasAgent || $blocks === []) {
                    $blocks[] = ['agents' => [], 'rules' => []];
                }

                $blocks[array_key_last($blocks)]['agents'][] = strtolower($value);
                $previousWasAgent = true;

                continue;
            }

            $previousWasAgent = false;

            if (in_array($field, ['allow', 'disallow'], true) && $blocks !== []) {
                $blocks[array_key_last($blocks)]['rules'][] = [$field === 'allow', $value];
            }
        }

        $named = [];
        $wildcard = [];

        foreach ($blocks as $block) {
            foreach ($block['agents'] as $agent) {
                if ($agent === '*') {
                    $wildcard = array_merge($wildcard, $block['rules']);
                } elseif ($agent !== '' && str_contains($token, $agent)) {
                    $named = array_merge($named, $block['rules']);
                }
            }
        }

        return new self(array_slice($named !== [] ? $named : $wildcard, 0, self::MAX_RULES), $sitemaps);
    }

    /** @return list<string> */
    public function sitemaps(): array
    {
        return $this->sitemaps;
    }

    public function allows(string $path): bool
    {
        $path = $path === '' ? '/' : $path;
        $bestLength = -1;
        $allowed = true;

        foreach ($this->rules as [$allow, $pattern]) {
            if ($pattern === '' || ! self::matches($pattern, $path)) {
                continue; // "Disallow:" with nothing disallows nothing
            }

            $length = strlen($pattern);

            if ($length > $bestLength || ($length === $bestLength && $allow)) {
                $bestLength = $length;
                $allowed = $allow;
            }
        }

        return $allowed;
    }

    private static function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $core = $anchored ? substr($pattern, 0, -1) : $pattern;
        $regex = '';

        foreach (str_split($core) as $char) {
            $regex .= $char === '*' ? '.*' : preg_quote($char, '#');
        }

        return preg_match('#^'.$regex.($anchored ? '$' : '').'#', $path) === 1;
    }
}
