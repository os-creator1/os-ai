<?php

namespace App\Library\Seo\Content;

use App\Enums\Seo\ArticleIntent;
use App\Enums\Seo\ArticleStatus;
use App\Library\NicheBlueprint\Adapters\SeoStrategyComponentAdapter;
use App\Library\NicheBlueprint\Workspace\BlueprintConfigReader;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\SeoKeyword;
use App\Models\WebsiteArticle;
use Illuminate\Support\Str;

/**
 * SEO Content Engine V1 — the deterministic list of articles worth writing. No AI, no provider call, and
 * NO invented search volume, CPC or competition: an opportunity says what a customer plausibly asks, which
 * of the Business's pages it would support, and why — nothing about traffic.
 *
 * Inputs (all read-only, all this Business's own):
 *   - the Niche Blueprint's content topics (BlueprintConfigReader::seoStrategy → content_topics): a SMALL
 *     set of patterns per niche, expanded only for the Business's top three services and its primary city
 *     — never a permutation per service × city;
 *   - when there is no blueprint, a short generic set built from the Business's own services;
 *   - the Business's active tracked SEO keywords that are informational (carry a cost / how / vs / ideas word);
 *   - the published Website pages (ArticleSiteInventory) and the real catalog;
 *   - existing articles, to say Not covered / Draft exists / Published.
 *
 * Cannibalization protection: every candidate goes through ArticleCannibalizationGuard. A topic that is the
 * same search as a Home / service / location / packages page is dropped, so the list can never tell an owner to
 * write an article that competes with their own money page.
 *
 * The list is capped (MAX). `key` is stable for a given title and intent, so an article started from an
 * opportunity stays linked to it.
 */
final class ArticleOpportunityEngine
{
    public const MAX = 24;
    private const TOP_SERVICES = 3;
    private const MAX_KEYWORD_TOPICS = 5;

    private const WHY = [
        'cost' => 'People usually ask what something costs before they enquire. A clear answer based on your real packages helps them decide, and points them to the page where they can book.',
        'how_to' => 'Customers often look for practical steps before choosing a provider. A useful how-to builds trust and leads naturally to your service page.',
        'comparison' => 'Customers weigh options before they book. A fair comparison helps them choose and links to the service that fits.',
        'ideas' => 'Customers look for ideas while they plan their event. Helpful ideas keep them on your site and lead to the service that delivers them.',
        'planning' => 'Planning questions come up long before booking. Answering them shows you know the job and supports the page where they can enquire.',
        'guide' => 'A straightforward guide answers a question customers are already asking and supports the page you want them to reach next.',
    ];

    public function __construct(
        private readonly ArticleSiteInventory $inventory,
        private readonly ArticleSupportPageResolver $resolver,
        private readonly ArticleInternalLinkSuggester $links,
        private readonly ArticleCannibalizationGuard $guard,
        private readonly BlueprintConfigReader $blueprint,
    ) {
    }

