<?php


    namespace App\Library;


    use App\Helpers\Helper;
    use App\Library\Exception\RateLimitExceeded;
    use Carbon\Carbon;
    use Exception;
    use Illuminate\Support\Facades\Cache;

    class DynamicRateTracker
    {
        protected $resourceKey;
        protected $reservedPath;
        protected $limits;

        protected $separator = ':';

        public function __construct(string $resourceKey, $limits = []) // RateLimit class
        {
            $this->resourceKey = $resourceKey;

            // IMPORTANT: this path is shared among processes of the same server (same filesystem)
            $this->reservedPath = "/tmp/dynamic-rate-tracker-pid-".md5($this->resourceKey);

            $this->limits = $limits;

            if (!file_exists($this->reservedPath)) {
                touch($this->reservedPath);
            }
        }

        public function getReservedPath()
        {
            return $this->reservedPath;
        }

        // Reverse of count()
        // @deprecated: rollback is not needed as even a failed operation is also counted in rate limits
        /**
         * @throws Exception
         */
        public function rollback()
        {
            // This is important, in case a tracker is counted (successfully, returning ok) in the execute_with_limits() function
            // and is later on rolled back
            if (empty($this->limits)) {
                return;
            }

            $lock = new Lockable($this->reservedPath);
            $lock->getExclusiveLock(function ($fopen) {
                [$until, $credits] = $this->parseReservedCredits($fopen);

                if ($until) {
                    $this->updateReservedCredits($until, $credits + 1);
                } else {
                    // do nothing as there is no reserved credits available
                }

            });
        }

        public function getRateLimits()
        {
            return $this->limits;
        }

        // Example of $period: "24 hours", "1 week"
        // i.e. Clean up credit tracking logs that are older than "24 hours", "1 week"
        public function cleanup(string $period = null)
        {
            // no cleanup needed
        }

        private function updateReservedCredits(Carbon $until, $credits)
        {
            file_put_contents($this->reservedPath, "{$until->timestamp}{$this->separator}{$credits}");
        }

        private function countReservedCredits(Carbon $now, $fopen)
        {
            [$until, $credits] = $this->parseReservedCredits($fopen);

            if ($until && $until->gte($now) && $credits > RateLimit::ZERO) {
                $this->updateReservedCredits($until, $credits - 1);
                return true;
            } else {
                return false;
            }
        }

        // Test and throw exception, do not count()
        // Used by DelayedJob
        /**
         * @throws Exception
         */
        public function test(Carbon $now = null)
        {
            $this->count($now, false);
        }

        /**
         * @throws Exception
         */
        public function count(Carbon $now = null, $count = true)
        {
            if (empty($this->limits)) {
                return;
            }

            $now = $now ?: Carbon::now();
            $available = false;

            // Step 1: check if there is a local reservation
            $lock = new Lockable($this->reservedPath);
            $lock->getExclusiveLock(function ($fopen) use ($now, &$available) {
                if ($this->countReservedCredits($now, $fopen)) {
                    $available = true;
                }
            });

            if ($available) {
                // If there is a valid local reservation, then move forward
                // without having to check the remote tracker
                return;
            }

            // If not available locally, check the remote
            Helper::with_cache_lock($this->resourceKey, function () use ($now, $count) {
                if ($this->isReservedByOthers($now)) {
                    // no local reserved
                    // and there is a reserved-until key (by other)

                    $reservedUntil = (int)Cache::get($this->resourceKey);
                    $until = new Carbon($reservedUntil);

                    throw new RateLimitExceeded("Calculated rate limit exceeded: {$this->getMinCredits()}/{$this->getShortestLimitPeriod()} | {$this->getLimitsDescription()}. Will be available in {$until->diffForHumans()}, at {$until->toString()}");
                }

                if (!$count) {
                    // this is for Delay job only
                    // test if it should resume a campaign, do not count ONE use credits
                    return;
                }

                // Okio, so ready
                $until = $now->add($this->getShortestLimitPeriod());
                $credits = $this->getMinCredits();

                // reserve credits for local & remote
                // also count 1 credit used
                $this->reserve($until, $credits - 1);

            });
        }

        public function isReservedByOthers(Carbon $now)
        {
            $reservedUntil = Cache::get($this->resourceKey);

            if (!is_null($reservedUntil) && (new Carbon((int)$reservedUntil))->gte($now)) {
                return true;
            } else {
                return false;
            }
        }

        private function reserve(Carbon $until, $credits)
        {
            // Set local
            $this->updateReservedCredits($until, $credits);

            // Set remote reserve flag
            Cache::put($this->resourceKey, $until->timestamp);
        }

        public function parseReservedCredits($fopen = null)
        {
            if (is_null($fopen)) {
                $fopen = fopen($this->getReservedPath(), 'r');
            }

            rewind($fopen);
            $contents = fgets($fopen);
            if (empty($contents)) {
                return [null, null];
            }

            [$until, $credits] = explode($this->separator, $contents);

            return [new Carbon((int)$until), (int)$credits];
        }

        public function getShortestLimitPeriod()
        {
            $shortest = null;
            $now = now();
            foreach($this->limits as $limit) {
                $d = $now->copy()->add($limit->getPeriod());
                if (is_null($shortest) || $now->copy()->add($shortest->getPeriod())->gte($d)) {
                    $shortest = $limit;
                }
            }

            return $shortest->getPeriod();
        }

        public function getMinCredits()
        {
            $min = null;
            foreach($this->limits as $limit) {
                if (is_null($min) || $limit->getAmount() < $min) {
                    $min = $limit->getAmount();
                }
            }

            return $min;
        }

        public function clearReservation()
        {
            file_put_contents($this->getReservedPath(), '');
            Cache::forget($this->resourceKey);
        }

        public function getLimitsDescription()
        {
            $str = [];
            foreach ($this->getRateLimits() as $limit) {
                $str[] = $limit->getDescription();
            }

            return implode(', ', $str);
        }
    }
