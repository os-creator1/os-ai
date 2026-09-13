<?php

namespace App\Listeners\Support;

use App\Library\Support\RequestScopedCache;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

/**
 * The queue-job boundary for RequestScopedCache — the one place it is enforced.
 *
 * WHY THIS EXISTS. RequestScopedCache memoizes on the bound Request. An HTTP
 * request gets a fresh Request, so its memo dies with it. A console process
 * does not: SetRequestForConsole binds one Request at startup and it stays
 * bound for the whole process, so a `queue:work` daemon — which this
 * application's scheduler runs every minute, each run processing job after
 * job — shared one memo across every job. Job A's "plan active" answer
 * survived into job B after the plan was suspended.
 *
 * WHAT IT DOES. It flushes the cache at both edges of every job a worker
 * processes:
 *
 *   JobProcessing          before the job runs, so every job starts empty
 *                          whatever ran before it in this process;
 *   JobProcessed           after it succeeds, so it leaves nothing behind;
 *   JobExceptionOccurred   after it throws, so a FAILED job leaves nothing
 *                          behind either — including one that is released
 *                          for a retry or marked failed.
 *
 * One listener on the framework's own queue events, for every job class, so
 * no individual job has to remember to clear anything.
 *
 * SYNC JOBS ARE DELIBERATELY LEFT ALONE. A job on the `sync` connection is not
 * a new unit of work: it runs inline, inside whatever dispatched it — normally
 * an HTTP request. Flushing there would throw away that request's memo in the
 * middle of the request, which is exactly the optimization RequestScopedCache
 * exists for, and it would buy nothing: the request's own write paths already
 * invalidate the keys they change. (The application dispatches no job
 * synchronously today; tests run the sync driver, which is why this matters.)
 */
class ResetRequestScopedCacheAtJobBoundary
{
    public function __construct(private readonly RequestScopedCache $cache)
    {
    }

    public function handle(JobProcessing|JobProcessed|JobExceptionOccurred $event): void
    {
        if ($this->runsInline($event->connectionName)) {
            return;
        }

        $this->cache->flush();
    }

    /**
     * Whether jobs on this connection run inline in their dispatcher. Decided
     * by the connection's DRIVER, not its name, so a sync connection configured
     * under any name is recognised.
     */
    private function runsInline(string $connectionName): bool
    {
        return config("queue.connections.{$connectionName}.driver") === 'sync';
    }
}
