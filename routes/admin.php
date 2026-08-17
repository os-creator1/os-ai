<?php

    use App\Http\Middleware\EnsureUserIsAdministrator;

    /*
     * All routes for admin portal
     *
     * Item Name: Ultimate SMS - Bulk SMS Application For Marketing
     * Author: Codeglen
     * Author URL: https://codecanyon.net/user/codeglen
     */

    Route::get('/dashboard', 'AdminBaseController@index')->name('home');

    /*
    |--------------------------------------------------------------------------
    | Customer module
    |--------------------------------------------------------------------------
    |
    | Route for Customer module
    |
    */

    Route::post('customers/search', 'CustomerController@search')->name('customers.search');
    Route::get('customers/import', 'CustomerController@import')->name('customers.import');
    Route::post('customers/import', 'CustomerController@importPost');
    Route::get('customers/download-customer-sample-file', 'CustomerController@downloadCustomerSampleFile')->name('customers.download_customer_sample_file');
    Route::post('customers/import-mapping', 'CustomerController@importMapping')->name('customers.import-mapping');
    Route::post('customers/import-run', 'CustomerController@importRun')->name('customers.import-run');
    Route::get('customers/export', 'CustomerController@export')->name('customers.export');
    Route::get('customers/{customer}/show', 'CustomerController@show')->name('customers.show');
    Route::get('customers/{customer}/impersonate', 'CustomerController@impersonate')->name('customers.login_as');
    Route::get('customers/{customer}/assign-plan', 'CustomerController@show')->name('customers.assign_plan');
    Route::get('customers/{customer}/avatar', 'CustomerController@avatar')->name('customers.avatar');
    Route::post('customers/{customer}/avatar', 'CustomerController@updateAvatar');
    Route::post('customers/{customer}/remove-avatar', 'CustomerController@removeAvatar');
    Route::post('customers/{customer}/add-unit', 'CustomerController@addUnit')->name('customers.add_unit');
    Route::post('customers/{customer}/remove-unit', 'CustomerController@removeUnit')->name('customers.remove_unit');
    Route::post('customers/{customer}/update-information', 'CustomerController@updateInformation')->name('customers.update_information');
    Route::post('customers/{customer}/permissions', 'CustomerController@permissions')->name('customers.permissions');
    Route::post('customers/{customer}/active', 'CustomerController@activeToggle')->name('customers.active');
    Route::post('customers/batch_action', 'CustomerController@batchAction')->name('customers.batch_action');

    Route::resource('customers', 'CustomerController', [
        'only' => ['index', 'create', 'store', 'update', 'destroy'],
    ]);

    /*Version 3.7*/

    Route::post('customers/{customer}/pricing', 'CustomerController@pricing')->name('customers.pricing');
    Route::get('customers/{customer}/coverage', 'CustomerController@coverage')->name('customers.coverage');
    Route::post('customers/{customer}/coverage', 'CustomerController@postCoverage');

    Route::get('customers/{customer}/edit-coverage/{coverage}', 'CustomerController@editCoverage')->name('customers.edit_coverage');
    Route::post('customers/{customer}/edit-coverage/{coverage}', 'CustomerController@editCoveragePost');

    Route::post('customers/{customer}/coverage/{coverage}/active', 'CustomerController@activeCoverageToggle')->name('customers.coverage.active');
    Route::post('customers/{customer}/coverage/{coverage}/delete', 'CustomerController@deleteCoverage')->name('customers.coverage.delete');

    /*Version 3.8*/
    Route::post('customers/{customer}/sending-server', 'CustomerController@addSendingServer')->name('customers.sending-server');
    Route::post('customers/{customer}/delete-sending-server', 'CustomerController@deleteSendingServer')->name('customers.sending-server.delete');
    Route::post('customers/{customer}/dlt-entity-id', 'CustomerController@dltEntityId')->name('customers.dlt-entity-id');
    Route::post('customers/{customer}/dlt-telemarketer-id', 'CustomerController@dltTelemarketerId')->name('customers.dlt-telemarketer-id');

    /*
    |--------------------------------------------------------------------------
    | Subscription module
    |--------------------------------------------------------------------------
    |
    | Route for Subscription module
    |
    */

    Route::post('subscriptions/search', 'SubscriptionController@search')->name('subscriptions.search');
    Route::post('subscriptions/{subscription}/approve-pending', 'SubscriptionController@approvePending')->name('subscriptions.approve_pending');
    Route::post('subscriptions/{subscription}/reject-pending', 'SubscriptionController@rejectPending')->name('subscriptions.reject_pending');
    Route::post('subscriptions/{subscription}/cancel', 'SubscriptionController@cancel')->name('subscriptions.cancel');
    Route::get('subscriptions/{subscription}/logs', 'SubscriptionController@logs')->name('subscriptions.logs');
    Route::post('subscriptions/batch_action', 'SubscriptionController@batchAction')->name('subscriptions.batch_action');

    Route::resource('subscriptions', 'SubscriptionController', [
        'only' => ['index', 'create', 'store', 'destroy'],
    ]);

    /*
    |--------------------------------------------------------------------------
    | Currency module
    |--------------------------------------------------------------------------
    |
    | Route for Currency module
    |
    */

    Route::post('currencies/search', 'CurrencyController@search')->name('currencies.search');
    Route::get('currencies/export', 'CurrencyController@export')->name('currencies.export');
    Route::get('currencies/{currency}/show', 'CurrencyController@show')->name('currencies.show');
    Route::post('currencies/{currency}/active', 'CurrencyController@activeToggle')->name('currencies.active');
    Route::post('currencies/batch_action', 'CurrencyController@batchAction')->name('currencies.batch_action');

    Route::resource('currencies', 'CurrencyController', [
        'only' => ['index', 'create', 'store', 'update', 'destroy'],
    ]);

    /*
    |--------------------------------------------------------------------------
    | Sending servers module
    |--------------------------------------------------------------------------
    |
    | Route for Sending servers module
    |
    */

    Route::post('sending-servers/search', 'SendingServerController@search')->name('sending-servers.search');
    Route::get('sending-servers/select', 'SendingServerController@select')->name('sending-servers.select');
    Route::get('sending-servers/create/{type}', 'SendingServerController@create')->name('sending-servers.create');
    Route::get('sending-servers/export', 'SendingServerController@export')->name('sending-servers.export');
    Route::get('sending-servers/{server}/show', 'SendingServerController@show')->name('sending-servers.show');
    Route::post('sending-servers/{server}/active', 'SendingServerController@activeToggle')->name('sending-servers.active');
    Route::post('sending-servers/custom-server/create', 'SendingServerController@addCustomServer')->name('sending-servers.add.custom');
    Route::post('sending-servers/custom-server/update/{sending_server}', 'SendingServerController@updateCustomServer')->name('sending-servers.update.custom');
    Route::post('sending-servers/batch_action', 'SendingServerController@batchAction')->name('sending-servers.batch_action');

    /*For WhatSender Only*/

    Route::get('sending-servers/{server}/devices', 'SendingServerController@devices')->name('sending-servers.devices');
    Route::post('sending-servers/{server}/reboot', 'SendingServerController@reboot')->name('sending-servers.reboot');
    Route::post('sending-servers/{server}/reset', 'SendingServerController@reset')->name('sending-servers.reset');
    Route::post('sending-servers/{server}/scan', 'SendingServerController@scan')->name('sending-servers.scan');
    Route::post('sending-servers/{server}/sync', 'SendingServerController@sync')->name('sending-servers.sync');
    Route::post('sending-servers/{server}/start', 'SendingServerController@start')->name('sending-servers.start');

    /*WhatSender Route End here*/

    Route::resource('sending-servers', 'SendingServerController', [
        'only' => ['index', 'store', 'update', 'destroy'],
    ]);

    /*Version 3.13*/
    Route::get('sending-servers/{server}/billing', 'SendingServerController@billing')->name('sending-servers.billing');
    Route::post('sending-servers/{server}/coverage', 'SendingServerController@coverage')->name('sending-servers.coverage');
    Route::get('sending-servers/{server}/add-coverage', 'SendingServerController@addCoverage')->name('sending-servers.add-coverage');
    Route::post('sending-servers/{server}/add-coverage', 'SendingServerController@postAddCoverage');
    Route::get('sending-servers/{server}/edit-coverage/{country}', 'SendingServerController@editCoverage')->name('sending-servers.edit-coverage');
    Route::post('sending-servers/{server}/edit-coverage/{country}', 'SendingServerController@editCoveragePost');
    Route::post('sending-servers/{server}/coverage/{coverage}/active', 'SendingServerController@activeCoverageToggle')->name('sending-servers.coverage.active');
    Route::post('sending-servers/{server}/coverage/{coverage}/delete', 'SendingServerController@deleteCoverage')->name('sending-servers.coverage.delete');
    Route::post('sending-servers/{server}/coverage-bulk-actions', 'SendingServerController@coverageBulkActions')->name('sending-server.coverage-bulk-actions');


