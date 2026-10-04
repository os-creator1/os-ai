<?php

namespace App\Library\Seo\Rank;

use App\Enums\Seo\SeoRankObservationStatus;
use App\Library\Seo\Rank\Provider\SeoRankResultItem;

/**
 * Pure matching of normalized provider rows to our Business. No I/O.
 *
 * ORGANIC: the first row (lowest position) within the depth searched whose
 * host equals the Business's canonical domain (scheme and www ignored). A
 * sub-page matches; a different host — including a sibling subdomain — does
 * not. Title text is never consulted. Nothing within depth => NotFound, which
 * the caller stores as a NULL position, never 0 or depth+1.
 *
 * LOCAL: identity precedence is CID, then exact domain, then exact phone.
 * If we hold none of them the result is NotMatched ("cannot establish our
 * listing"), distinct from NotFound ("our listing is not in what came back").
 * A business name is never compared.
 */
final class SeoRankMatcher
{
    /**
     * @param list<SeoRankResultItem> $items
     * @return array{status: SeoRankObservationStatus, position: int|null, url: string|null, domain: string|null, path: string|null, basis: string|null}
     */
    public function organic(SeoRankIdentity $identity, array $items, int $depth): array
    {
        if (! $identity->canMatchOrganic()) {
            return $this->result(SeoRankObservationStatus::NotMatched);
        }

        foreach ($this->sorted($items, $depth) as $item) {
            $host = SeoRankIdentity::normalizeHost($item->domain) ?? SeoRankIdentity::normalizeHost($item->url);

            if ($host !== null && $host === $identity->domain) {
                return $this->found($item, $host, 'domain');
            }
        }

        return $this->result(SeoRankObservationStatus::NotFound);
    }

    /**
     * @param list<SeoRankResultItem> $items
     * @return array{status: SeoRankObservationStatus, position: int|null, url: string|null, domain: string|null, path: string|null, basis: string|null}
     */
    public function local(SeoRankIdentity $identity, array $items, int $depth): array
    {
        if (! $identity->canMatchLocal()) {
            return $this->result(SeoRankObservationStatus::NotMatched);
        }

        foreach ($this->sorted($items, $depth) as $item) {
            if ($identity->cid !== null && $item->cid !== null && $item->cid === $identity->cid) {
                return $this->found($item, SeoRankIdentity::normalizeHost($item->domain), 'cid');
            }
        }

        foreach ($this->sorted($items, $depth) as $item) {
            $host = SeoRankIdentity::normalizeHost($item->domain) ?? SeoRankIdentity::normalizeHost($item->url);

            if ($identity->domain !== null && $host !== null && $host === $identity->domain) {
                return $this->found($item, $host, 'domain');
            }
        }

        foreach ($this->sorted($items, $depth) as $item) {
            $phone = SeoRankIdentity::normalizePhone($item->phone);

            if ($identity->phone !== null && $phone !== null && $phone === $identity->phone) {
                return $this->found($item, SeoRankIdentity::normalizeHost($item->domain), 'phone');
            }
        }

        return $this->result(SeoRankObservationStatus::NotFound);
    }

    /**
     * @param list<SeoRankResultItem> $items
     * @return list<SeoRankResultItem>
     */
    private function sorted(array $items, int $depth): array
    {
        $within = array_values(array_filter($items, fn (SeoRankResultItem $i) => $i->position >= 1 && $i->position <= $depth));

        usort($within, fn (SeoRankResultItem $a, SeoRankResultItem $b) => $a->position <=> $b->position);

        return $within;
    }

    /** @return array{status: SeoRankObservationStatus, position: int|null, url: string|null, domain: string|null, path: string|null, basis: string|null} */
    private function found(SeoRankResultItem $item, ?string $host, string $basis): array
    {
        $path = $item->url !== null ? parse_url($item->url, PHP_URL_PATH) : null;

        return [
            'status' => SeoRankObservationStatus::Found,
            'position' => $item->position,
            'url' => $item->url,
            'domain' => $host,
            'path' => is_string($path) && $path !== '' ? mb_substr($path, 0, 512) : '/',
            'basis' => $basis,
        ];
    }

    /** @return array{status: SeoRankObservationStatus, position: int|null, url: string|null, domain: string|null, path: string|null, basis: string|null} */
    private function result(SeoRankObservationStatus $status): array
    {
        return ['status' => $status, 'position' => null, 'url' => null, 'domain' => null, 'path' => null, 'basis' => null];
    }
}
