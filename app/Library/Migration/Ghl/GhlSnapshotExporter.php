<?php

namespace App\Library\Migration\Ghl;

/**
 * Exports a point-in-time, read-only snapshot of one GoHighLevel location.
 *
 * Returns ['data' => [dataset => rows], 'report' => [dataset => [status, count]]].
 * A dataset the token cannot read (401/403/404) is recorded in the report as
 * UNVERIFIED rather than silently skipped, so the migration can never claim
 * coverage it does not have. The snapshot is written to disk by the caller
 * (it contains personal data and must never be committed).
 *
 * A page or conversation that fails to download is never skipped quietly: the dataset's count becomes null (UNVERIFIED),
 * its worst HTTP status is kept, and every failure is listed under `failures`. {@see self::incompleteDatasets()} is what
 * the import commands use to refuse a partial export.
 */
final class GhlSnapshotExporter
{
    /** Simple list endpoints: dataset => [path template, response key, extra query]. */
    private const LISTS = [
        'customValues' => ['/locations/{L}/customValues', 'customValues', []],
        'customFields' => ['/locations/{L}/customFields', 'customFields', []],
        'tags' => ['/locations/{L}/tags', 'tags', []],
        'pipelines' => ['/opportunities/pipelines', 'pipelines', ['locationId' => '{L}']],
        'users' => ['/users/', 'users', ['locationId' => '{L}']],
        'calendars' => ['/calendars/', 'calendars', ['locationId' => '{L}']],
        'forms' => ['/forms/', 'forms', ['locationId' => '{L}']],
        'surveys' => ['/surveys/', 'surveys', ['locationId' => '{L}']],
        'workflows' => ['/workflows/', 'workflows', ['locationId' => '{L}']],
        'templates' => ['/locations/{L}/templates', 'templates', ['originId' => '{L}', 'limit' => 100]],
        'triggerLinks' => ['/links/', 'links', ['locationId' => '{L}']],
        'funnels' => ['/funnels/funnel/list', 'funnels', ['locationId' => '{L}', 'limit' => 100]],
        'media' => ['/medias/files', 'files', ['altType' => 'location', 'altId' => '{L}', 'type' => 'file', 'limit' => 100]],
    ];

    /** Datasets an import cannot be trusted without. Others (surveys, workflows, media…) are informational and may be unreadable. */
    public const REQUIRED = ['location', 'customFields', 'tags', 'pipelines', 'contacts', 'opportunities', 'conversations', 'messages'];

    /**
     * Required datasets that are not proven complete: unreadable, partially downloaded, or (a report from before failures
     * were recorded) carrying no completeness evidence at all.
     *
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    public static function incompleteDatasets(array $report): array
    {
        $bad = [];
        foreach (self::REQUIRED as $name) {
            $entry = $report[$name] ?? null;
            if (! is_array($entry) || ($entry['count'] ?? null) === null || ! empty($entry['failures'])) {
                $bad[] = $name;
            } elseif (in_array($name, ['contacts', 'opportunities', 'conversations', 'messages'], true) && ! array_key_exists('failures', $entry)) {
                $bad[] = $name; // legacy report: no evidence that every page was fetched
            }
        }

        return $bad;
    }

    /** @return list<string> required datasets not proven complete by the `_report.json` in a snapshot directory (all of them when it is missing) */
    public static function incompleteInDirectory(string $dir): array
    {
        $file = rtrim($dir, '/').'/_report.json';
        $report = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return self::incompleteDatasets(is_array($report) ? $report : []);
    }

    public function __construct(private readonly GhlApiClient $client)
    {
    }

    /** @return array{data: array<string, mixed>, report: array<string, array{status: int, count: int|null, failures?: list<array<string, mixed>>}>} */
    public function export(string $locationId, bool $includeMessages = true): array
    {
        $data = [];
        $report = [];

        $loc = $this->client->get("/locations/{$locationId}");
        $data['location'] = $loc->body['location'] ?? [];
        $report['location'] = ['status' => $loc->status, 'count' => $loc->ok() ? 1 : null];

        foreach (self::LISTS as $name => [$path, $key, $query]) {
            $r = $this->client->get(str_replace('{L}', $locationId, $path), $this->fill($query, $locationId));
            $data[$name] = $r->ok() ? ($r->body[$key] ?? []) : [];
            $report[$name] = ['status' => $r->status, 'count' => $r->ok() ? count($data[$name]) : null];
        }

        $n = $this->client->get("/phone-system/numbers/location/{$locationId}");
        $data['phoneNumbers'] = $n->ok() ? ($n->body['numbers'] ?? []) : [];
        $report['phoneNumbers'] = ['status' => $n->status, 'count' => $n->ok() ? count($data['phoneNumbers']) : null];

        $data['contacts'] = $this->paged('/contacts/', ['locationId' => $locationId], 'contacts', $report, 'contacts');
        $data['opportunities'] = $this->paged('/opportunities/search', ['location_id' => $locationId], 'opportunities', $report, 'opportunities');

        $this->conversations($locationId, $includeMessages, $data, $report);
        $this->appointments($locationId, $data, $report);

        return ['data' => $data, 'report' => $report];
    }

