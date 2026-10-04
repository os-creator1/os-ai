<?php

namespace App\Library\Seo\Rank\Provider;

/**
 * Deterministic in-memory provider for tests and local development. Makes NO
 * network call. State is static so a container-resolved instance and the
 * test see the same world; call reset() in setUp().
 */
final class FakeSeoRankProvider implements SeoRankProvider
{
    /** @var array<string, array{request: SeoRankTaskRequest, ready: bool}> */
    public static array $tasks = [];
    /** @var list<SeoRankTaskRequest> every task the fake created */
    public static array $submitted = [];
    /** @var array<string, SeoRankTaskResult> "checkType|keyword" => result */
    public static array $results = [];
    public static int $submitCalls = 0;
    public static int $fetchCalls = 0;
    public static ?SeoRankProviderException $submitFailure = null;
    /** When true the task IS created but submit() still throws (the ambiguous case). */
    public static bool $ambiguousButCreated = false;
    public static bool $completeImmediately = true;
    public static ?int $costMicros = null;
    /** @var list<array{code: int, name: string, parent_code: int|null, country_iso: string, type: string}> */
    public static array $locations = [];
    private static int $sequence = 0;

    public static function reset(): void
    {
        self::$tasks = [];
        self::$submitted = [];
        self::$results = [];
        self::$submitCalls = 0;
        self::$fetchCalls = 0;
        self::$submitFailure = null;
        self::$ambiguousButCreated = false;
        self::$completeImmediately = true;
        self::$costMicros = null;
        self::$locations = [];
        self::$sequence = 0;
    }

    /** @param list<SeoRankResultItem> $items */
    public static function willReturn(string $checkType, string $keyword, array $items): void
    {
        self::$results[$checkType . '|' . $keyword] = new SeoRankTaskResult(SeoRankTaskResult::COMPLETED, $items, self::$costMicros);
    }

    public static function markReady(string $taskId): void
    {
        self::$tasks[$taskId]['ready'] = true;
    }

    public function key(): string
    {
        return DataForSeoRankProvider::KEY;
    }

    public function submit(SeoRankTaskRequest $request): SeoRankTaskSubmission
    {
        self::$submitCalls++;

        if (self::$submitFailure !== null && ! self::$ambiguousButCreated) {
            throw self::$submitFailure;
        }

        $id = sprintf('00000000-fake-%08d', ++self::$sequence);
        self::$tasks[$id] = ['request' => $request, 'ready' => self::$completeImmediately];
        self::$submitted[] = $request;

        if (self::$submitFailure !== null) {
            throw self::$submitFailure;
        }

        return new SeoRankTaskSubmission($id, self::$costMicros);
    }

    public function fetch(string $checkType, string $taskId): SeoRankTaskResult
    {
        self::$fetchCalls++;

        $task = self::$tasks[$taskId] ?? null;

        if ($task === null) {
            return new SeoRankTaskResult(SeoRankTaskResult::FAILED, [], null, SeoRankProviderException::CODE_TASK_FAILED);
        }

        if (! $task['ready']) {
            return SeoRankTaskResult::pending();
        }

        return self::$results[$checkType . '|' . $task['request']->keyword]
            ?? new SeoRankTaskResult(SeoRankTaskResult::COMPLETED, [], self::$costMicros);
    }

    public function readyTasksByTag(string $checkType): array
    {
        $ready = [];

        foreach (self::$tasks as $id => $task) {
            if ($task['request']->checkType === $checkType) {
                $ready[$task['request']->tag] = $id;
            }
        }

        return $ready;
    }

    public function locations(string $countryIso): array
    {
        return array_values(array_filter(self::$locations, fn (array $l) => $l['country_iso'] === strtoupper($countryIso)));
    }
}
