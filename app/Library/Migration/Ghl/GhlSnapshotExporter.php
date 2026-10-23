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

    public function __construct(private readonly GhlApiClient $client)
    {
    }

    /** @return array{data: array<string, mixed>, report: array<string, array{status: int, count: int|null}>} */
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

        do {
            $r = $this->client->get($path, $query + ['limit' => 100] + $cursor);
            $status = $r->status;
            if (! $r->ok()) {
                break;
            }
            $page = $r->body[$key] ?? [];
            array_push($rows, ...$page);
            $meta = $r->body['meta'] ?? [];
            $cursor = isset($meta['startAfterId'], $meta['startAfter'])
                ? ['startAfter' => $meta['startAfter'], 'startAfterId' => $meta['startAfterId']]
                : [];
        } while ($page !== [] && $cursor !== []);

        $report[$name] = ['status' => $status, 'count' => $status < 300 ? count($rows) : null];

        return $rows;
    }

    private function conversations(string $locationId, bool $withMessages, array &$data, array &$report): void
    {
        $convs = [];
        $after = null;
        $status = 200;

        do {
            $r = $this->client->get('/conversations/search', ['locationId' => $locationId, 'limit' => 100, 'startAfterDate' => $after]);
            $status = $r->status;
            if (! $r->ok()) {
                break;
            }
            $page = $r->body['conversations'] ?? [];
            array_push($convs, ...$page);
            $after = $page === [] ? null : end($page)['lastMessageDate'] ?? null;
        } while (count($page) === 100 && $after !== null);

        $data['conversations'] = $convs;
        $report['conversations'] = ['status' => $status, 'count' => $status < 300 ? count($convs) : null];

        $data['messages'] = [];
        if (! $withMessages) {
            return;
        }

        $total = 0;
        foreach ($convs as $c) {
            $last = null;
            $rows = [];
            do {
                $r = $this->client->get("/conversations/{$c['id']}/messages", ['limit' => 100, 'lastMessageId' => $last]);
                if (! $r->ok()) {
                    break;
                }
                $m = $r->body['messages'] ?? [];
                array_push($rows, ...($m['messages'] ?? []));
                $last = ! empty($m['nextPage']) ? ($m['lastMessageId'] ?? null) : null;
            } while ($last !== null);
            $data['messages'][$c['id']] = $rows;
            $total += count($rows);
        }
        $report['messages'] = ['status' => $status, 'count' => $total];
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
        $report['appointments'] = ['status' => $worst, 'count' => count($events)];
    }
}
