<?php

namespace App\Repositories\Contracts;

use App\Models\Automation;

interface AutomationsRepository extends BaseRepository
{

    /**
     * send automation
     *
     * @param  Automation  $automation
     * @param  array  $input
     *
     * @return mixed
     */
    public function automationBuilder(Automation $automation, array $input);

    /**
     * Enable automation
     *
     * @param  Automation  $automation
     *
     * @return mixed
     */
    public function enable(Automation $automation);

    /**
     * Disable automation
     *
     * @param  Automation  $automation
     *
     * @return mixed
     */
    public function disable(Automation $automation);

    /**
     * Delete automation
     *
     * @param  Automation  $automation
     *
     * @return mixed
     */
    public function delete(Automation $automation);


    /**
     * @param  array  $ids
     *
     * @return mixed
     */
    public function batchDelete(array $ids);

    /**
     * @param  array  $ids
     *
     * @return mixed
     */
    public function batchEnable(array $ids);

    /**
     * @param  array  $ids
     *
     * @return mixed
     */
    public function batchDisable(array $ids);


}
