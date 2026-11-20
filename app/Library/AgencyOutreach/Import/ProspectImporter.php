<?php

namespace App\Library\AgencyOutreach\Import;

use App\Enums\AgencyProspecting\AgencyProspectCampaignStatus;
use App\Enums\AgencyProspecting\AgencyProspectStatus;
use App\Library\AgencyOutreach\AgencyOutreachBusinessResolver;
use App\Library\AgencyOutreach\OutreachExistingClients;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectExclusion;
use App\Models\AgencyProspectImportBatch;
use App\Models\AgencyProspectImportRow;
use App\Models\Blacklists;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Smart prospect / existing-client CSV import: analyse (writes ONLY the batch + diagnostic rows), then
 * commit (transactional, batched, idempotent). Importing NEVER dispatches a job or sends a message.
 */
final class ProspectImporter
{
    public const OUT_NEW = 'new';
    public const OUT_EXISTING = 'existing';
    public const OUT_EXCLUDED = 'excluded';
    public const OUT_STOPPED = 'stopped';
    public const OUT_DUPLICATE = 'duplicate';
    public const OUT_MISSING = 'missing_phone';
    public const OUT_INVALID = 'invalid_phone';
    public const OUT_EMPTY = 'empty';

    public const OUTCOME_LABELS = [
        self::OUT_NEW => 'New', self::OUT_EXISTING => 'Already a prospect', self::OUT_EXCLUDED => 'Existing client',
        self::OUT_STOPPED => 'Opted out (STOP)', self::OUT_DUPLICATE => 'Duplicate in file', self::OUT_MISSING => 'No phone',
        self::OUT_INVALID => 'Invalid phone', self::OUT_EMPTY => 'Empty row',
    ];

    private const CHUNK = 200;

    /** @param array{headers: list<string>, rows: list<array<string,string>>}|array $parsed */
    public function createBatch(Workspace $workspace, string $kind, ?string $filename, array $parsed, ?int $actorId): AgencyProspectImportBatch
    {
        $headers = $parsed['headers'];
        $detected = ProspectFieldMapper::detect($headers, $parsed['rows']);

        return DB::transaction(function () use ($workspace, $kind, $filename, $parsed, $actorId, $headers, $detected) {
            $batch = AgencyProspectImportBatch::create([
                'workspace_id' => $workspace->id,
                'kind' => $kind,
                'filename' => $filename !== null ? mb_substr(basename($filename), 0, 255) : null,
                'status' => 'analyzed',
                'headers' => $headers,
                'mapping' => $detected['mapping'],
                'summary' => ['detail' => $detected['detail'], 'truncated' => (bool) ($parsed['truncated'] ?? false)],
                'created_by_user_id' => $actorId,
            ]);

            foreach (array_chunk($parsed['rows'], self::CHUNK, true) as $chunk) {
                $insert = [];

                foreach ($chunk as $i => $row) {
                    $insert[] = [
                        'batch_id' => $batch->id, 'workspace_id' => $workspace->id, 'row_number' => $i + 2,
                        'original' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                        'outcome' => self::OUT_NEW, 'created_at' => now(), 'updated_at' => now(),
                    ];
                }

                AgencyProspectImportRow::query()->insert($insert);
            }

            $this->analyze($workspace, $batch, $detected['mapping'], null);

            return $batch->refresh();
        });
    }

