<?php

namespace App\Library\WebsiteAnalytics;

use App\Library\AgencySite\OfficialDomainPaths;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencySite;
use App\Models\WebsiteAnalyticsCampaign;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Cold-SMS attribution. The FINAL outbound rendering step of an Agency's prospecting campaign calls tag() on the finished
 * message text. Every URL in it that is an eligible landing page of THIS Agency (its official /photobooth-style path or its
 * hosted /w/{id} site, on a MotionGrove host) gets one opaque `mgc` value naming the campaign — never a phone number, name
 * or e-mail. Anything else is left byte-for-byte alone.
 *
 *  - Existing query parameters and #fragments are preserved; `mgc` is appended after the query and before the fragment.
 *  - Idempotent: a URL that already carries `mgc` is never touched, so rendering a rendered message changes nothing.
 *  - Trailing sentence punctuation ("…/photobooth.") is not part of the link and is kept after it.
 *  - There is no redirect and no click counter: a human visit is recorded only when the published page is actually viewed.
 *
 * (The repository has no branded short-link mechanism to reuse, so the value rides on the normal landing URL.)
 */
final class AnalyticsCampaignLinks
{
    public const PARAM = 'mgc';

    private const URL = '~https?://[^\s<>"\']+~i';

    private const TRAILING = '.,;:!?)]}';

    public function tag(string $text, Workspace $agency, ?AgencyProspectCampaign $campaign): string
    {
        if ($campaign === null || (int) $campaign->workspace_id !== (int) $agency->id || ! str_contains($text, 'http')) {
            return $text;
        }

        $token = null;

        return (string) preg_replace_callback(self::URL, function (array $m) use ($agency, $campaign, &$token): string {
            $url = $m[0];
            $tail = '';

            while ($url !== '' && str_contains(self::TRAILING, substr($url, -1))) {
                $tail = substr($url, -1) . $tail;
                $url = substr($url, 0, -1);
            }

            if (! $this->eligible($url, $agency)) {
                return $m[0];
            }

            $token ??= $this->tokenFor($campaign)->token;

            return $this->withToken($url, $token) . $tail;
        }, $text);
    }

    /** Same as tag(), for a caller that only holds the workspace id. Best effort: never throws. */
    public function tagForWorkspace(string $text, int $workspaceId, ?AgencyProspectCampaign $campaign): string
    {
        try {
            $agency = Workspace::query()->find($workspaceId);

            return $agency === null ? $text : $this->tag($text, $agency, $campaign);
        } catch (\Throwable) {
            return $text;
        }
    }

    /** A prospect's campaign when the caller has none: the Agency's most recent enrolment of that prospect. */
    public function campaignForProspect(?AgencyProspect $prospect, Workspace $agency): ?AgencyProspectCampaign
    {
        if ($prospect === null || (int) $prospect->workspace_id !== (int) $agency->id) {
            return null;
        }

        $member = AgencyProspectCampaignMember::query()
            ->where('workspace_id', $agency->id)->where('prospect_id', $prospect->id)
            ->orderByDesc('id')->first();

        return $member === null ? null : AgencyProspectCampaign::query()->where('workspace_id', $agency->id)->find($member->campaign_id);
    }

    public function tokenFor(AgencyProspectCampaign $campaign): WebsiteAnalyticsCampaign
    {
        $existing = WebsiteAnalyticsCampaign::query()->where('prospect_campaign_id', $campaign->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return WebsiteAnalyticsCampaign::query()->create([
                'token' => 'c' . strtolower(Str::random(9)),
                'workspace_id' => (int) $campaign->workspace_id,
                'prospect_campaign_id' => (int) $campaign->id,
                'label' => mb_substr((string) $campaign->name, 0, 190),
            ]);
        } catch (UniqueConstraintViolationException) {
            return WebsiteAnalyticsCampaign::query()->where('prospect_campaign_id', $campaign->id)->firstOrFail();
        }
    }

    /** Resolves a token for ONE workspace only: another Agency's token is as good as none. */
    public function resolve(?string $token, int $workspaceId): ?WebsiteAnalyticsCampaign
    {
        if (! is_string($token) || ! preg_match('/^c[a-z0-9]{9}$/', $token)) {
            return null;
        }

        return WebsiteAnalyticsCampaign::query()->where('token', $token)->where('workspace_id', $workspaceId)->first();
    }

    public function withToken(string $url, string $token): string
    {
        $fragment = '';

        if (($hash = strpos($url, '#')) !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }

        $query = (string) parse_url($url, PHP_URL_QUERY);
        parse_str($query, $params);

        if (array_key_exists(self::PARAM, $params)) {
            return $url . $fragment;
        }

        return $url . (str_contains($url, '?') ? (str_ends_with($url, '?') || str_ends_with($url, '&') ? '' : '&') : '?') . self::PARAM . '=' . $token . $fragment;
    }

    private function eligible(string $url, Workspace $agency): bool
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['host']) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || isset($parts['user'])) {
            return false;
        }

        if (! in_array(strtolower($parts['host']), $this->hosts(), true)) {
            return false;
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        $segments = $path === '' ? [] : explode('/', $path);

        if ($segments === []) {
            return false;
        }

        if (OfficialDomainPaths::permits(strtolower($segments[0]), (string) $agency->uid)) {
            return true;
        }

        return $segments[0] === 'w' && isset($segments[1])
            && AgencySite::query()->where('public_id', $segments[1])->where('agency_workspace_id', $agency->id)->exists();
    }

    /** @return list<string> MotionGrove's own hosts (the platform host and the configured marketing hosts, with/without www). */
    private function hosts(): array
    {
        $hosts = (array) config('marketing.hosts', []);
        $hosts[] = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $out = [];

        foreach ($hosts as $host) {
            $host = strtolower(trim((string) $host));

            if ($host === '') {
                continue;
            }

            $out[] = $host;
            $out[] = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.' . $host;
        }

        return array_values(array_unique($out));
    }
}
