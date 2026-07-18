<?php

namespace App\Repositories\Contracts;

use App\Models\Campaigns;

interface CampaignRepository extends BaseRepository
{
    /**
     * send quick message
     *
     * @param  Campaigns  $campaign
     * @param  array  $input
     *
     * @return mixed
     */
    public function quickSend(Campaigns $campaign, array $input);

    /**
     * send campaign
     *
     * @param  Campaigns  $campaign
     * @param  array  $input
     *
     * @return mixed
     */
    public function campaignBuilder(Campaigns $campaign, array $input);


    /**
     * send campaign using file
     *
     * @param  Campaigns  $campaign
     * @param  array  $input
     *
     * @return mixed
     */
    public function sendUsingFile(Campaigns $campaign, array $input);

    /**
     * pause campaign
     *
     * @param  Campaigns  $campaign
     *
     * @return mixed
     */
    public function pause(Campaigns $campaign);

    /**
     * Restart campaign
     *
     * @param  Campaigns  $campaign
     *
     * @return mixed
     */
    public function restart(Campaigns $campaign);


    /**
     * resend existing campaign
     *
     * @param  Campaigns  $campaign
     *
     * @return mixed
     */
    public function resend(Campaigns $campaign);

    /**
     * send api message
     *
     * @param  Campaigns  $campaign
     * @param  array  $input
     *
     * @return mixed
     */
    public function sendApi(Campaigns $campaign, array $input);

    /*
    |--------------------------------------------------------------------------
    | Version 3.7
    |--------------------------------------------------------------------------
    |
    | Send Campaign Using API
    |
    */


    /**
     * send campaign using API
     *
     * @param  Campaigns  $campaign
     * @param  array  $input
     *
     * @return mixed
     */
    public function apiCampaignBuilder(Campaigns $campaign, array $input);


    /*Version 3.8*/
    /*
    |--------------------------------------------------------------------------
    | make faster quick send
    |--------------------------------------------------------------------------
    |
    |
    |
    */

    public function checkQuickSendValidation(array $input);

}