    /** @param array<string, mixed> $query @return array<string, mixed> */
    private function fill(array $query, string $locationId): array
    {
        return array_map(static fn ($v) => $v === '{L}' ? $locationId : $v, $query);
    }

    /** Cursor pagination via meta.startAfter / meta.startAfterId. @return list<array<string, mixed>> */
    private function paged(string $path, array $query, string $key, array &$report, string $name): array
    {
        $rows = [];
        $cursor = [];
        $status = 200;
        $failures = [];
        $pageNo = 0;

        do {
            $pageNo++;
            $r = $this->client->get($path, $query + ['limit' => 100] + $cursor);
            if (! $r->ok()) {
                $status = $r->status;
                $failures[] = ['page' => $pageNo, 'status' => $r->status];

                break;
            }
            $page = $r->body[$key] ?? [];
            array_push($rows, ...$page);
            $meta = $r->body['meta'] ?? [];
            $cursor = isset($meta['startAfterId'], $meta['startAfter'])
                ? ['startAfter' => $meta['startAfter'], 'startAfterId' => $meta['startAfterId']]
                : [];
        } while ($page !== [] && $cursor !== []);

        $report[$name] = ['status' => $status, 'count' => $failures === [] ? count($rows) : null, 'failures' => $failures];

        return $rows;
    }

    private function conversations(string $locationId, bool $withMessages, array &$data, array &$report): void
    {
        $convs = [];
        $after = null;
        $status = 200;
        $failures = [];
        $pageNo = 0;

        do {
            $pageNo++;
            $r = $this->client->get('/conversations/search', ['locationId' => $locationId, 'limit' => 100, 'startAfterDate' => $after]);
            if (! $r->ok()) {
                $status = $r->status;
                $failures[] = ['page' => $pageNo, 'status' => $r->status];

                break;
            }
            $page = $r->body['conversations'] ?? [];
            array_push($convs, ...$page);
            $after = $page === [] ? null : end($page)['lastMessageDate'] ?? null;
        } while (count($page) === 100 && $after !== null);

        $data['conversations'] = $convs;
        $report['conversations'] = ['status' => $status, 'count' => $failures === [] ? count($convs) : null, 'failures' => $failures];

        $data['messages'] = [];
        if (! $withMessages) {
            return;
        }

        $total = 0;
        $failures = [];
        $worst = 200;
        foreach ($convs as $c) {
            $last = null;
            $rows = [];
            $pageNo = 0;
            do {
                $pageNo++;
                $r = $this->client->get("/conversations/{$c['id']}/messages", ['limit' => 100, 'lastMessageId' => $last]);
                if (! $r->ok()) {
                    // Keep what was downloaded, but never let a failed page pass as "no more messages".
                    $worst = max($worst, $r->status);
                    $failures[] = ['conversation' => (string) $c['id'], 'page' => $pageNo, 'status' => $r->status];

                    break;
                }
                $m = $r->body['messages'] ?? [];
                array_push($rows, ...($m['messages'] ?? []));
                $last = ! empty($m['nextPage']) ? ($m['lastMessageId'] ?? null) : null;
            } while ($last !== null);
            $data['messages'][$c['id']] = $rows;
            $total += count($rows);
        }
        $report['messages'] = ['status' => $worst, 'count' => $failures === [] ? $total : null, 'failures' => $failures];
    }

    private function appointments(string $locationId, array &$data, array &$report): void
    {
        $events = [];
        $worst = 200;
        foreach ($data['calendars'] as $cal) {
            $r = $this->client->get('/calendars/events', [
                'locationId' => $locationId, 'calendarId' => $cal['id'],
                'startTime' => 1577836800000, 'endTime' => 1893456000000,
            ]);
            if (! $r->ok()) {
                $worst = $r->status;
                continue;
            }
            array_push($events, ...($r->body['events'] ?? []));
        }
        $data['appointments'] = $events;
        $report['appointments'] = ['status' => $worst, 'count' => $worst < 300 ? count($events) : null];
    }
}
