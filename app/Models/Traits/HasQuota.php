<?php


namespace App\Models\Traits;


use App\Library\Exception\NoCreditsLeft;
use App\Library\QuotaManager;
use Carbon\Carbon;
use Exception;

trait HasQuota
{
    public function getQuotaManager($name)
    {
        return new QuotaManager($this, $name);
    }

    /**
     * @param $name
     * @param $count
     *
     * @return null
     */
    public function addCredits($name, $count)
    {
        return $this->getQuotaManager($name)->addCredits($count);
    }

    public function setCredits($name, $count)
    {
        return $this->getQuotaManager($name)->setCredits($count);
    }

    /**
     * @throws Exception
     */
    public function getCreditsUsed(string $name, Carbon $from = null, Carbon $to = null)
    {
        return $this->getQuotaManager($name)->getCreditsUsed($from, $to);
    }

    public function cleanupCreditsStorageFiles($name)
    {
        return $this->getQuotaManager($name)->cleanup();
    }

    /**
     * @throws Exception
     */
    public function getRemainingCredits($name)
    {
        return $this->getQuotaManager($name)->getRemainingCredits();
    }

    /**
     * @throws NoCreditsLeft
     */
    public function updateRemainingCredits($name)
    {
        return $this->getQuotaManager($name)->updateRemainingCredits();
    }
}