    /**
     * @return array<int, array{key: string, title: string, primary_topic: string, search_intent: string, supports_page_uid: ?string, supports_page_title: ?string, why: string, status: string, article_uid: ?string, internal_links: array<int, array{type: string, uid: string, title: string, anchor: string}>, source: string, cluster: ?string}>
     */
    public function forBusiness(Business $business): array
    {
        $pages = $this->inventory->pages($business);

        if ($pages === []) {
            return [];
        }

        $context = $this->context($business, $pages);
        $articles = WebsiteArticle::query()->where('business_id', $business->id)->get(['id', 'uid', 'title', 'primary_topic', 'status', 'opportunity_key']);
        $published = $this->links->publishedArticles($business);

        $out = [];
        $seen = [];

        foreach ($this->candidates($business, $context) as $candidate) {
            $title = $this->clean($candidate['title']);
            $key = $candidate['intent'] . ':' . Str::limit(Str::slug($title), 90, '');

            if ($title === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $topic = rtrim($title, '?');

            // Never an article that is the same search as a money page.
            if ($this->guard->check($business, [$title], null, $pages)->conflictsWithPage()) {
                continue;
            }

            $supports = $this->resolver->resolve($pages, $candidate['supports'], $topic, $candidate['service_page'], $candidate['city']);
            [$status, $articleUid] = $this->coverage($business, $title, $key, $articles, $pages);

            $out[] = [
                'key' => $key,
                'title' => $title,
                'primary_topic' => $topic,
                'search_intent' => $candidate['intent'],
                'supports_page_uid' => $supports['uid'] ?? null,
                'supports_page_title' => $supports['title'] ?? null,
                'why' => $candidate['why'] ?: self::WHY[$candidate['intent']],
                'status' => $status,
                'article_uid' => $articleUid,
                'internal_links' => $this->links->forPages($business, $pages, $published, $supports['uid'] ?? null, $topic),
                'source' => $candidate['source'],
                'cluster' => $candidate['cluster'],
            ] + array_intersect_key($candidate, ['months' => true, 'stage' => true]);

            if (count($out) >= self::MAX) {
                break;
            }
        }

        // Things still to write come first; what is drafted or published sinks, order otherwise unchanged.
        $rank = ['not_covered' => 0, 'draft' => 1, 'published' => 2];
        $indexed = [];
        foreach ($out as $i => $row) {
            $indexed[] = [$rank[$row['status']], $i, $row];
        }
        usort($indexed, fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(fn (array $row) => $row[2], $indexed);
    }

    /** @return array<string, mixed>|null */
    public function find(Business $business, string $key): ?array
    {
        foreach ($this->forBusiness($business) as $opportunity) {
            if ($opportunity['key'] === $key) {
                return $opportunity;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ candidates

    /**
     * @param  array{services: array<int, array<string, mixed>>, city: ?string, business: string}  $context
     * @return \Generator<int, array{title: string, intent: string, supports: ?string, cluster: ?string, why: ?string, source: string, service_page: ?array<string, mixed>, city: ?string}>
     */
    private function candidates(Business $business, array $context): \Generator
    {
        $strategy = $this->blueprint->seoStrategy($business);
        $topics = $strategy !== null ? SeoStrategyComponentAdapter::contentTopics($strategy) : [];

        if ($topics !== []) {
            foreach ($topics as $topic) {
                foreach ($this->expand($topic, $context) as $candidate) {
                    yield $candidate + ['source' => 'niche_blueprint'];
                }
            }
        } else {
            foreach ($this->genericTopics($context) as $candidate) {
                yield $candidate + ['source' => 'generic'];
            }
        }

        foreach ($this->keywordTopics($business) as $candidate) {
            yield $candidate + ['source' => 'keyword'];
        }
    }

    /**
     * @param  array{title: string, intent: string, per: string, supports: ?string, cluster: ?string, why: ?string}  $topic
     * @param  array{services: array<int, array<string, mixed>>, city: ?string, business: string}  $context
     * @return array<int, array{title: string, intent: string, supports: ?string, cluster: ?string, why: ?string, service_page: ?array<string, mixed>, city: ?string}>
     */
    private function expand(array $topic, array $context): array
    {
        $base = ['intent' => $topic['intent'], 'supports' => $topic['supports'], 'cluster' => $topic['cluster'], 'why' => $topic['why'], 'service_page' => null, 'city' => null]
            + array_intersect_key($topic, ['months' => true, 'stage' => true]);
        $render = fn (array $values) => strtr($topic['title'], $values);

        if ($topic['per'] === 'service') {
            return array_map(fn (array $service) => array_merge($base, [
                'title' => $render(['{service}' => $this->serviceName($service), '{business}' => $context['business'], '{city}' => (string) $context['city']]),
                'service_page' => $service,
            ]), array_slice($context['services'], 0, self::TOP_SERVICES));
        }

        if ($topic['per'] === 'city') {
            return $context['city'] === null ? [] : [array_merge($base, [
                'title' => $render(['{city}' => $context['city'], '{business}' => $context['business'], '{service}' => '']),
                'city' => $context['city'],
            ])];
        }

        return [array_merge($base, ['title' => $render(['{business}' => $context['business'], '{city}' => (string) $context['city'], '{service}' => ''])])];
    }

    /**
     * A short, honest default for a Business whose niche has no content strategy yet.
     *
     * @param  array{services: array<int, array<string, mixed>>, city: ?string, business: string}  $context
     * @return array<int, array{title: string, intent: string, supports: ?string, cluster: ?string, why: ?string, service_page: ?array<string, mixed>, city: ?string}>
     */
    private function genericTopics(array $context): array
    {
        $out = [];

        foreach (array_slice($context['services'], 0, 2) as $service) {
            $name = $this->serviceName($service);
            $out[] = ['title' => "How much does {$name} cost?", 'intent' => 'cost', 'supports' => 'packages', 'cluster' => 'Pricing', 'why' => null, 'service_page' => $service, 'city' => null];
            $out[] = ['title' => "How to choose {$name}: what to compare", 'intent' => 'how_to', 'supports' => 'service', 'cluster' => null, 'why' => null, 'service_page' => $service, 'city' => null];
            $out[] = ['title' => "{$name} planning checklist", 'intent' => 'planning', 'supports' => 'service', 'cluster' => null, 'why' => null, 'service_page' => $service, 'city' => null];
        }

        return $out;
    }

    /**
     * Active tracked keywords that are already informational. They are the owner's own words, so they are
     * offered as written; a plain "hire this service" keyword is not (it belongs to a page).
     *
     * @return array<int, array{title: string, intent: string, supports: ?string, cluster: ?string, why: ?string, service_page: ?array<string, mixed>, city: ?string}>
     */
    private function keywordTopics(Business $business): array
    {
        $out = [];

        $keywords = SeoKeyword::query()->where('business_id', $business->id)->where('lifecycle_state', 'active')
            ->orderBy('id')->limit(60)->get(['phrase']);

        foreach ($keywords as $keyword) {
            $signature = ArticleTopicSignature::of((string) $keyword->phrase);

            if ($signature['modifiers'] === [] || $signature['core'] === []) {
                continue;
            }

            $out[] = [
                'title' => Str::ucfirst(trim((string) $keyword->phrase)),
                'intent' => $this->intentFor($signature['modifiers']),
                'supports' => null,
                'cluster' => null,
                'why' => 'You track this search as a keyword, and no page or article is written to answer it yet.',
                'service_page' => null,
                'city' => null,
            ];

            if (count($out) >= self::MAX_KEYWORD_TOPICS) {
                break;
            }
        }

        return $out;
    }

    /** @param  array<int, string>  $modifiers */
    private function intentFor(array $modifiers): string
    {
        return match (true) {
            (bool) array_intersect($modifiers, ['cost', 'price', 'pricing', 'much']) => ArticleIntent::Cost->value,
            (bool) array_intersect($modifiers, ['vs', 'versus', 'difference', 'compare', 'comparison']) => ArticleIntent::Comparison->value,
            (bool) array_intersect($modifiers, ['idea', 'inspiration', 'theme', 'example']) => ArticleIntent::Ideas->value,
            (bool) array_intersect($modifiers, ['plan', 'planning', 'checklist', 'space', 'room', 'logistics']) => ArticleIntent::Planning->value,
            (bool) array_intersect($modifiers, ['how', 'step', 'work', 'prepare', 'choose']) => ArticleIntent::HowTo->value,
            default => ArticleIntent::Guide->value,
        };
    }

    // ------------------------------------------------------------------ context & coverage

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @return array{services: array<int, array<string, mixed>>, city: ?string, business: string}
     */
    private function context(Business $business, array $pages): array
    {
        $services = array_values(array_filter($pages, fn (array $p) => $p['kind'] === ArticleSiteInventory::KIND_SERVICE && $p['linkable']));

        $location = BusinessLocation::query()->where('business_id', $business->id)
            ->orderByDesc('is_primary')->orderBy('id')->get(['city', 'is_primary'])
            ->first(fn ($l) => trim((string) $l->city) !== '');

        $city = $location !== null ? trim((string) $location->city) : null;

        if ($city === null) {
            foreach ($pages as $page) {
                if ($page['kind'] === ArticleSiteInventory::KIND_LOCATION && $page['linkable']) {
                    $city = Str::title(str_replace('-', ' ', (string) preg_replace('/^serving-/', '', (string) $page['slug'])));
                    break;
                }
            }
        }

        return ['services' => $services, 'city' => $city, 'business' => (string) $business->name];
    }

    /**
     * Not covered / Draft exists / Published. An article counts when it was started FROM this opportunity,
     * or when its topic is the same subject and angle (a strong overlap): a Business that already wrote
     * "Wedding photo booth ideas" by hand has covered it.
     *
     * @param  \Illuminate\Support\Collection<int, WebsiteArticle>  $articles
     * @param  array<int, array<string, mixed>>  $pages
     * @return array{0: string, 1: ?string}
     */
    private function coverage(Business $business, string $title, string $key, $articles, array $pages): array
    {
        $match = $articles->first(fn (WebsiteArticle $a) => $a->opportunity_key === $key && $a->status !== ArticleStatus::Archived);

        if ($match === null) {
            $signature = ArticleTopicSignature::of($title);

            $match = $articles->first(function (WebsiteArticle $a) use ($signature) {
                if ($a->status === ArticleStatus::Archived) {
                    return false;
                }

                foreach (array_filter([$a->title, $a->primary_topic]) as $phrase) {
                    $existing = ArticleTopicSignature::of((string) $phrase);

                    if ($existing['core'] !== [] && $existing['core'] === $signature['core'] && $existing['modifiers'] === $signature['modifiers']) {
                        return true;
                    }
                }

                return false;
            });
        }

        if ($match === null) {
            return ['not_covered', null];
        }

        return [$match->status === ArticleStatus::Published ? 'published' : 'draft', (string) $match->uid];
    }

    /** @param  array<string, mixed>  $service */
    private function serviceName(array $service): string
    {
        return trim((string) $service['title']);
    }

    private function clean(string $title): string
    {
        return trim((string) preg_replace('/\s+/', ' ', str_replace(['  ', ' ?'], [' ', '?'], $title)));
    }
}
