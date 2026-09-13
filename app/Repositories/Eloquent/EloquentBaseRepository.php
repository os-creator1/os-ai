<?php

namespace App\Repositories\Eloquent;

use App\Library\Support\RequestScopedCache;
use App\Repositories\Contracts\BaseRepository;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EloquentBaseRepository implements BaseRepository
{

    protected $model;

    /**
     * BaseRepository constructor.
     *
     * @param Model $model
     */
    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    /**
     * @return Builder
     */
    public function query(): Builder
    {
        return $this->model->newQuery();
    }


    /**
     * @param      $query
     * @param null $callback
     * @return mixed
     *
     */
    public function search($query, $callback = null)
    {
        return $this->model->search($query, $callback);
    }

    /**
     * @param array $columns
     *
     * @return Builder
     */
    public function select(array $columns = ['*']): Builder
    {
        return $this->query()->select($columns);
    }

    /**
     * @param array $attributes
     *
     * @return Model
     */
    public function make(array $attributes = []): Model
    {
        return $this->query()->make($attributes);
    }

    /**
     * Shared customer request query-budget optimization (Automations V2
     * §18) — memoizes one read for the life of the current request/
     * container only (RequestScopedCache's own docblock explains the
     * scoping guarantee). A repository opts in per-method by wrapping its
     * existing query in this; every write path it exposes must call
     * `forgetRequestCache()`/`forgetRequestCachePrefixed()` for the same
     * key(s) so a later read in that same request never returns data
     * stale as of before that write.
     */
    protected function rememberForRequest(string $key, Closure $resolver): mixed
    {
        return app(RequestScopedCache::class)->remember($key, $resolver);
    }

    protected function forgetRequestCache(string $key): void
    {
        app(RequestScopedCache::class)->forget($key);
    }

    protected function forgetRequestCachePrefixed(string $prefix): void
    {
        app(RequestScopedCache::class)->forgetPrefixed($prefix);
    }

}
