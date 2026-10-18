<?php

namespace Tests\Support\WebsiteAcceptance;

/**
 * Website V1 full-site acceptance — the machine-readable result: a flat list
 * of {surface, template, page, check, status, evidence}. No score. Statuses:
 *
 *   PASS  the check ran and held
 *   FAIL  the check ran and found a defect (the run fails)
 *   GAP   Website V1 does not implement this (reported, never faked, never a pass)
 *   INFO  a recorded observation or measurement (not a judgement)
 */
final class AcceptanceReport
{
    public const PASS = 'PASS';
    public const FAIL = 'FAIL';
    public const GAP = 'GAP';
    public const INFO = 'INFO';

    /** @var array<int, array{surface: string, template: string, page: string, check: string, status: string, evidence: string}> */
    private array $findings = [];

    private string $surface = '-';

    private string $template = '-';

    public function context(string $surface, string $template = '-'): void
    {
        $this->surface = $surface;
        $this->template = $template;
    }

    public function add(string $page, string $check, string $status, string $evidence = ''): void
    {
        $this->findings[] = [
            'surface' => $this->surface,
            'template' => $this->template,
            'page' => $page,
            'check' => $check,
            'status' => $status,
            'evidence' => $evidence,
        ];
    }

    public function pass(string $page, string $check, string $evidence = ''): void
    {
        $this->add($page, $check, self::PASS, $evidence);
    }

    public function fail(string $page, string $check, string $evidence): void
    {
        $this->add($page, $check, self::FAIL, $evidence);
    }

    public function gap(string $page, string $check, string $evidence): void
    {
        $this->add($page, $check, self::GAP, $evidence);
    }

    public function info(string $page, string $check, string $evidence): void
    {
        $this->add($page, $check, self::INFO, $evidence);
    }

    /** Record a boolean outcome as PASS or FAIL. */
    public function expect(bool $ok, string $page, string $check, string $evidence): void
    {
        $this->add($page, $check, $ok ? self::PASS : self::FAIL, $evidence);
    }

    /** @return array<int, array<string, string>> */
    public function findings(): array
    {
        return $this->findings;
    }

    /** @return array<int, array<string, string>> */
    public function failures(): array
    {
        return array_values(array_filter($this->findings, fn ($f) => $f['status'] === self::FAIL));
    }

    /** @return array<int, array<string, string>> */
    public function gaps(): array
    {
        return array_values(array_filter($this->findings, fn ($f) => $f['status'] === self::GAP));
    }

    /**
     * Roll-up per (surface, template, check): "PASS — 14/14" or "FAIL — 3 of 14".
     *
     * @return array<string, array{status: string, passed: int, total: int}>
     */
    public function rollup(string $surface, ?string $template = null): array
    {
        $groups = [];

        foreach ($this->findings as $f) {
            if ($f['surface'] !== $surface || ($template !== null && $f['template'] !== $template) || $f['status'] === self::INFO) {
                continue;
            }

            $groups[$f['check']] ??= ['status' => self::PASS, 'passed' => 0, 'total' => 0, 'gap' => false];
            $groups[$f['check']]['total']++;

            if ($f['status'] === self::PASS) {
                $groups[$f['check']]['passed']++;
            } elseif ($f['status'] === self::FAIL) {
                $groups[$f['check']]['status'] = self::FAIL;
            } elseif ($f['status'] === self::GAP) {
                $groups[$f['check']]['gap'] = true;
                $groups[$f['check']]['status'] = $groups[$f['check']]['status'] === self::FAIL ? self::FAIL : self::GAP;
            }
        }

        ksort($groups);

        return array_map(fn ($g) => ['status' => $g['status'], 'passed' => $g['passed'], 'total' => $g['total']], $groups);
    }

    /** @return array<int, string> distinct surfaces in the order first seen */
    public function surfaces(): array
    {
        return array_values(array_unique(array_column($this->findings, 'surface')));
    }

    public function toJson(): string
    {
        return (string) json_encode(['findings' => $this->findings], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** The human-readable report: a roll-up per surface, then every FAIL and GAP with its evidence. */
    public function toText(string $title, array $headline = []): string
    {
        $lines = [$title, str_repeat('=', strlen($title)), ''];

        foreach ($headline as $label => $value) {
            $lines[] = $label . ': ' . $value;
        }

        foreach ($this->surfaces() as $surface) {
            $lines[] = '';
            $lines[] = '## ' . $surface;

            foreach ($this->rollup($surface) as $check => $r) {
                $lines[] = sprintf('%-4s  %-34s %d/%d', $r['status'], $check, $r['passed'], $r['total']);
            }
        }

        $lines[] = '';
        $lines[] = '## Defects (FAIL)';

        $failures = $this->failures();
        if ($failures === []) {
            $lines[] = 'none';
        }

        foreach ($failures as $f) {
            $lines[] = sprintf('- [%s%s] %s :: %s :: %s', $f['surface'], $f['template'] !== '-' ? ' / ' . $f['template'] : '', $f['page'], $f['check'], $f['evidence']);
        }

        $lines[] = '';
        $lines[] = '## Not implemented by Website V1 (GAP)';

        $seen = [];
        foreach ($this->gaps() as $f) {
            $key = $f['check'] . '|' . $f['evidence'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $lines[] = sprintf('- %s :: %s', $f['check'], $f['evidence']);
        }

        if ($seen === []) {
            $lines[] = 'none';
        }

        return implode("\n", $lines) . "\n";
    }

    /** Write both artifacts to a directory (created if needed). */
    public function write(string $directory, string $basename, string $title, array $headline = []): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($directory . DIRECTORY_SEPARATOR . $basename . '.json', $this->toJson());
        file_put_contents($directory . DIRECTORY_SEPARATOR . $basename . '.txt', $this->toText($title, $headline));
    }
}
