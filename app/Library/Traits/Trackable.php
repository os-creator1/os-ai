<?php


namespace App\Library\Traits;


use App\Models\JobMonitor;

trait Trackable
{

    public $monitor;
    public $eventAfterDispatched;
    public $eventAfterFinished;

    /**
     * Set Monitor
     *
     * @param  JobMonitor  $monitor
     *
     * @return void
     */
    public function setMonitor(JobMonitor $monitor): void
    {
        $this->monitor = $monitor;
    }

    /**
     *
     * @param $callback
     *
     * @return void
     */
    public function afterDispatched($callback): void
    {
        $this->eventAfterDispatched = $callback;
    }

    /**
     *
     *
     * @param $callback
     *
     * @return void
     */
    public function afterFinished($callback): void
    {
        $this->eventAfterFinished = $callback;
    }


}
