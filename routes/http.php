<?php


    /*
    |--------------------------------------------------------------------------
    | API Routes
    |--------------------------------------------------------------------------
    |
    | Here is where you can register API routes for your application. These
    | routes are loaded by the RouteServiceProvider within a group which
    | is assigned the "api" middleware group. Enjoy building your API!
    |
    */


    Route::get('/me', 'APIHTTPController@me')->name('profile.me');
    Route::get('/balance', 'APIHTTPController@balance')->name('profile.balance');


    /*
    |--------------------------------------------------------------------------
    | contact module routes
    |--------------------------------------------------------------------------
    |
    |
    |
    */


    Route::post('contacts/{group_id}/all', 'ContactsHTTPController@allContact')->name('contact.all');
    Route::post('contacts/{group_id}/search/{uid}', 'ContactsHTTPController@searchContact')->name('contact.search');
    Route::post('contacts/{group_id}/store', 'ContactsHTTPController@storeContact')->name('contact.store');
    Route::patch('contacts/{group_id}/update/{uid}', 'ContactsHTTPController@updateContact')->name('contact.update');
    Route::delete('contacts/{group_id}/delete/{uid}', 'ContactsHTTPController@deleteContact')->name('contact.delete');

    /*
    |--------------------------------------------------------------------------
    | contact groups module route
    |--------------------------------------------------------------------------
    |
    |
    |
    */
    Route::resource('contacts', 'ContactsHTTPController', [
        'only' => ['index', 'store', 'update', 'destroy'],
    ]);
    Route::post('contacts/{group_id}/show', 'ContactsHTTPController@show')->name('contacts.show');


    /*
    |--------------------------------------------------------------------------
    | send message module including plain, voice, mms, and whatsapp
    |--------------------------------------------------------------------------
    |
    |
    |
    */
    Route::get('sms', 'CampaignHTTPController@viewAllSMS')->name('sms.index');
    Route::any('sms/send', 'CampaignHTTPController@smsSend')->name('sms.send');
    Route::any('sms/campaign', 'CampaignHTTPController@campaign')->name('sms.campaign');
    Route::get('sms/{uid}', 'CampaignHTTPController@viewSMS')->name('sms.view');

    /*
    |-------------------------------------------------------------------------
    | View Campaign
    |-------------------------------------------------------------------------
    |
    |
    |
    */

    Route::get('campaign/{uid}/view', 'CampaignHTTPController@viewCampaign')->name('campaign.view');
