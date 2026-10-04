<?php

namespace App\Library\MetaAds;

use App\DTO\MetaAds\MetaAdsAccountCandidate;
use App\DTO\MetaAds\MetaAdsAdData;
use App\DTO\MetaAds\MetaAdsAdSetData;
use App\DTO\MetaAds\MetaAdsCampaignData;
use App\DTO\MetaAds\MetaFrequencyRow;
use App\DTO\MetaAds\MetaInsightRow;
use App\DTO\MetaAds\MetaReportPage;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Library\MetaAds\Contracts\MetaReadClient;
use InvalidArgumentException;

/**
 * Meta Ads Module V1 contract §2/§6 — the real READ client. Every method is a
 * GET built through MetaGraphTransport (Bearer + appsecret_proof, version from
 * config, our own `after` cursor, no redirects). One method call = one request,
 * except listAdAccounts() which pages internally within the configured caps.
 *
 * Graph JSON is untrusted: ids / enums / counters are validated by
 * MetaAdsJson; a malformed required field is an `unexpected_response`; text is
 * length-capped; a creative thumbnail is kept only when it is https AND its
 * host is on config('meta_ads.thumbnail_hosts'); `actions` are filtered to
 * config('meta_ads.result_types'); spend is parsed WITHOUT floats.
 */
final class HttpMetaReadClient implements MetaReadClient
{
    public const ACCOUNT_FIELDS = 'account_id,name,currency,timezone_name,account_status';

    public const CAMPAIGN_FIELDS = 'id,name,status,effective_status,objective,daily_budget,lifetime_budget,budget_remaining,start_time,stop_time';

    public const AD_SET_FIELDS = 'id,campaign_id,name,status,effective_status,daily_budget,lifetime_budget,optimization_goal,bid_strategy,targeting';

    public const AD_FIELDS = 'id,campaign_id,adset_id,name,status,effective_status,creative{title,body,thumbnail_url,object_type}';

    public const INSIGHT_FIELDS = 'spend,impressions,reach,frequency,clicks,inline_link_clicks,actions,action_values,account_currency,date_start,date_stop';

    public const FREQUENCY_FIELDS = 'adset_id,reach,frequency,date_start,date_stop';

    /** local level => [Graph `level`, Graph id field] */
    private const LEVELS = [
        'campaign' => ['campaign', 'campaign_id'],
        'ad_set' => ['adset', 'adset_id'],
        'ad' => ['ad', 'ad_id'],
    ];

    public function __construct(
        private readonly MetaGraphTransport $transport,
        private readonly MetaAdsConfig $config,
    ) {
    }

    public function listAdAccounts(string $accessToken): array
    {
        $maxPages = $this->config->maxPagesPerReport();
        $maxRows = $this->config->maxRowsPerReport();
        $accounts = [];
        $cursor = null;

        for ($page = 1; $page <= $maxPages; $page++) {
            $result = $this->transport->get('me/adaccounts', $this->listQuery(self::ACCOUNT_FIELDS, $cursor), $accessToken, 'list_ad_accounts');

            foreach ($this->dataRows($result->body) as $row) {
                // One malformed account must not hide the others from the owner.
                $candidate = $this->account($row, false);

                if ($candidate !== null) {
                    $accounts[] = $candidate;
                }
            }

            $cursor = $this->nextCursor($result->body);

            if ($cursor === null || count($accounts) >= $maxRows) {
                break;
            }
        }

        return array_slice($accounts, 0, $maxRows);
    }

    public function accountDetails(string $accessToken, string $adAccountId): MetaAdsAccountCandidate
    {
        $result = $this->transport->get(
            'act_' . $this->accountId($adAccountId),
            ['fields' => self::ACCOUNT_FIELDS],
            $accessToken,
            'account_details',
        );

        return $this->account($result->body, true) ?? throw MetaProviderException::unexpectedResponse();
    }