//Plan For Plan module
    /**
     * Plan details
     * 1. Name of plan
     * 2. Price for plan
     * 3. description (optional)
     * 4. Billing cycle (Daily, Monthly, yearly, custom - [integer amount with day, week, month, year]
     * 5. Currency
     * 6. Billing Information (optional)
     *
     * Quota
     * 1. SMS Sending Credits
     * 2. Max List/Phone book
     * 3. Max Subscriber
     * 4. Max subscriber per list
     *
     * Plan features
     * 1. Customer can import list
     * 2. Customer can export list
     * 3. Customer can use API
     * 4. Customer can create own sending server
     * 5. Customer can create sub-accounts
     * 6. Customer can delete sms history
     * 7. Add Previous sms balance on next subscription
     * 8. Sender ID Verification
     *
     * Pricing
     * 1. Coverage country
     * 2. plain, voice, mms, whatsapp message price
     *
     *Speed Limit
     * 1. Set a limit [unlimited, 100 sms per minute, 10000 sms per hour, 10000 sms per hour, 10,000 sms per day, 50,000 sms per day, custom - Sending Credits - Time Value - Time unit]
     * 2. Max Number of processes [1,2,3]
     *
     * Sending Servers
     * 1. Add multiple sending server (Rotate sending server when message will send)
     * 2. Set probability
     */
    Route::post('plans/search', 'PlanController@search')->name('plans.search');
    Route::get('plans/export', 'PlanController@export')->name('plans.export');
    Route::get('plans/{plan}/show', 'PlanController@show')->name('plans.show');
    Route::post('plans/{plan}/active', 'PlanController@activeToggle')->name('plans.active');
    Route::post('plans/{plan}/settings', 'PlanController@settingFeatures')->name('plans.settings.features');
    Route::post('plans/{plan}/speed-limit', 'PlanController@updateSpeedLimit')->name('plans.settings.speed-limit');
    Route::post('plans/{plan}/pricing', 'PlanController@updatePricing')->name('plans.settings.pricing');
    Route::get('plans/{plan}/coverage', 'PlanController@addCoverage')->name('plans.settings.coverage');
    Route::post('plans/{plan}/coverage', 'PlanController@addCoveragePost');
    Route::post('plans/{plan}/search', 'PlanController@searchCoverage')->name('plans.settings.search_coverage');
    Route::get('plans/{plan}/edit-coverage/{coverage}', 'PlanController@editCoverage')->name('plans.settings.edit_coverage');
    Route::post('plans/{plan}/edit-coverage/{coverage}', 'PlanController@editCoveragePost');
    Route::post('plans/{plan}/coverage/{coverage}/active', 'PlanController@activeCoverageToggle')->name('plans.coverage.active');
    Route::post('plans/{plan}/coverage/{coverage}/delete', 'PlanController@deleteCoverage')->name('plans.coverage.delete');

    Route::post('plans/{plan}/copy', 'PlanController@copy')->name('plans.copy');
    Route::post('plans/batch_action', 'PlanController@batchAction')->name('plans.batch_action');

    Route::resource('plans', 'PlanController', [
        'only' => ['index', 'create', 'store', 'update', 'destroy'],
    ]);

    /*Version 3.5*/
    Route::post('plans/{plan}/sender-id', 'PlanController@updateSenderID')->name('plans.settings.sender_id');

    /*Version 3.9*/
    Route::post('plans/{plan}/update-credit-price', 'PlanController@updateCreditPrice')->name('plans.settings.update-credit-price');
    Route::get('plans/{plan}/add-credit-price-field', 'PlanController@addCreditPriceField')->name('plans.settings.add-credit-price-field');
    Route::post('plans/{plan}/delete-credit-price/{field_id}', 'PlanController@deleteCreditPrice')->name('plans.settings.delete-credit-price');


    /*Version 3.12*/
    Route::post('plans/{plan}/coverage-bulk-actions', 'PlanController@coverageBulkActions')->name('plans.coverage-bulk-actions');
    Route::post('plans/calculate-sms-units', 'PlanController@calculateSMSUnits')->name('plans.calculate.sms.units');
    Route::post('plans/calculate-sms-price', 'PlanController@calculateSMSPrice')->name('plans.calculate.sms.price');

    /*
    |--------------------------------------------------------------------------
    | Keywords module
    |--------------------------------------------------------------------------
    |
    | Route for Keywords module
    |
    */

    Route::post('keywords/search', 'KeywordController@search')->name('keywords.search');
    Route::get('keywords/export', 'KeywordController@export')->name('keywords.export');
    Route::get('keywords/{keyword}/show', 'KeywordController@show')->name('keywords.show');
    Route::post('keywords/{keyword}/remove-mms', 'KeywordController@removeMMS')->name('keywords.remove-mms');
    Route::post('keywords/batch_action', 'KeywordController@batchAction')->name('keywords.batch_action');

    Route::resource('keywords', 'KeywordController', [
        'only' => ['index', 'create', 'store', 'update', 'destroy'],
    ]);

    /*
    |--------------------------------------------------------------------------
    | Sender id module
    |--------------------------------------------------------------------------
    |
    | Route for sender id and sender id plan module
    |
    */

    Route::post('senderid/search', 'SenderIDController@search')->name('senderid.search');
    Route::get('senderid/export', 'SenderIDController@export')->name('senderid.export');
    Route::get('senderid/{senderid}/show', 'SenderIDController@show')->name('senderid.show');
    Route::post('senderid/batch_action', 'SenderIDController@batchAction')->name('senderid.batch_action');
    Route::resource('senderid', 'SenderIDController', [
        'only' => ['index', 'create', 'store', 'update', 'destroy'],
    ]);

    Route::get('senderid/plan', 'SenderIDController@plan')->name('senderid.plan');
    Route::post('senderid/search-plan', 'SenderIDController@searchPlan')->name('senderid.search_plan');
    Route::get('senderid/create-plan', 'SenderIDController@createPlan')->name('senderid.create_plan');
    Route::post('senderid/store-plan', 'SenderIDController@storePlan')->name('senderid.store_plan');
    Route::post('senderid/delete-plan/{plan}', 'SenderIDController@deletePlan')->name('senderid.delete_plan');
    Route::post('senderid/delete-batch-plan', 'SenderIDController@deleteBatchPlan')->name('senderid.delete_batch_plan');

    //Version 3.11

    Route::put('senderid/{senderid}/update-status', 'SenderIDController@updateStatus')->name('senderid.update_status');

    //Version 3.13

    /*
    |--------------------------------------------------------------------------
    | Block Sender ID
    |--------------------------------------------------------------------------
    |
    | These routes works with Block Sender IDs.
    |
    */

    Route::post('block-senderid/search', 'BlockSenderIdController@search')->name('block-senderid.search');
    Route::get('block-senderid/export', 'BlockSenderIdController@export')->name('block-senderid.export');
    Route::post('block-senderid/batch_action', 'BlockSenderIdController@batchAction')->name('block-senderid.batch_action');
    Route::resource('block-senderid', 'BlockSenderIdController', [
        'only' => ['index', 'create', 'store', 'destroy'],
    ]);

    /*
    |--------------------------------------------------------------------------
    | Phone number module
    |--------------------------------------------------------------------------
    |
    | Route for phone number module
    |
    */

    Route::post('phone-numbers/search', 'PhoneNumberController@search')->name('phone-numbers.search');
    Route::get('phone-numbers/export', 'PhoneNumberController@export')->name('phone-numbers.export');
    Route::get('phone-numbers/{number}/show', 'PhoneNumberController@show')->name('phone-numbers.show');
    Route::post('phone-numbers/batch_action', 'PhoneNumberController@batchAction')->name('phone-numbers.batch_action');
    Route::resource('phone-numbers', 'PhoneNumberController', [
        'only' => ['index', 'create', 'store', 'update', 'destroy'],
    ]);

