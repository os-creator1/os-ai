<?php

    namespace App\Library\Traits;

    use App\Models\JobMonitor;
    use Closure;
    use Exception;
    use Illuminate\Bus\Batch;
    use Illuminate\Contracts\Bus\Dispatcher;
    use Illuminate\Database\Eloquent\Relations\HasMany;
    use Illuminate\Support\Collection;
    use Illuminate\Support\Facades\Bus;
    use Illuminate\Support\Facades\Log;
    use Throwable;

    trait TrackJobs
    {
        /**
         * Relationship: job monitors for this model instance.
         *
         * @return HasMany
         */
        public function jobMonitors(): HasMany
        {
            return $this->hasMany(JobMonitor::class, 'subject_id')
                ->where('subject_name', static::class);
        }

        /**
         * Dispatch a single job and create a JobMonitor for it.
         *
         * @param object      $job
         * @param string|null $jobType
         * @param bool        $afterCommit If true, dispatch after DB commit (only for single-job dispatch)
         * @return JobMonitor
         *
         * @throws Throwable
         */
        public function dispatchWithMonitor(object $job, ?string $jobType = null, bool $afterCommit = false): JobMonitor
        {
            if (is_null($jobType)) {
                $jobType = get_class($job);
            }

            // Ensure job supports monitor (Trackable)
            if ( ! method_exists($job, 'setMonitor')) {
                throw new Exception(sprintf(
                    'Job class `%s` must implement setMonitor() (use Trackable trait)',
                    get_class($job)
                ));
            }

            $monitor = JobMonitor::makeInstance($this, $jobType); // QUEUED
            $monitor->save();

            $job->setMonitor($monitor);

            // Extract the closure(s) we need to run after dispatch (jobs can't carry closures)
            $afterDispatchClosures = $this->extractAndNullifyClosure($job, 'eventAfterDispatched');

            try {
                if ($afterCommit && method_exists($job, 'afterCommit')) {
                    // Optional: if job supports afterCommit chaining (Laravel job instance)
                    $dispatchedJobId = app(Dispatcher::class)->dispatch($job->afterCommit());
                } else {
                    $dispatchedJobId = app(Dispatcher::class)->dispatch($job);
                }

                // Associate job id with monitor
                $monitor->job_id = $dispatchedJobId;
                $monitor->save();

                // Execute after-dispatch closures (if any)
                foreach ($afterDispatchClosures as $closure) {
                    if ( ! is_null($closure)) {
                        $closure($job, $monitor);
                    }
                }

                return $monitor;
            } catch (Throwable $e) {
                // Mark monitor as failed and rethrow
                try {
                    $monitor->setFailed($e);
                } catch (Throwable $_) {
                    Log::error('Failed saving monitor failure state: ' . $_->getMessage());
                }

                throw $e;
            }
        }

        /**
         * Dispatch an array of jobs as a batch and create a JobMonitor for the batch.
         *
         * Important: don't run this inside a DB transaction else batch/job ids may not be available immediately.
         *
         * @param string           $jobType
         * @param array|Collection $jobs
         * @param null             $thenCallback function (Batch $batch) { ... }
         * @param null             $catchCallback function (Batch $batch, Throwable $e) { ... }
         * @param null             $finallyCallback function (Batch $batch) { ... }
         * @return JobMonitor
         *
         * @throws Throwable
         */
        public function dispatchWithBatchMonitor(string $jobType, array|Collection $jobs, $thenCallback = null, $catchCallback = null, $finallyCallback = null): JobMonitor
        {
            $jobs = collect($jobs);

            // Validate all jobs support setMonitor
            $jobs->each(function ($job) {
                if ( ! method_exists($job, 'setMonitor')) {
                    throw new Exception(sprintf(
                        'Job class `%s` must use `Trackable` trait (setMonitor method required)',
                        get_class($job)
                    ));
                }
            });

            // Create and persist monitor
            $monitor = JobMonitor::makeInstance($this, $jobType);
            $monitor->save();

            // Attach monitor to each job
            $jobs->each(fn($job) => $job->setMonitor($monitor));

            // If single-job batch, capture after-dispatched closures so they can be executed locally
            $events = [];
            if ($jobs->count() === 1) {
                $job                       = $jobs->first();
                $events['afterDispatched'] = $this->extractAndNullifyClosure($job, 'eventAfterDispatched')[0] ?? null;
            }

            // Build batch and dispatch
            try {
                $batchBuilder = Bus::batch($jobs->all())
                    ->then(function (Batch $batch) use ($monitor, $thenCallback) {
                        $monitor->setDone();

                        if ( ! is_null($thenCallback)) {
                            $thenCallback($batch);
                        }
                    })
                    ->catch(function (Batch $batch, Throwable $e) use ($monitor, $catchCallback) {
                        $monitor->setFailed($e);

                        if ( ! is_null($catchCallback)) {
                            $catchCallback($batch, $e);
                        }
                    })
                    ->finally(function (Batch $batch) use ($monitor, $finallyCallback) {
                        if ( ! is_null($finallyCallback)) {
                            $finallyCallback($batch);
                        }
                    })
                    ->onQueue('batch');

                // Dispatch and get Batch instance (dispatch() returns Batch)
                $batch = $batchBuilder->dispatch();

                // Persist batch id
                $monitor->batch_id = $batch->id;
                $monitor->save();

                // Execute afterDispatched closure for single-job batches
                if (array_key_exists('afterDispatched', $events) && $events['afterDispatched'] instanceof Closure) {
                    $closure = $events['afterDispatched'];
                    $job     = $jobs->first();
                    $closure($job, $monitor);
                }

                return $monitor;
            } catch (Throwable $e) {
                // If dispatching the batch fails, mark monitor as failed and rethrow
                try {
                    $monitor->setFailed($e);
                } catch (Throwable $_) {
                    Log::error('Failed saving monitor failure state after batch dispatch error: ' . $_->getMessage());
                }

                throw $e;
            }
        }

        /**
         * Cancel and delete (cancel only) jobs of a given type for this subject.
         *
         * @param string|null $jobType
         * @return void
         */
        public function cancelAndDeleteJobs(?string $jobType = null): void
        {
            $query = $this->jobMonitors();

            if ( ! is_null($jobType)) {
                $query = $query->byJobType($jobType);
            }

            $query->get()->each(fn($job) => $job->cancel());
        }

        /**
         * Extract named closure(s) from job, nullify them on the job (so Laravel can serialize it),
         * and return an array of closures (or nulls).
         *
         * @param object       $job
         * @param array|string $property
         * @return array<int, Closure|null>
         */
        protected function extractAndNullifyClosure(object $job, array|string $property): array
        {
            $properties = is_array($property) ? $property : [$property];
            $closures   = [];

            foreach ($properties as $prop) {
                $val = null;
                if (property_exists($job, $prop)) {
                    $val          = $job->{$prop} ?? null;
                    $job->{$prop} = null;
                }
                $closures[] = $val;
            }

            return $closures;
        }

    }
