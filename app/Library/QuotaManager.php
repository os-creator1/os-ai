<?php


namespace App\Library;


use App\Library\Contracts\HasQuota as HasQuotaInterface;
use App\Library\Exception\NoCreditsLeft;
use App\Library\Exception\QuotaExceeded;
use Carbon\Carbon;
use Closure;
use Exception;
use File;
use function App\Helpers\ptouch;

class QuotaManager
{
    public const QUOTA_ZERO      = 0;
    public const QUOTA_UNLIMITED = -1;

    protected $subject;
    protected $name;
    protected $quotaTracker;

    protected $quotaExceededCallback;

    public function __construct(HasQuotaInterface $subject, string $name)
    {
        $this->subject = $subject;
        $this->name    = $name;
    }

    public function whenQuotaExceeded(Closure $callback): static
    {
        $this->quotaExceededCallback = $callback;

        return $this;
    }

    public static function with(HasQuotaInterface $subject, string $name): static
    {
        return new static($subject, $name);
    }

    public function getCreditsStorageFile(): string
    {
        // store remaining credits
        return storage_path("app/quota/credits-{$this->subject->getUid()}");
    }

    public function getCreditsLogFile(): string
    {
        return storage_path("app/quota/credits-log-{$this->name}-{$this->subject->getUid()}");
    }

    public function cleanup(): void
    {
        $files = [
                $this->getCreditsStorageFile(),
                $this->getCreditsLogFile(),
        ];

        foreach ($files as $file) {
            if (File::exists($file)) {
                File::delete($file);
            }
        }
    }

    public function getQuotaTracker(): QuotaTracker
    {
        if (is_null($this->quotaTracker)) {
            $this->quotaTracker = new QuotaTracker($this->getCreditsLogFile());
        }

        return $this->quotaTracker;
    }

    public function getCreditsStorageJson()
    {
        $filepath = $this->getCreditsStorageFile();

        if ( ! file_exists($filepath)) {
            ptouch($filepath);
        }

        return json_decode(file_get_contents($filepath), true) ?: [];
    }

    private function updateCreditsLog($json): void
    {
        $filepath = $this->getCreditsStorageFile();
        file_put_contents($filepath, json_encode($json));
    }

    /**
     * @throws Exception
     */
    public function getRemainingCredits()
    {
        $json = $this->getCreditsStorageJson();
        if ( ! array_key_exists($this->name, $json)) {
            throw new Exception(sprintf('Credits limit for object %s#%s is not initialized yet. Initiate it with setCredits or addCredits first', get_class($this->subject), $this->subject->uid));
        }

        // This is the remaining credits
        return $json[$this->name];
    }

    public function addCredits($count): void
    {
        $json = $this->getCreditsStorageJson();

        if ( ! array_key_exists($this->name, $json)) {
            $json[$this->name] = $count;
        } else {
            $json[$this->name] = $json[$this->name] + $count;
        }

        $this->updateCreditsLog($json);
    }

    public function setCredits($count): void
    {
        $json              = $this->getCreditsStorageJson();
        $json[$this->name] = $count;
        $this->updateCreditsLog($json);
    }

    // MAIN

    /**
     * @throws NoCreditsLeft
     * @throws Exception
     */
    public function updateRemainingCredits(): void
    {
        $json = $this->getCreditsStorageJson();
        if ( ! array_key_exists($this->name, $json)) {
            throw new Exception('No credits information available, initialize it first by setCredits() or addCredits()');
        }

        $remaining = $json[$this->name];

        if ($remaining === self::QUOTA_UNLIMITED) {
            // QUOTA_UNLIMITED (-1) also means "unlimited"
            return;
        }

        if ($remaining === self::QUOTA_ZERO) {
            // QUOTA_ZERO (0) means "limit reached"
            throw new NoCreditsLeft("Credits remaining is already 0");
        }

        // Deduct remaining credits
        $json[$this->name] = $remaining - 1;
        $this->updateCreditsLog($json);
    }

    /**
     * @throws Exception
     */
    public function getCreditsUsed(Carbon $from = null, Carbon $to = null): float|int
    {
        return $this->getQuotaTracker()->getCreditsUsed($from, $to);
    }


    /**
     * @param  bool|null  $countCredits
     *
     * @return void
     * @throws QuotaExceeded
     */
    public function count(?bool $countCredits = true): void
    {
        // Check available credits and quota
        // Count if passed, throw exception otherwise
        $limits = $this->subject->getQuotaSettings($this->name);

        // Check quota allowance
        $now = Carbon::now('UTC');

        // instantiate the QuotaTracker object
        $tracker = $this->getQuotaTracker();

        if ( ! is_null($this->quotaExceededCallback)) {
            $tracker->whenQuotaExceeded($this->quotaExceededCallback);
        }

        // Count quota use
        $tracker->count($now, $limits, function () use ($countCredits) {
            // Take advantage of the lock to count remaining credits
            if ($countCredits) {
                $this->updateRemainingCredits();
            }
        });
    }

    /**
     * @throws QuotaExceeded
     */
    public function enforce(): void
    {
        $this->count(false);
    }
}