// Template tags Module Routes
    Route::post('tags/search', 'TemplateTagsController@search')->name('tags.search');
    Route::get('tags/export', 'TemplateTagsController@export')->name('tags.export');
    Route::get('tags/{tag}/show', 'TemplateTagsController@show')->name('tags.show');
    Route::post('tags/{tag}/active', 'TemplateTagsController@activeToggle')->name('tags.active');
    Route::post('tags/batch_action', 'TemplateTagsController@batchAction')->name('tags.batch_action');
    Route::resource('tags', 'TemplateTagsController', [
        'only' => ['index', 'create', 'store', 'update', 'destroy'],
    ]);

    /*
    |-------------------------------------------------------------------------
    | Security module
    |-------------------------------------------------------------------------
    |
    | working with blacklists and spam word features in this module
    |
    */

// Blacklists Module Routes
    Route::post('blacklists/search', 'BlacklistsController@search')->name('blacklists.search');
    Route::get('blacklists/export', 'BlacklistsController@export')->name('blacklists.export');
    Route::post('blacklists/batch_action', 'BlacklistsController@batchAction')->name('blacklists.batch_action');
    Route::resource('blacklists', 'BlacklistsController', [
        'only' => ['index', 'create', 'store', 'destroy'],
    ]);

// Spam word Module Routes
    Route::post('spam-word/search', 'SpamWordController@search')->name('spam-word.search');
    Route::get('spam-word/export', 'SpamWordController@export')->name('spam-word.export');
    Route::post('spam-word/batch_action', 'SpamWordController@batchAction')->name('spam-word.batch_action');
    Route::resource('spam-word', 'SpamWordController', [
        'only' => ['index', 'create', 'store', 'destroy'],
    ]);

    /*
    |--------------------------------------------------------------------------
    | Administrator Module
    |--------------------------------------------------------------------------
    |
    | working with different types of admin and associate admin role
    |
    */

