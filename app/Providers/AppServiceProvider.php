<?php

    namespace App\Providers;

    use App\Broadcasting\SMSChannel;
    use App\Library\HookManager;
    use App\Library\ViewAs\ViewAsManager;
    use App\Models\ViewAsSession;
    use App\Models\Admin;
    use App\Models\Customer;
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
    use App\Repositories\Contracts\WorkspaceMembershipBusinessRepository;
    use App\Repositories\Contracts\WorkspaceMembershipRepository;
    use App\Repositories\Contracts\WorkspaceRepository;
    use App\Repositories\Contracts\WorkspaceTransitionRepository;
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
    use App\Repositories\Eloquent\EloquentWorkspaceMembershipBusinessRepository;
    use App\Repositories\Eloquent\EloquentWorkspaceMembershipRepository;
    use App\Repositories\Eloquent\EloquentWorkspaceRepository;
    use App\Repositories\Eloquent\EloquentWorkspaceTransitionRepository;
    use Closure;
    use Exception;
    use Illuminate\Auth\Events\Logout;
    use Illuminate\Cache\NullStore;
    use Illuminate\Database\Eloquent\Builder;
    use Illuminate\Database\Eloquent\Relations\Relation;
    use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\Event;
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
            // Customer Experience Slice 3 §4.13 step 4 — the default
            // messaging-adapter binding, behind the adapter's own
            // fail-closed constructor check.
            //
            // This is deliberately a `bind()` rather than an entry in the
            // repository map below, because it must NOT be resolved eagerly
            // or shared: TelnyxMessagingAdapter's constructor throws
            // MessagingProviderNotConfiguredException when the platform kill
            // switch is off or a credential is absent, and that refusal has
            // to happen at the moment of use, on the caller's terms, not at
            // container-build time for every request in the application.
            //
            // Without this line `app(MessagingProviderAdapter::class)` cannot
            // resolve at all in production — the interface has no concrete
            // default — while every test that binds FakeMessagingAdapter
            // masks the gap. T-PROV's real-binding assertion is what caught
            // that.
            $this->app->bind(
                \App\Library\Messaging\Contracts\MessagingProviderAdapter::class,
                \App\Library\Messaging\TelnyxMessagingAdapter::class,
            );

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
                BusinessRepository::class       => EloquentBusinessRepository::class,
                BusinessLocationRepository::class => EloquentBusinessLocationRepository::class,
                BusinessServiceRepository::class  => EloquentBusinessServiceRepository::class,
                CustomerOnboardingRepository::class => EloquentCustomerOnboardingRepository::class,
                OpportunityRunRepository::class => EloquentOpportunityRunRepository::class,
                \App\Repositories\Contracts\OpportunityProducerDispatchRepository::class => \App\Repositories\Eloquent\EloquentOpportunityProducerDispatchRepository::class,
                OpportunityRepository::class => EloquentOpportunityRepository::class,
                OpportunityRunCandidateRepository::class => EloquentOpportunityRunCandidateRepository::class,
                OpportunityActionExecutionRepository::class => EloquentOpportunityActionExecutionRepository::class,
                OpportunityTransitionRepository::class => EloquentOpportunityTransitionRepository::class,
                WorkspaceRepository::class => EloquentWorkspaceRepository::class,
                WorkspaceMembershipRepository::class => EloquentWorkspaceMembershipRepository::class,
                WorkspaceMembershipBusinessRepository::class => EloquentWorkspaceMembershipBusinessRepository::class,
                WorkspaceTransitionRepository::class => EloquentWorkspaceTransitionRepository::class,
                \App\Repositories\Contracts\WorkspacePlanCatalogRepository::class => \App\Repositories\Eloquent\EloquentWorkspacePlanCatalogRepository::class,
                \App\Repositories\Contracts\WorkspacePlanCatalogPricingChangeRepository::class => \App\Repositories\Eloquent\EloquentWorkspacePlanCatalogPricingChangeRepository::class,
                \App\Repositories\Contracts\WorkspacePlanFeatureRepository::class => \App\Repositories\Eloquent\EloquentWorkspacePlanFeatureRepository::class,
                \App\Repositories\Contracts\WorkspacePlanAssignmentRepository::class => \App\Repositories\Eloquent\EloquentWorkspacePlanAssignmentRepository::class,
                \App\Repositories\Contracts\WorkspaceEntitlementOverrideRepository::class => \App\Repositories\Eloquent\EloquentWorkspaceEntitlementOverrideRepository::class,
                \App\Repositories\Contracts\BusinessFeatureToggleRepository::class => \App\Repositories\Eloquent\EloquentBusinessFeatureToggleRepository::class,
                \App\Repositories\Contracts\WorkspaceEntitlementTransitionRepository::class => \App\Repositories\Eloquent\EloquentWorkspaceEntitlementTransitionRepository::class,
                \App\Repositories\Contracts\BusinessUsageWalletRepository::class => \App\Repositories\Eloquent\EloquentBusinessUsageWalletRepository::class,
                // Customer Experience Slice 3 §4.8 — RFC-005's additive,
                // measurement-only repository; the sole writer of
                // business_usage_measurements.
                \App\Repositories\Contracts\BusinessUsageMeasurementRepository::class => \App\Repositories\Eloquent\EloquentBusinessUsageMeasurementRepository::class,
                \App\Repositories\Contracts\BusinessUsageRateRepository::class => \App\Repositories\Eloquent\EloquentBusinessUsageRateRepository::class,
                \App\Repositories\Contracts\BusinessUsageRateActivationRepository::class => \App\Repositories\Eloquent\EloquentBusinessUsageRateActivationRepository::class,
                \App\Repositories\Contracts\PlatformFeatureUsageClassificationRepository::class => \App\Repositories\Eloquent\EloquentPlatformFeatureUsageClassificationRepository::class,
                \App\Repositories\Contracts\PlatformFeatureUsageClassificationTransitionRepository::class => \App\Repositories\Eloquent\EloquentPlatformFeatureUsageClassificationTransitionRepository::class,
                \App\Repositories\Contracts\UsageMeterRepository::class => \App\Repositories\Eloquent\EloquentUsageMeterRepository::class,
                \App\Repositories\Contracts\UsageMeterTransitionRepository::class => \App\Repositories\Eloquent\EloquentUsageMeterTransitionRepository::class,
                \App\Repositories\Contracts\BusinessUsageReservationRepository::class => \App\Repositories\Eloquent\EloquentBusinessUsageReservationRepository::class,
                \App\Repositories\Contracts\BusinessUsageLedgerEntryRepository::class => \App\Repositories\Eloquent\EloquentBusinessUsageLedgerEntryRepository::class,
                \App\Library\Entitlement\Contracts\UsageAuthorizationGateway::class => \App\Library\Entitlement\RealUsageAuthorizationGateway::class,
                \App\Repositories\Contracts\BusinessFeatureUsageLimitRepository::class => \App\Repositories\Eloquent\EloquentBusinessFeatureUsageLimitRepository::class,
                \App\Repositories\Contracts\PlatformFeatureUsageSafetyLimitRepository::class => \App\Repositories\Eloquent\EloquentPlatformFeatureUsageSafetyLimitRepository::class,
                \App\Repositories\Contracts\BusinessUsageLimitTransitionRepository::class => \App\Repositories\Eloquent\EloquentBusinessUsageLimitTransitionRepository::class,
                \App\Repositories\Contracts\BusinessUsageWalletBillingStatusTransitionRepository::class => \App\Repositories\Eloquent\EloquentBusinessUsageWalletBillingStatusTransitionRepository::class,
                \App\Repositories\Contracts\BusinessBillingContactRepository::class => \App\Repositories\Eloquent\EloquentBusinessBillingContactRepository::class,
                \App\Repositories\Contracts\BusinessPayerAssignmentRepository::class => \App\Repositories\Eloquent\EloquentBusinessPayerAssignmentRepository::class,
                \App\Repositories\Contracts\BusinessPayerTransitionRepository::class => \App\Repositories\Eloquent\EloquentBusinessPayerTransitionRepository::class,
                \App\Repositories\Contracts\PaymentProviderCustomerRepository::class => \App\Repositories\Eloquent\EloquentPaymentProviderCustomerRepository::class,
                \App\Repositories\Contracts\BusinessPaymentInstrumentRepository::class => \App\Repositories\Eloquent\EloquentBusinessPaymentInstrumentRepository::class,
                \App\Repositories\Contracts\BusinessFundingAttemptRepository::class => \App\Repositories\Eloquent\EloquentBusinessFundingAttemptRepository::class,
                \App\Repositories\Contracts\BusinessFundingAttemptTransitionRepository::class => \App\Repositories\Eloquent\EloquentBusinessFundingAttemptTransitionRepository::class,
                \App\Repositories\Contracts\PaymentProviderEventRepository::class => \App\Repositories\Eloquent\EloquentPaymentProviderEventRepository::class,
                \App\Library\Usage\Contracts\PaymentProviderGateway::class => \App\Library\Usage\StripePaymentProviderGateway::class,
                \App\Repositories\Contracts\AdditionalBusinessSlotAgreementRepository::class => \App\Repositories\Eloquent\EloquentAdditionalBusinessSlotAgreementRepository::class,
                \App\Repositories\Contracts\AdditionalBusinessSlotAgreementTransitionRepository::class => \App\Repositories\Eloquent\EloquentAdditionalBusinessSlotAgreementTransitionRepository::class,
                \App\Repositories\Contracts\AdditionalBusinessSlotRenewalChargeRepository::class => \App\Repositories\Eloquent\EloquentAdditionalBusinessSlotRenewalChargeRepository::class,
                \App\Repositories\Contracts\AdditionalBusinessSlotRenewalChargeTransitionRepository::class => \App\Repositories\Eloquent\EloquentAdditionalBusinessSlotRenewalChargeTransitionRepository::class,
                \App\Repositories\Contracts\BusinessUsageAddonCatalogRepository::class => \App\Repositories\Eloquent\EloquentBusinessUsageAddonCatalogRepository::class,
                \App\Repositories\Contracts\BusinessUsageAddonPurchaseRepository::class => \App\Repositories\Eloquent\EloquentBusinessUsageAddonPurchaseRepository::class,
                \App\Repositories\Contracts\BusinessUsageAddonPurchaseTransitionRepository::class => \App\Repositories\Eloquent\EloquentBusinessUsageAddonPurchaseTransitionRepository::class,
                \App\Repositories\Contracts\BusinessBillingReceiptRepository::class => \App\Repositories\Eloquent\EloquentBusinessBillingReceiptRepository::class,
                \App\Repositories\Contracts\PlatformThemePresetRepository::class => \App\Repositories\Eloquent\EloquentPlatformThemePresetRepository::class,
                \App\Repositories\Contracts\PlatformThemeFontRepository::class => \App\Repositories\Eloquent\EloquentPlatformThemeFontRepository::class,
                \App\Library\AgencyProspecting\Contracts\AgencyProspectingAiClient::class => \App\Library\AgencyProspecting\OpenAiAgencyProspectingClient::class,
                \App\Library\AgencyProspecting\Contracts\AgencyProspectingMessageSender::class => \App\Library\AgencyProspecting\ProviderAgencyProspectingMessageSender::class,
                // Google Business Profile Slice A (contract §30.14). The
                // provider seam is bound to the READ-ONLY HTTP client;
                // tests swap FakeGoogleBusinessProfileReadClient in via
                // app()->instance(), exactly as the Usage and
                // AgencyProspecting suites do.
                \App\Library\GoogleBusinessProfile\Contracts\GoogleBusinessProfileReadClient::class => \App\Library\GoogleBusinessProfile\HttpGoogleBusinessProfileReadClient::class,
                \App\Repositories\Contracts\BusinessGoogleConnectionRepository::class => \App\Repositories\Eloquent\EloquentBusinessGoogleConnectionRepository::class,
                \App\Repositories\Contracts\BusinessGoogleLocationRepository::class => \App\Repositories\Eloquent\EloquentBusinessGoogleLocationRepository::class,
                \App\Repositories\Contracts\BusinessGoogleOperationRepository::class => \App\Repositories\Eloquent\EloquentBusinessGoogleOperationRepository::class,

                // Automations V2 runtime core. V2-0 declared these interfaces so
                // the runtime, trigger, action and HTTP lanes could be built in
                // parallel; this is where the runtime lane supplies them.
                \App\Library\Automation\Workflow\Contracts\EnrollmentService::class => \App\Library\Automation\Workflow\Runtime\WorkflowEnrollmentService::class,
                \App\Library\Automation\Workflow\Contracts\WorkflowLifecycle::class => \App\Library\Automation\Workflow\Runtime\WorkflowLifecycleService::class,
            ];

            foreach ($bindings as $interface => $implementation) {
                $this->app->bind($interface, $implementation);
            }

            // Automations V2 §5.2 — the step-executor registry MUST be a
            // singleton: each slice registers its own node types into it, and a
            // per-resolution binding would hand the advancer an empty registry
            // that silently refuses to run every step.
            //
            // This slice owns the two structural executors. The wait and If/Else
            // executors arrive with their slice, and the action executors with
            // theirs, each adding one register() call here and nothing else.
            $this->app->singleton(
                \App\Library\Automation\Workflow\Runtime\NodeExecutorRegistry::class,
                function ($app): \App\Library\Automation\Workflow\Runtime\NodeExecutorRegistry {
                    $registry = new \App\Library\Automation\Workflow\Runtime\NodeExecutorRegistry();

                    $registry->register($app->make(\App\Library\Automation\Workflow\Executors\TriggerNodeExecutor::class));
                    $registry->register($app->make(\App\Library\Automation\Workflow\Executors\EndNodeExecutor::class));

                    return $registry;
                },
            );

            // Google Business Profile Slice A (correction pass item 6).
            // The call budget MUST be a singleton: withinOperation() sets
            // the reservation context on it, and the provider client — a
            // separately resolved object — calls reserve() on the same
            // instance. A per-resolution binding would give the client a
            // context-free copy and every reservation would fail closed.
            $this->app->singleton(\App\Library\GoogleBusinessProfile\GoogleBusinessProfileCallBudget::class);

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

            // Custom notification channel
            Notification::extend('sms', fn() => new SMSChannel());

            // Customer Experience Slice 1B (contract §5.5 "Exit"): logging out
            // ends any open View-as-client session and audits the end.
            Event::listen(Logout::class, function (Logout $event): void {
                if ($event->user !== null && isset($event->user->id)) {
                    $this->app->make(ViewAsManager::class)
                        ->endAllForActor((int) $event->user->id, ViewAsSession::END_REASON_LOGOUT);
                }
            });

            // Allow some routes during maintenance
            PreventRequestsDuringMaintenance::except([
                'maintenance/notify',
                'maintenance/notify/*',
            ]);
        }

    }