    public function campaigns(string $accessToken, string $adAccountId, ?string $cursor = null): MetaReportPage
    {
        $result = $this->transport->get(
            'act_' . $this->accountId($adAccountId) . '/campaigns',
            $this->listQuery(self::CAMPAIGN_FIELDS, $this->cursorArg($cursor)),
            $accessToken,
            'campaigns',
        );

        $rows = array_map(fn (array $row): MetaAdsCampaignData => $this->campaign($row), $this->dataRows($result->body));

        return new MetaReportPage($rows, $this->nextCursor($result->body), $result->usage);
    }

    public function adSets(string $accessToken, string $adAccountId, ?string $cursor = null): MetaReportPage
    {
        $result = $this->transport->get(
            'act_' . $this->accountId($adAccountId) . '/adsets',
            $this->listQuery(self::AD_SET_FIELDS, $this->cursorArg($cursor)),
            $accessToken,
            'ad_sets',
        );

        $rows = array_map(fn (array $row): MetaAdsAdSetData => $this->adSet($row), $this->dataRows($result->body));

        return new MetaReportPage($rows, $this->nextCursor($result->body), $result->usage);
    }

    public function ads(string $accessToken, string $adAccountId, ?string $cursor = null): MetaReportPage
    {
        $result = $this->transport->get(
            'act_' . $this->accountId($adAccountId) . '/ads',
            $this->listQuery(self::AD_FIELDS, $this->cursorArg($cursor)),
            $accessToken,
            'ads',
        );

        $rows = array_map(fn (array $row): MetaAdsAdData => $this->ad($row), $this->dataRows($result->body));

        return new MetaReportPage($rows, $this->nextCursor($result->body), $result->usage);
    }

    public function insights(string $accessToken, string $adAccountId, string $level, string $since, string $until, ?string $cursor = null): MetaReportPage
    {
        if (! isset(self::LEVELS[$level])) {
            throw MetaProviderException::validation();
        }

        [$graphLevel, $idField] = self::LEVELS[$level];
        $range = $this->timeRange($since, $until);

        $query = $this->listQuery(self::INSIGHT_FIELDS . ',' . $idField, $this->cursorArg($cursor)) + [
            'level' => $graphLevel,
            'time_increment' => 1,
            'time_range' => $range,
        ];

        $result = $this->transport->get('act_' . $this->accountId($adAccountId) . '/insights', $query, $accessToken, 'insights_' . $level);

        $rows = array_map(fn (array $row): MetaInsightRow => $this->insightRow($level, $idField, $row), $this->dataRows($result->body));

        return new MetaReportPage($rows, $this->nextCursor($result->body), $result->usage);
    }

    public function frequency7d(string $accessToken, string $adAccountId, string $since, string $until, ?string $cursor = null): MetaReportPage
    {
        $query = $this->listQuery(self::FREQUENCY_FIELDS, $this->cursorArg($cursor)) + [
            'level' => 'adset',
            'time_range' => $this->timeRange($since, $until),
        ];

        $result = $this->transport->get('act_' . $this->accountId($adAccountId) . '/insights', $query, $accessToken, 'frequency');

        $rows = array_map(function (array $row) use ($since, $until): MetaFrequencyRow {
            $id = MetaAdsJson::id($row['adset_id'] ?? null) ?? throw MetaProviderException::unexpectedResponse();

            return new MetaFrequencyRow(
                $id,
                MetaAdsJson::unsignedInt($row['reach'] ?? null),
                MetaAdsJson::ratio($row['frequency'] ?? null),
                MetaAdsJson::date($row['date_start'] ?? null) ?? $since,
                MetaAdsJson::date($row['date_stop'] ?? null) ?? $until,
            );
        }, $this->dataRows($result->body));

        return new MetaReportPage($rows, $this->nextCursor($result->body), $result->usage);
    }

    // ------------------------------------------------------------------
    // Request helpers
    // ------------------------------------------------------------------