//Admin Role Module
    Route::post('roles/search', 'RoleController@search')->name('roles.search');
    Route::get('roles/export', 'RoleController@export')->name('roles.export');
    Route::get('roles/{role}/show', 'RoleController@show')->name('roles.show');
    Route::post('roles/{role}/active', 'RoleController@activeToggle')->name('roles.active');
    Route::post('roles/batch_action', 'RoleController@batchAction')->name('roles.batch_action');
    Route::resource('roles', 'RoleController', [
        'only' => ['index', 'create', 'store', 'update', 'destroy'],
    ]);

//Administrator Module
    Route::post('administrators/search', 'AdministratorController@search')->name('administrators.search');
    Route::get('administrators/export', 'AdministratorController@export')->name('administrators.export');
    Route::get('administrators/{administrator}/show', 'AdministratorController@show')->name('administrators.show');
    Route::post('administrators/{administrator}/active', 'AdministratorController@activeToggle')->name('administrators.active');
    Route::post('administrators/batch_action', 'AdministratorController@batchAction')->name('administrators.batch_action');
    Route::resource('administrators', 'AdministratorController', [
        'only' => ['index', 'create', 'store', 'update', 'destroy'],
    ]);

    /*
    |--------------------------------------------------------------------------
    | settings module
    |--------------------------------------------------------------------------
    |
    | All settings related routes describe here
    |
    */

