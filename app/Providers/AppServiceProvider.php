<?php

    namespace App\Providers;

    use App\Broadcasting\SMSChannel;
    use App\Library\HookManager;
    use App\Models\Admin;
    use App\Models\Customer;
    use App\Models\Plugins;
    use App\Models\User;
    use App\Repositories\Contracts\AccountRepository;
    use App\Repositories\Contracts\AnnouncementsRepository;
    use App\Repositories\Contracts\AutomationsRepository;
    use App\Repositories\Contracts\BlacklistsRepository;
    use App\Repositories\Contracts\BlockSenderIDdRepository;
    use App\Repositories\Contracts\BusinessLocationRepository;
    use App\Repositories\Contracts\BusinessRepository;
    use App\Repositories\Contracts\BusinessServiceRepository;
    use App\Repositories\Contracts\CampaignRepository;
    use App\Repositories\Contracts\ContactsRepository;
    use App\Repositories\Contracts\CountriesRepository;
    use App\Repositories\Contracts\CurrencyRepository;
    use App\Repositories\Contracts\CustomerOnboardingRepository;
    use App\Repositories\Contracts\CustomerRepository;
    use App\Repositories\Contracts\KeywordRepository;
    use App\Repositories\Contracts\LanguageRepository;
    use App\Repositories\Contracts\OpportunityActionExecutionRepository;
    use App\Repositories\Contracts\OpportunityRepository;
    use App\Repositories\Contracts\OpportunityRunCandidateRepository;
    use App\Repositories\Contracts\OpportunityRunRepository;
    use App\Repositories\Contracts\OpportunityTransitionRepository;
    use App\Repositories\Contracts\PhoneNumberRepository;
    use App\Repositories\Contracts\PlanRepository;
    use App\Repositories\Contracts\PluginsRepository;
    use App\Repositories\Contracts\RoleRepository;
    use App\Repositories\Contracts\SenderIDRepository;
    use App\Repositories\Contracts\SendingServerRepository;
    use App\Repositories\Contracts\SettingsRepository;
    use App\Repositories\Contracts\SubAccountRepository;
    use App\Repositories\Contracts\TemplatesRepository;
    use App\Repositories\Contracts\SpamWordRepository;
    use App\Repositories\Contracts\SubscriptionRepository;
    use App\Repositories\Contracts\TemplateTagsRepository;
    use App\Repositories\Contracts\UserRepository;
    use App\Repositories\Eloquent\EloquentAccountRepository;
    use App\Repositories\Eloquent\EloquentAnnouncementsRepository;
    use App\Repositories\Eloquent\EloquentAutomationsRepository;
    use App\Repositories\Eloquent\EloquentBlacklistsRepository;
    use App\Repositories\Eloquent\EloquentBlockSenderIDRepository;
    use App\Repositories\Eloquent\EloquentBusinessLocationRepository;
    use App\Repositories\Eloquent\EloquentBusinessRepository;
    use App\Repositories\Eloquent\EloquentBusinessServiceRepository;
    use App\Repositories\Eloquent\EloquentCampaignRepository;
    use App\Repositories\Eloquent\EloquentContactsRepository;
    use App\Repositories\Eloquent\EloquentCountriesRepository;
    use App\Repositories\Eloquent\EloquentCurrencyRepository;
    use App\Repositories\Eloquent\EloquentCustomerOnboardingRepository;
    use App\Repositories\Eloquent\EloquentCustomerRepository;
    use App\Repositories\Eloquent\EloquentKeywordRepository;
    use App\Repositories\Eloquent\EloquentLanguageRepository;
    use App\Repositories\Eloquent\EloquentOpportunityActionExecutionRepository;
    use App\Repositories\Eloquent\EloquentOpportunityRepository;
    use App\Repositories\Eloquent\EloquentOpportunityRunCandidateRepository;
    use App\Repositories\Eloquent\EloquentOpportunityRunRepository;
    use App\Repositories\Eloquent\EloquentOpportunityTransitionRepository;
    use App\Repositories\Eloquent\EloquentPhoneNumberRepository;
    use App\Repositories\Eloquent\EloquentPlanRepository;
    use App\Repositories\Eloquent\EloquentPluginsRepository;
    use App\Repositories\Eloquent\EloquentRoleRepository;
    use App\Repositories\Eloquent\EloquentSenderIDRepository;
    use App\Repositories\Eloquent\EloquentSendingServerRepository;
    use App\Repositories\Eloquent\EloquentSettingsRepository;
    use App\Repositories\Eloquent\EloquentSubAccountRepository;
    use App\Repositories\Eloquent\EloquentTemplatesRepository;
    use App\Repositories\Eloquent\EloquentSpamWordRepository;
    use App\Repositories\Eloquent\EloquentSubscriptionRepository;
    use App\Repositories\Eloquent\EloquentTemplateTagsRepository;
    use App\Repositories\Eloquent\EloquentUserRepository;
    use Closure;
    use Exception;
    use Illuminate\Cache\NullStore;
    use Illuminate\Database\Eloquent\Builder;
    use Illuminate\Database\Eloquent\Relations\Relation;
    use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\Notification;
    use Illuminate\Support\Facades\URL;
    use Illuminate\Support\ServiceProvider;
    use Illuminate\Support\Facades\Schema;
    use Throwable;


    /**
     * @method where(Closure $param)
     */
    class AppServiceProvider extends ServiceProvider
    {
        /**
         * Register any application services.
         *
         * @return void
         */
        public function register()
        {
            $bindings = [
                UserRepository::class           => EloquentUserRepository::class,
                AccountRepository::class        => EloquentAccountRepository::class,
                RoleRepository::class           => EloquentRoleRepository::class,
                CustomerRepository::class       => EloquentCustomerRepository::class,
                CurrencyRepository::class       => EloquentCurrencyRepository::class,
                SendingServerRepository::class  => EloquentSendingServerRepository::class,
                PlanRepository::class           => EloquentPlanRepository::class,
                KeywordRepository::class        => EloquentKeywordRepository::class,
                SenderIDRepository::class       => EloquentSenderIDRepository::class,
                SettingsRepository::class       => EloquentSettingsRepository::class,
                LanguageRepository::class       => EloquentLanguageRepository::class,
                SubscriptionRepository::class   => EloquentSubscriptionRepository::class,
                PhoneNumberRepository::class    => EloquentPhoneNumberRepository::class,
                TemplateTagsRepository::class   => EloquentTemplateTagsRepository::class,
                BlacklistsRepository::class     => EloquentBlacklistsRepository::class,
                SpamWordRepository::class       => EloquentSpamWordRepository::class,
                ContactsRepository::class       => EloquentContactsRepository::class,
                TemplatesRepository::class      => EloquentTemplatesRepository::class,
                CampaignRepository::class       => EloquentCampaignRepository::class,
                CountriesRepository::class      => EloquentCountriesRepository::class,
                AutomationsRepository::class    => EloquentAutomationsRepository::class,
                AnnouncementsRepository::class  => EloquentAnnouncementsRepository::class,
                BlockSenderIDdRepository::class => EloquentBlockSenderIDRepository::class,
                SubAccountRepository::class     => EloquentSubAccountRepository::class,
                PluginsRepository::class        => EloquentPluginsRepository::class,
                BusinessRepository::class       => EloquentBusinessRepository::class,
                BusinessLocationRepository::class => EloquentBusinessLocationRepository::class,
                BusinessServiceRepository::class  => EloquentBusinessServiceRepository::class,
                CustomerOnboardingRepository::class => EloquentCustomerOnboardingRepository::class,
                OpportunityRunRepository::class => EloquentOpportunityRunRepository::class,
                OpportunityRepository::class => EloquentOpportunityRepository::class,
                OpportunityRunCandidateRepository::class => EloquentOpportunityRunCandidateRepository::class,
                OpportunityActionExecutionRepository::class => EloquentOpportunityActionExecutionRepository::class,
                OpportunityTransitionRepository::class => EloquentOpportunityTransitionRepository::class,
            ];

            foreach ($bindings as $interface => $implementation) {
                $this->app->bind($interface, $implementation);
            }

            $this->app->singleton(HookManager::class, fn() => new HookManager());
        }

        /**
         * Bootstrap services.
         * @throws Exception
         */
        public function boot()
        {
            Schema::defaultStringLength(191);

            // Force HTTPS if enabled
            if (config('app.url_force_https') === true) {
                URL::forceScheme('https');
            }

            // Polymorphic relations
            Relation::morphMap([
                'user'     => User::class,
                'customer' => Customer::class,
                'admin'    => Admin::class,
            ]);

            // Null cache driver
            Cache::extend('none', fn() => Cache::repository(new NullStore()));

            // Where like macro
            Builder::macro('whereLike', function ($attributes, string $searchTerm) {
                $this->where(function (Builder $query) use ($attributes, $searchTerm) {
                    foreach (array_wrap($attributes) as $attribute) {
                        $query->orWhere(function ($q) use ($attribute, $searchTerm) {
                            if (str_contains($attribute, '.')) {
                                [$rel, $col] = explode('.', $attribute);
                                $q->whereHas($rel, fn($r) => $r->where($col, 'LIKE', "%{$searchTerm}%"));
                            } else {
                                $q->where($attribute, 'LIKE', "%{$searchTerm}%");
                            }
                        });
                    }
                });

                return $this;
            });

            /**
             * Load plugins safely (prevents broken plugin from killing app)
             */
            try {
                Plugins::autoloadWithoutDbQuery();
            } catch (Throwable $e) {
                logger()->error('Plugin system boot failure: ' . $e->getMessage());
            }

            // Custom notification channel
            Notification::extend('sms', fn() => new SMSChannel());

            // Allow some routes during maintenance
            PreventRequestsDuringMaintenance::except([
                'maintenance/notify',
                'maintenance/notify/*',
            ]);
        }

    }