    /**
     * (Re)classify every stored row under a mapping and region. Idempotent: only diagnostics change.
     *
     * @param  array<string,?string>  $mapping
     */
    public function analyze(Workspace $workspace, AgencyProspectImportBatch $batch, array $mapping, ?string $region): array
    {
        abort_unless((int) $batch->workspace_id === (int) $workspace->id, 404);

        $business = AgencyOutreachBusinessResolver::forWorkspace($workspace);
        $clients = app(OutreachExistingClients::class);
        $seen = [];
        $summary = ['rows' => 0, 'outcomes' => array_fill_keys(array_keys(self::OUTCOME_LABELS), 0), 'issues' => [], 'review' => 0];

        // Existing-client names for the "similar name, different number" review hint (never auto-blocks).
        $clientNames = AgencyProspectExclusion::query()
            ->where('workspace_id', $workspace->id)->whereNull('removed_at')->whereNotNull('business_name')
            ->get(['phone', 'business_name'])->all();

        AgencyProspectImportRow::query()->where('batch_id', $batch->id)->orderBy('id')->chunkById(self::CHUNK, function ($rows) use (
            $workspace, $business, $clients, $mapping, $region, &$seen, &$summary, $clientNames, $batch
        ) {
            $parsed = [];

            foreach ($rows as $row) {
                $parsed[$row->id] = ProspectRowNormalizer::isEmpty($row->original)
                    ? null : ProspectRowNormalizer::normalize($row->original, $mapping, $region);
            }

            $phones = array_values(array_filter(array_map(fn ($p) => $p['phone'] ?? null, $parsed)));
            $excluded = $clients->lookup((int) $workspace->id, $phones);
            $existing = $phones === [] ? collect() : AgencyProspect::query()->where('workspace_id', $workspace->id)->whereIn('phone', $phones)->get()->keyBy('phone');
            $blocked = $this->blacklisted($business, $phones);

            foreach ($rows as $row) {
                $summary['rows']++;
                $p = $parsed[$row->id];
                $issues = [];
                $prospectId = null;

                if ($p === null) {
                    $outcome = self::OUT_EMPTY;
                } else {
                    $issues = $p['issues'];
                    $phone = $p['phone'];

                    if (in_array(ProspectRowNormalizer::ISSUE_NO_PHONE, $issues, true)) {
                        $outcome = self::OUT_MISSING;
                    } elseif ($phone === null) {
                        $outcome = self::OUT_INVALID;
                    } elseif (isset($seen[$phone])) {
                        $outcome = self::OUT_DUPLICATE;
                    } else {
                        $seen[$phone] = $row->row_number;
                        $prospect = $existing->get($phone);

                        if (isset($excluded[(string) $phone])) {
                            $outcome = self::OUT_EXCLUDED;
                        } elseif (isset($blocked[$phone]) || ($prospect !== null && $prospect->status === AgencyProspectStatus::Stopped)) {
                            $outcome = self::OUT_STOPPED;
                        } elseif ($prospect !== null) {
                            $outcome = self::OUT_EXISTING;
                            $prospectId = $prospect->id;
                        } else {
                            $outcome = self::OUT_NEW;
                        }

                        // Same-ish name as an existing client but a DIFFERENT number: a review hint only.
                        if (! isset($excluded[(string) $phone]) && ($p['values']['business_name'] ?? null) !== null) {
                            foreach ($clientNames as $client) {
                                if ((string) $client->phone !== (string) $phone
                                    && ProspectRowNormalizer::namesSimilar($p['values']['business_name'], (string) $client->business_name)) {
                                    $issues[] = ProspectRowNormalizer::ISSUE_SIMILAR_CLIENT;
                                    break;
                                }
                            }
                        }
                    }
                }

                $summary['outcomes'][$outcome]++;

                foreach (array_unique($issues) as $issue) {
                    $summary['issues'][$issue]['count'] = ($summary['issues'][$issue]['count'] ?? 0) + 1;

                    if (count($summary['issues'][$issue]['rows'] ?? []) < 10) {
                        $summary['issues'][$issue]['rows'][] = $row->row_number;
                    }
                }

                if ($issues !== [] && $outcome === self::OUT_NEW) {
                    $summary['review']++;
                }

                $row->forceFill([
                    'normalized' => $p['values'] ?? null,
                    'phone' => $p['phone'] ?? null,
                    'outcome' => $outcome,
                    'issues' => array_values(array_unique($issues)),
                    'prospect_id' => $prospectId,
                ])->save();
            }
        });

        $summary['ready'] = $summary['outcomes'][self::OUT_NEW] + $summary['outcomes'][self::OUT_EXISTING];
        $summary['detail'] = $batch->summary['detail'] ?? [];
        $summary['truncated'] = $batch->summary['truncated'] ?? false;

        $batch->update(['mapping' => $mapping, 'region' => $region, 'summary' => $summary]);

        return $summary;
    }