//All Settings
    Route::get('settings', 'SettingsController@general')->name('settings.general');
    Route::post('settings', 'SettingsController@postGeneral');
    Route::post('settings/email', 'SettingsController@email')->name('settings.email');
    Route::post('settings/authentication', 'SettingsController@authentication')->name('settings.authentication');
    Route::post('settings/permissions', 'SettingsController@permissions')->name('settings.permissions');
    Route::post('settings/notifications', 'SettingsController@notifications')->name('settings.notifications');
    Route::post('settings/pusher', 'SettingsController@pusher')->name('settings.pusher');
    Route::post('settings/license', 'SettingsController@license')->name('settings.license');

    /*Version 3.5*/
    Route::post('settings/dlt', 'SettingsController@dlt')->name('settings.dlt');

    /*Version 3.13*/
    Route::post('settings/gateway-wise-billing', 'SettingsController@gatewayWiseBilling')->name('settings.gateway-wise-billing');

//Language module
    Route::post('languages/{language}/active', 'LanguageController@activeToggle')->name('languages.active');
    Route::get('languages/{language}/download', 'LanguageController@download')->name('languages.download');
    Route::get('languages/{language}/upload', 'LanguageController@upload')->name('languages.upload');
    Route::post('languages/{language}/upload', 'LanguageController@uploadLanguage');
    Route::get('languages/{language}/show', 'LanguageController@show')->name('languages.show');

    Route::resource('languages', 'LanguageController', [
        'only' => ['index', 'create', 'store', 'update', 'destroy'],
    ]);

//country module
    Route::post('countries/search', 'CountriesController@search')->name('countries.search');
    Route::post('countries/{country}/active', 'CountriesController@activeToggle')->name('countries.active');
    Route::resource('countries', 'CountriesController', [
        'only' => ['index', 'create', 'store', 'destroy'],
    ]);