    /**
     * @return array<string, scalar|null>
     */
    private function listQuery(string $fields, ?string $cursor): array
    {
        return [
            'fields' => $fields,
            'limit' => $this->config->pageSize(),
            // Our own cursor, never a followed `paging.next` URL.
            'after' => $cursor,
        ];
    }

    private function cursorArg(?string $cursor): ?string
    {
        if ($cursor === null) {
            return null;
        }

        return MetaAdsJson::cursor($cursor) ?? throw MetaProviderException::validation();
    }

    private function accountId(string $adAccountId): string
    {
        return MetaAdsJson::id($adAccountId) ?? throw MetaProviderException::validation();
    }

    private function timeRange(string $since, string $until): string
    {
        if (MetaAdsJson::date($since) === null || MetaAdsJson::date($until) === null || $since > $until) {
            throw MetaProviderException::validation();
        }

        return json_encode(['since' => $since, 'until' => $until], JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<int, array<string, mixed>>
     */
    private function dataRows(array $body): array
    {
        $data = $body['data'] ?? null;

        if (! is_array($data)) {
            throw MetaProviderException::unexpectedResponse();
        }

        return array_values(array_filter($data, 'is_array'));
    }

    /**
     * The `after` cursor of the NEXT page. Meta returns `paging.cursors.after`
     * even on the last page; only the presence of `paging.next` says there is
     * more. The next URL itself is never read further or followed.
     *
     * @param  array<string, mixed>  $body
     */
    private function nextCursor(array $body): ?string
    {
        $paging = $body['paging'] ?? null;

        if (! is_array($paging)) {
            return null;
        }

        $next = $paging['next'] ?? null;

        if (! is_string($next) || $next === '') {
            return null;
        }

        return MetaAdsJson::cursor(MetaAdsJson::dig($paging, ['cursors', 'after'])) ?? throw MetaProviderException::unexpectedResponse();
    }

    // ------------------------------------------------------------------
    // Row mappers
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $row
     */
    private function account(array $row, bool $strict): ?MetaAdsAccountCandidate
    {
        $id = MetaAdsJson::id($row['account_id'] ?? null) ?? MetaAdsJson::id($row['id'] ?? null);
        $currency = is_string($row['currency'] ?? null) ? strtoupper($row['currency']) : null;
        $timeZone = MetaAdsJson::string($row['timezone_name'] ?? null, 64);
        $status = MetaAdsJson::unsignedInt($row['account_status'] ?? null);

        try {
            if ($id === null || $currency === null || $timeZone === null || $status === null) {
                throw new InvalidArgumentException('incomplete account');
            }

            return new MetaAdsAccountCandidate($id, MetaAdsJson::string($row['name'] ?? null, 255), $currency, $timeZone, $status);
        } catch (InvalidArgumentException) {
            if ($strict) {
                throw MetaProviderException::unexpectedResponse();
            }

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function campaign(array $row): MetaAdsCampaignData
    {
        return new MetaAdsCampaignData(
            MetaAdsJson::id($row['id'] ?? null) ?? throw MetaProviderException::unexpectedResponse(),
            MetaAdsJson::string($row['name'] ?? null, 255) ?? '(unnamed)',
            MetaAdsJson::enum($row['status'] ?? null) ?? throw MetaProviderException::unexpectedResponse(),
            MetaAdsJson::enum($row['effective_status'] ?? null),
            MetaAdsJson::enum($row['objective'] ?? null),
            $this->budget($row, 'daily_budget'),
            $this->budget($row, 'lifetime_budget'),
            $this->budget($row, 'budget_remaining'),
            MetaAdsJson::dateTime($row['start_time'] ?? null),
            MetaAdsJson::dateTime($row['stop_time'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function adSet(array $row): MetaAdsAdSetData
    {
        $targeting = is_array($row['targeting'] ?? null) ? $row['targeting'] : [];

        return new MetaAdsAdSetData(
            MetaAdsJson::id($row['id'] ?? null) ?? throw MetaProviderException::unexpectedResponse(),
            MetaAdsJson::id($row['campaign_id'] ?? null) ?? throw MetaProviderException::unexpectedResponse(),
            MetaAdsJson::string($row['name'] ?? null, 255) ?? '(unnamed)',
            MetaAdsJson::enum($row['status'] ?? null) ?? throw MetaProviderException::unexpectedResponse(),
            MetaAdsJson::enum($row['effective_status'] ?? null),
            $this->budget($row, 'daily_budget'),
            $this->budget($row, 'lifetime_budget'),
            MetaAdsJson::enum($row['optimization_goal'] ?? null),
            MetaAdsJson::enum($row['bid_strategy'] ?? null),
            MetaAdsTargetingSummary::build($targeting),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function ad(array $row): MetaAdsAdData
    {
        $creative = is_array($row['creative'] ?? null) ? $row['creative'] : [];

        return new MetaAdsAdData(
            MetaAdsJson::id($row['id'] ?? null) ?? throw MetaProviderException::unexpectedResponse(),
            MetaAdsJson::id($row['campaign_id'] ?? null) ?? throw MetaProviderException::unexpectedResponse(),
            MetaAdsJson::id($row['adset_id'] ?? null) ?? throw MetaProviderException::unexpectedResponse(),
            MetaAdsJson::string($row['name'] ?? null, 255) ?? '(unnamed)',
            MetaAdsJson::enum($row['status'] ?? null) ?? throw MetaProviderException::unexpectedResponse(),
            MetaAdsJson::enum($row['effective_status'] ?? null),
            MetaAdsJson::string($creative['title'] ?? null, 255),
            MetaAdsJson::string($creative['body'] ?? null, 1000),
            $this->config->allowedThumbnailUrl(MetaAdsJson::string($creative['thumbnail_url'] ?? null, 2048)),
            MetaAdsJson::enum($creative['object_type'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insightRow(string $level, string $idField, array $row): MetaInsightRow
    {
        return new MetaInsightRow(
            $level,
            MetaAdsJson::id($row[$idField] ?? null) ?? throw MetaProviderException::unexpectedResponse(),
            MetaAdsJson::date($row['date_start'] ?? null) ?? throw MetaProviderException::unexpectedResponse(),
            MetaAdsMoney::parseToMicros(is_string($row['spend'] ?? null) || is_int($row['spend'] ?? null) ? $row['spend'] : null),
            MetaAdsJson::unsignedInt($row['impressions'] ?? null),
            MetaAdsJson::unsignedInt($row['clicks'] ?? null),
            MetaAdsJson::unsignedInt($row['inline_link_clicks'] ?? null),
            $this->results($row),
        );
    }

    /**
     * actions + action_values filtered to the result_types allow-list.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, array{count: int, value: ?int}>
     */
    private function results(array $row): array
    {
        $values = [];

        foreach (is_array($row['action_values'] ?? null) ? $row['action_values'] : [] as $entry) {
            $type = is_array($entry) ? ($entry['action_type'] ?? null) : null;

            if (is_string($type) && $this->config->isResultType($type) && ! array_key_exists($type, $values)) {
                $raw = $entry['value'] ?? null;
                $values[$type] = MetaAdsMoney::tryParseToMicros(is_string($raw) || is_int($raw) ? $raw : null);
            }
        }

        $results = [];

        foreach (is_array($row['actions'] ?? null) ? $row['actions'] : [] as $entry) {
            $type = is_array($entry) ? ($entry['action_type'] ?? null) : null;

            if (! is_string($type) || ! $this->config->isResultType($type) || array_key_exists($type, $results)) {
                continue;
            }

            $count = MetaAdsJson::unsignedInt($entry['value'] ?? null);

            if ($count !== null) {
                $results[$type] = ['count' => $count, 'value' => $values[$type] ?? null];
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function budget(array $row, string $key): ?int
    {
        return MetaAdsMoney::parseMinor(is_string($row[$key] ?? null) || is_int($row[$key] ?? null) ? $row[$key] : null);
    }
}