    /**
     * Commit a analysed prospect batch. Idempotent (a committed batch is a no-op) and transactional per chunk.
     * Existing prospects keep their notes, conversations, status and Client-won state: only blank enrichment
     * fields are filled in.
     *
     * @return array{created: int, updated: int, enrolled: int, skipped: int, already: bool}
     */
    public function commitProspects(Workspace $workspace, AgencyProspectImportBatch $batch, ?string $campaignUid = null): array
    {
        abort_unless((int) $batch->workspace_id === (int) $workspace->id && $batch->kind === 'prospects', 404);

        $result = ['created' => 0, 'updated' => 0, 'enrolled' => 0, 'skipped' => 0, 'already' => false];

        $campaign = $campaignUid === null || $campaignUid === '' ? null : AgencyProspectCampaign::query()
            ->where('workspace_id', $workspace->id)->where('uid', $campaignUid)
            ->where('status', AgencyProspectCampaignStatus::Draft->value)->first();

        if ($campaignUid !== null && $campaignUid !== '' && $campaign === null) {
            abort(404);
        }

        DB::transaction(function () use ($workspace, $batch, $campaign, &$result) {
            $fresh = AgencyProspectImportBatch::query()->whereKey($batch->id)->lockForUpdate()->first();

            if ($fresh->status === 'imported') {
                $result['already'] = true;

                return;
            }

            $clients = app(OutreachExistingClients::class);

            AgencyProspectImportRow::query()->where('batch_id', $batch->id)
                ->whereIn('outcome', [self::OUT_NEW, self::OUT_EXISTING])->orderBy('id')
                ->chunkById(self::CHUNK, function ($rows) use ($workspace, $batch, $campaign, $clients, &$result) {
                    foreach ($rows as $row) {
                        $v = (array) $row->normalized;
                        $phone = (string) $row->phone;

                        // Re-check at commit time: the world may have changed since the analysis.
                        if ($clients->isExcluded((int) $workspace->id, $phone)) {
                            $row->update(['outcome' => self::OUT_EXCLUDED]);
                            $result['skipped']++;

                            continue;
                        }

                        $prospect = AgencyProspect::query()->where('workspace_id', $workspace->id)->where('phone', $phone)->lockForUpdate()->first();

                        if ($prospect !== null && $prospect->status === AgencyProspectStatus::Stopped) {
                            $row->update(['outcome' => self::OUT_STOPPED]);
                            $result['skipped']++;

                            continue;
                        }

                        $enrich = array_filter([
                            'business_type' => $v['business_type'] ?? null,
                            'google_rating' => $v['rating'] ?? null,
                            'review_count' => $v['review_count'] ?? null,
                            'has_website' => $v['has_website'] ?? null,
                            'website' => $v['website'] ?? null,
                            'social_url' => $v['social_url'] ?? null,
                            'address' => $v['address'] ?? null,
                            'city' => $v['city'] ?? null,
                            'state' => $v['state'] ?? null,
                            'country' => $v['country'] ?? null,
                            'maps_url' => $v['maps_url'] ?? null,
                            'latitude' => $v['latitude'] ?? null,
                            'longitude' => $v['longitude'] ?? null,
                            'opening_hours' => $v['opening_hours'] ?? null,
                            'email' => $v['email'] ?? null,
                            'contact_name' => $v['contact_name'] ?? null,
                        ], fn ($x) => $x !== null);

                        if ($prospect === null) {
                            $prospect = AgencyProspect::create($enrich + [
                                'workspace_id' => $workspace->id,
                                'company_name' => $v['business_name'] ?? $phone,
                                'phone' => $phone,
                                'status' => AgencyProspectStatus::Active->value,
                                'source' => $v['source'] ?? $batch->filename,
                                'location' => trim(implode(', ', array_filter([$v['city'] ?? null, $v['state'] ?? null]))) ?: null,
                                'import_batch_id' => $batch->id,
                                'imported_at' => now(),
                            ]);
                            $result['created']++;
                        } else {
                            // Never overwrite: only fill what is still blank. Status, notes, history stay.
                            $fill = [];

                            foreach ($enrich as $column => $value) {
                                if ($prospect->{$column} === null || $prospect->{$column} === '') {
                                    $fill[$column] = $value;
                                }
                            }

                            if ($fill !== []) {
                                $prospect->update($fill);
                                $result['updated']++;
                            }
                        }

                        $row->update(['prospect_id' => $prospect->id]);

                        if ($campaign !== null && $prospect->status === AgencyProspectStatus::Active) {
                            $member = AgencyProspectCampaignMember::firstOrCreate(
                                ['campaign_id' => $campaign->id, 'prospect_id' => $prospect->id],
                                ['workspace_id' => $workspace->id, 'enrolled_at' => now()],
                            );

                            if ($member->wasRecentlyCreated) {
                                $result['enrolled']++;
                            }
                        }
                    }
                });

            $fresh->update(['status' => 'imported', 'imported_at' => now()]);
        });

        return $result;
    }

    /**
     * Commit an existing-client batch: every valid, new number becomes an Existing Client exclusion.
     *
     * @return array{created: int, already: int, skipped: int, already_imported: bool}
     */
    public function commitClients(Workspace $workspace, AgencyProspectImportBatch $batch, ?int $actorId): array
    {
        abort_unless((int) $batch->workspace_id === (int) $workspace->id && $batch->kind === 'existing_clients', 404);

        $result = ['created' => 0, 'already' => 0, 'skipped' => 0, 'already_imported' => false];
        $clients = app(OutreachExistingClients::class);

        DB::transaction(function () use ($workspace, $batch, $actorId, $clients, &$result) {
            $fresh = AgencyProspectImportBatch::query()->whereKey($batch->id)->lockForUpdate()->first();

            if ($fresh->status === 'imported') {
                $result['already_imported'] = true;

                return;
            }

            AgencyProspectImportRow::query()->where('batch_id', $batch->id)->orderBy('id')->chunkById(self::CHUNK, function ($rows) use ($workspace, $batch, $actorId, $clients, &$result) {
                foreach ($rows as $row) {
                    if ($row->phone === null || in_array($row->outcome, [self::OUT_EMPTY, self::OUT_DUPLICATE, self::OUT_MISSING, self::OUT_INVALID], true)) {
                        $result['skipped']++;

                        continue;
                    }

                    $added = $clients->add($workspace, $row->phone, ($row->normalized['business_name'] ?? null), AgencyProspectExclusion::REASON_CSV,
                        'Imported · ' . ($batch->filename ?? 'CSV'), $actorId, null, $batch->id);

                    $added['created'] ? $result['created']++ : $result['already']++;
                }
            });

            $fresh->update(['status' => 'imported', 'imported_at' => now()]);
        });

        return $result;
    }

    /** @param list<string> $phones @return array<string,true> */
    private function blacklisted($business, array $phones): array
    {
        if ($business === null || $phones === []) {
            return [];
        }

        return Blacklists::query()->where('business_id', (int) $business->id)->whereIn('number', $phones)
            ->pluck('number')->mapWithKeys(fn ($n) => [(string) $n => true])->all();
    }
}