// Payment gateways
    Route::post('payment-gateways/{gateway}/active', 'PaymentMethodController@activeToggle')->name('payment-gateways.active');
    Route::get('payment-gateways/{gateway}/show', 'PaymentMethodController@show')->name('payment-gateways.show');

    Route::resource('payment-gateways', 'PaymentMethodController', [
        'only' => ['index', 'update'],
    ]);

// Email Templates
    Route::post('email-templates/{template}/active', 'EmailTemplateController@activeToggle')->name('email-templates.active');
    Route::get('email-templates/{template}/show', 'EmailTemplateController@show')->name('email-templates.show');

    Route::resource('email-templates', 'EmailTemplateController', [
        'only' => ['index', 'update'],
    ]);

//update application
    Route::get('update-application', 'SettingsController@updateApplication')->name('settings.update_application');
    Route::post('update-application', 'SettingsController@postUpdateApplication');
    Route::get('check-available-update', 'SettingsController@checkAvailableUpdate')->name('settings.check_update');

    Route::post('invoices/search', 'InvoiceController@search')->name('invoices.search');
    Route::get('invoices/{invoice}/view', 'InvoiceController@view')->name('invoices.view');
    Route::get('invoices/{invoice}/print', 'InvoiceController@print')->name('invoices.print');
    Route::post('invoices/{invoice}/approve', 'InvoiceController@approve')->name('invoices.approve');
    Route::post('invoices/batch_action', 'InvoiceController@batchAction')->name('invoices.batch_action');
    Route::resource('invoices', 'InvoiceController', [
        'only' => ['index', 'destroy'],
    ]);

    /*
    |--------------------------------------------------------------------------
    | Reports module
    |--------------------------------------------------------------------------
    |
    |
    |
    */

    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/history', 'ReportsController@reports')->name('all');
        Route::post('/search', 'ReportsController@searchAllMessages')->name('search.all');
        Route::post('/{uid}/view', 'ReportsController@viewReports');
        Route::post('/export', 'ReportsController@export')->name('export');
        Route::post('/{uid}/destroy', 'ReportsController@destroy');
        Route::post('batch_action', 'ReportsController@batchAction')->name('batch_action');

        /*Version 3.7*/

        Route::get('/dashboard', 'ReportsController@dashboard')->name('dashboard');
        Route::post('/dashboard', 'ReportsController@postDashboard');
        Route::get('/export/{campaign}', 'ReportsController@exportCampaign')->name('export.campaign');

        Route::get('/campaigns', 'ReportsController@campaigns')->name('campaigns');
        Route::post('/search/campaigns', 'ReportsController@searchCampaigns')->name('search.campaigns');

        Route::get('/campaigns/{campaign}/overview', 'ReportsController@campaignOverview')->name('campaign.overview');
        Route::post('/campaigns/{campaign}/reports', 'ReportsController@campaignReports')->name('campaign.reports');
        Route::post('/campaigns/{campaign}/delete', 'ReportsController@campaignDelete')->name('campaign.delete');
        Route::post('/campaigns/{campaign}/mark-delivered', 'ReportsController@campaignMarkDelivered')->name('campaigns.mark-delivered');
        Route::post('/campaign/batch_action', 'ReportsController@campaignBatchAction')->name('campaign.batch_action');
        Route::get('/campaign/export', 'ReportsController@campaignExport')->name('campaign.export');

        Route::post('/{uid}/dlr', 'ReportsController@dlrReports');

    });

    /*
    |--------------------------------------------------------------------------
    | Theme Customizer
    |--------------------------------------------------------------------------
    |
    |
    |
    */
    Route::get('customizer', 'ThemeCustomizerController@index')->name('theme.customizer');
    Route::post('customizer', 'ThemeCustomizerController@postCustomizer');

    /*
    |--------------------------------------------------------------------------
    | Templates
    |--------------------------------------------------------------------------
    |
    | Templates for DLT
    |
    */

    Route::post('templates/search', 'TemplateController@search')->name('templates.search');
    Route::get('templates/{template}/show', 'TemplateController@show')->name('templates.show');
    Route::post('templates/{template}/active', 'TemplateController@activeToggle')->name('templates.active');
    Route::post('templates/batch_action', 'TemplateController@batchAction')->name('templates.batch_action');

    Route::resource('templates', 'TemplateController', [
        'only' => ['index', 'create', 'store', 'update', 'destroy'],
    ]);

    /*
    |--------------------------------------------------------------------------
    | Announcements
    |--------------------------------------------------------------------------
    |
    | Send Announcements to customers using Email or SMS
    |
    */

    Route::post('announcements/search', 'AnnouncementsController@search')->name('announcements.search');
    Route::get('announcements/{announcement}/show', 'AnnouncementsController@show')->name('announcements.show');
    Route::post('announcements/batch_action', 'AnnouncementsController@batchAction')->name('announcements.batch_action');

    Route::resource('announcements', 'AnnouncementsController', [
        'only' => ['index', 'create', 'store', 'update', 'destroy'],
    ]);


    /*
    |--------------------------------------------------------------------------
    | Tax Settings
    |--------------------------------------------------------------------------
    |
    |
    |
    */
    Route::delete('tax/remove/{code}', 'TaxController@removeCountry')->name('tax.remove_country');
    Route::match(['get', 'post'], 'tax/add', 'TaxController@addTax')->name('tax.add_tax');
    Route::get('tax/countries', 'TaxController@countries')->name('tax.countries');
    Route::match(['get', 'post'], 'tax/settings', 'TaxController@settings')->name('tax.settings');


    /*
    |--------------------------------------------------------------------------
    | Privacy Policy and Terms of Use
    |--------------------------------------------------------------------------
    |
    |
    |
    */
    Route::get('terms-of-use', 'SettingsController@termsOfUse')->name('settings.terms-of-use');
    Route::post('terms-of-use', 'SettingsController@postTermsOfUse');
    Route::get('privacy-policy', 'SettingsController@privacyPolicy')->name('settings.privacy-policy');
    Route::post('privacy-policy', 'SettingsController@postPrivacyPolicy');


    /*
    |--------------------------------------------------------------------------
    | maintenance-mode
    |--------------------------------------------------------------------------
    |
    |
    |
    */
    Route::get('maintenance-mode', 'SettingsController@maintenanceMode')->name('settings.maintenance-mode');
    Route::post('maintenance-mode', 'SettingsController@postMaintenanceMode');


    /*
    |--------------------------------------------------------------------------
    | AI Settings
    |--------------------------------------------------------------------------
    |
    | Working with OpenAI API.
    |
    */
    Route::get('ai-settings', 'SettingsController@aiSettings')->name('settings.ai-settings');
    Route::post('ai-settings', 'SettingsController@postAiSettings');


    /*
    |--------------------------------------------------------------------------
    | Business module (RFC-001)
    |--------------------------------------------------------------------------
    |
    | Admin-only, intentionally cross-tenant Business management. No create
    | or delete route — the RFC does not permit either from the admin surface.
    |
    | EnsureUserIsAdministrator is an explicit, independent admin-account-type
    | boundary layered on top of the group's blanket 'can:access backend'
    | gate — defense in depth so cross-tenant Business data can never be
    | reached by a non-admin account regardless of what any permission
    | string resolves to. Applied to every action (index/show/edit/update/
    | status) so it cannot be accidentally omitted from one route.
    |
    */
    Route::middleware(EnsureUserIsAdministrator::class)->group(function () {
        Route::resource('businesses', 'BusinessController', [
            'only' => ['index', 'show', 'edit', 'update'],
        ]);
        Route::patch('businesses/{business}/status', 'BusinessController@updateStatus')->name('businesses.status.update');
    });
    Route::post('ai-settings-toggle', 'SettingsController@toggleAiSettings')->name('settings.ai-settings.toggle');


    /*
    |--------------------------------------------------------------------------
    | Opportunity module (RFC-002)
    |--------------------------------------------------------------------------
    |
    | Admin-only, intentionally cross-tenant Opportunity inspection plus
    | snooze/dismiss/reopen mutation on the customer's behalf. Read-only
    | index, detail, and run/candidate inspection routes require 'view
    | opportunities'; the three mutation routes require 'edit opportunities'.
    | No approval/configuration/execution/retry/attestation/create/delete
    | route exists.
    |
    | EnsureUserIsAdministrator is the same independent, explicit admin-account-
    | type boundary layered on top of the group's blanket 'can:access backend'
    | gate as the Business module above (defense in depth).
    |
    | The literal 'opportunities/runs' routes are registered before
    | 'opportunities/{opportunity}', and {opportunity} is additionally
    | constrained to whereNumber(), so 'runs' can never be captured by that
    | wildcard regardless of registration order — a non-numeric segment
    | simply fails the constraint and falls through.
    |
    */
    Route::middleware(EnsureUserIsAdministrator::class)->group(function () {
        Route::get('opportunities/runs', 'OpportunityRunController@index')->name('opportunities.runs.index');
        Route::get('opportunities/runs/{run}', 'OpportunityRunController@show')
            ->whereNumber('run')
            ->name('opportunities.runs.show');

        Route::get('opportunities', 'OpportunityController@index')->name('opportunities.index');
        Route::get('opportunities/{opportunity}', 'OpportunityController@show')
            ->whereNumber('opportunity')
            ->name('opportunities.show');

        Route::post('opportunities/{opportunity}/snooze', 'OpportunityController@snooze')
            ->whereNumber('opportunity')
            ->name('opportunities.snooze');
        Route::post('opportunities/{opportunity}/dismiss', 'OpportunityController@dismiss')
            ->whereNumber('opportunity')
            ->name('opportunities.dismiss');
        Route::post('opportunities/{opportunity}/reopen', 'OpportunityController@reopen')
            ->whereNumber('opportunity')
            ->name('opportunities.reopen');
    });


    /*
    |--------------------------------------------------------------------------
    | Workspace module (RFC-003 Milestone 5)
    |--------------------------------------------------------------------------
    |
    | Admin-only, intentionally cross-tenant, READ-ONLY Workspace inspection
    | (docs/automation/RFC-003-M5-CONTRACT.md). No create/rename/deactivate/
    | reactivate/member/business/ownership-transfer route exists — those
    | remain exclusively the customer-side RFC-003 Milestone 4 surfaces.
    |
    | EnsureUserIsAdministrator is the same independent, explicit admin-account-
    | type boundary layered on top of the group's blanket 'can:access backend'
    | gate as the Business and Opportunity modules above (defense in depth).
    |
    */
    Route::middleware(EnsureUserIsAdministrator::class)->group(function () {
        Route::get('workspaces', 'WorkspaceController@index')->name('workspaces.index');
        Route::get('workspaces/{workspace}', 'WorkspaceController@show')
            ->whereUuid('workspace')
            ->name('workspaces.show');

        /*
        |--------------------------------------------------------------------------
        | Workspace plan catalog / entitlement mutation (RFC-004 Milestone 3)
        |--------------------------------------------------------------------------
        |
        | docs/automation/RFC-004-M3-CONTRACT.md §11. Declared here with no
        | literal "admin/" URI segment and no "admin." name prefix -- both are
        | applied externally by RouteServiceProvider's
        | ->prefix(config('app.admin_path'))->as('admin.') wrapper, exactly
        | like workspaces.index/workspaces.show above.
        |
        */
        Route::get('workspace-plan-catalog', 'WorkspacePlanCatalogController@index')->name('workspace-plan-catalog.index');

        /*
        |--------------------------------------------------------------------------
        | Payment provider events (RFC-005 Milestone 3, Correction Round 1, item 109)
        |--------------------------------------------------------------------------
        |
        | Same no-literal-"admin/"-segment, no-"admin."-name-prefix shape as
        | workspace-plan-catalog.index above.
        |
        */
        Route::get('provider-events', 'PaymentProviderEventController@index')->name('provider-events.index');
        Route::post('provider-events/{event}/dispose', 'PaymentProviderEventController@dispose')->name('provider-events.dispose');

        Route::prefix('workspaces/{workspace}')->name('workspaces.')->whereUuid('workspace')->group(function () {
            Route::post('plan', 'WorkspaceEntitlementController@assignPlan')->name('plan.assign');
            Route::post('plan/change', 'WorkspaceEntitlementController@changePlan')->name('plan.change');
            Route::post('plan/status', 'WorkspaceEntitlementController@changeStatus')->name('plan.status');
            Route::post('plan/complimentary', 'WorkspaceEntitlementController@grantComplimentary')->name('plan.complimentary.grant');
            Route::delete('plan/complimentary', 'WorkspaceEntitlementController@revokeComplimentary')->name('plan.complimentary.revoke');
            Route::post('plan/additional-slots', 'WorkspaceEntitlementController@updateAdditionalSlots')->name('plan.additional-slots');
            Route::post('entitlement-overrides', 'WorkspaceEntitlementController@storeOverride')->name('entitlement-overrides.store');
            Route::delete('entitlement-overrides/{featureKey}', 'WorkspaceEntitlementController@revertOverride')->name('entitlement-overrides.revert');
        });
    });
