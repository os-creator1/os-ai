<?php


    /*
    |--------------------------------------------------------------------------
    | Plugins
    |--------------------------------------------------------------------------
    |
    | These routes are used to manage plugins.
    |
    */
    Route::get('plugins', 'PluginsController@plugins')->name('plugins');
    Route::get('install-plugin', 'PluginsController@install')->name('plugins.install');
    Route::post('install-plugin', 'PluginsController@upload');
    Route::post('plugins/{plugin}/{action}', 'PluginsController@action')->name('plugins.action');

    Route::get('assets/{dirname}/{basename}', 'PluginsController@asset')
        ->name('plugins.asset');
