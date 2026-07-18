<?php


namespace App\Library;

use Closure;
use Exception;

class Lockable
{
    /**
     * Get exclusive lock.
     */
    private $file;

    public function __construct($file)
    {
        if (!file_exists($file)) {
            touch($file);
        }

        $this->file = $file;
    }

    /**
     * Get exclusive lock.
     * @throws Exception
     */
    public function getExclusiveLock($callback, $timeout = 15, $timeoutCallback = null)
    {
        $start = time();
        $reader = fopen($this->file, 'r+');
        try {
            while (true) {
                // raise an exception and quit if timed out
                if ($this->isTimeout($start, $timeout)) {
                    if (is_null($timeoutCallback)) {
                        throw new Exception('Timeout getting lock #Lockable for: '.$this->file);
                    } else {
                        $timeoutCallback();
                        break;
                    }
                }

                if (flock($reader, LOCK_EX | LOCK_NB)) {  // acquire an exclusive lock
                    // execute the callback
                    $callback($reader);
                    break;
                }
            }
        } finally {
            fflush($reader);
            flock($reader, LOCK_UN);    // release the lock
            fclose($reader);
        }
    }

    /**
     * Get shared lock.
     * @throws Exception
     */
    public function getSharedLock($callback, $timeout = 5, $timeoutCallback = null)
    {
        $start = time();
        $reader = fopen($this->file, 'r');
        while (true) {
            // raise an exception and quit if timed out
            if ($this->isTimeout($start, $timeout)) {
                if (is_null($timeoutCallback)) {
                    throw new Exception('Timeout getting lock #Lockable for: '.$this->file);
                } else {
                    $timeoutCallback();
                    break;
                }
            }

            if (flock($reader, LOCK_SH | LOCK_NB)) {  // acquire an exclusive lock
                // execute the callback
                $callback($this);

                flock($reader, LOCK_UN);    // release the lock
                fclose($reader);
                break;
            }
        }
    }

    /**
     * Check for timeout.
     */
    public function isTimeout($startTime, $timeoutDuration)
    {
        return (time() - $startTime > $timeoutDuration);
    }

    // Convenient shortcut to shorten execution code (do not have to instantiate the lock object)

    /**
     * @throws Exception
     */
    public static function withExclusiveLock(string $lockFile, Closure $task, int $waitFor = 15, Closure $waitTimeoutCallback = null)
    {
        $lock = new static($lockFile);
        $lock->getExclusiveLock($task, $waitFor, $waitTimeoutCallback);
    }
}
